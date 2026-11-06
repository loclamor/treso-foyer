<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Household;
use App\Import\Exception\NotAnAccountStatementException;
use App\Import\Exception\UnsupportedFormatException;
use App\Import\Model\ImportOptions;
use App\Import\Model\ImportReport;
use App\Import\PostImportProcessor;
use App\Import\StatementImporter;
use App\Repository\HouseholdRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Importe des relevés (CSV/PDF) : `app:import <fichiers ou dossiers>...`.
 * Dossiers parcourus récursivement (fichiers .csv et .pdf, triés par nom).
 * N'affiche jamais de libellé d'opération ni de numéro de compte en clair.
 */
#[AsCommand(name: 'app:import', description: 'Importe des relevés bancaires (CSV/PDF) sans doublons')]
final class ImportCommand extends Command
{
    public function __construct(
        private readonly StatementImporter $importer,
        private readonly EntityManagerInterface $entityManager,
        private readonly PostImportProcessor $postImportProcessor,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('paths', InputArgument::IS_ARRAY | InputArgument::REQUIRED, 'Fichiers ou dossiers de relevés (.csv, .pdf)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Calcule sans rien écrire en base')
            ->addOption('auto-create-account', null, InputOption::VALUE_NONE, 'Crée le compte s’il est inconnu (alias lu dans data/comptes.ini)')
            ->addOption('threshold', null, InputOption::VALUE_REQUIRED, 'Seuil de similarité pg_trgm pour les doublons probables', '0.45')
            ->addOption('no-post-process', null, InputOption::VALUE_NONE, 'Ne pas enchaîner l\'appariement des virements internes ni la catégorisation automatique')
            ->setHelp(<<<'HELP'
                Exemples :
                  <info>app:import data/releves/mon-compte/csv --dry-run</info>
                  <info>app:import data/inbox --auto-create-account</info>
                HELP,
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $files = $this->collectFiles($input->getArgument('paths'));
        if ([] === $files) {
            $io->warning('Aucun fichier .csv ou .pdf trouvé.');

            return Command::SUCCESS;
        }

        $options = new ImportOptions(
            dryRun: (bool) $input->getOption('dry-run'),
            autoCreateAccount: (bool) $input->getOption('auto-create-account'),
            similarityThreshold: (float) $input->getOption('threshold'),
        );

        /** @var list<array{0: string, 1: string, 2: string, 3: int, 4: int, 5: int, 6: int, 7: string}> $rows */
        $rows = [];
        $errors = [];
        $totalAdded = 0;
        $totalSkipped = 0;
        $totalProbable = 0;
        $totalEnriched = 0;
        $failures = 0;

        foreach ($files as $file) {
            $basename = basename($file);
            try {
                $reports = $this->importer->importAll($file, $options);
            } catch (NotAnAccountStatementException) {
                // Relevé annuel de frais ou document sans opérations : ignoré, pas un échec.
                $rows[] = [$basename, '-', '-', 0, 0, 0, 0, 'ignoré (pas un relevé d\'opérations)'];
                continue;
            } catch (UnsupportedFormatException) {
                $failures++;
                $rows[] = [$basename, '-', '-', 0, 0, 0, 0, 'format non pris en charge'];
                $errors[] = sprintf('%s : format non pris en charge', $basename);
                continue;
            } catch (\Throwable $exception) {
                // Un fichier en erreur n'arrête pas les suivants.
                $failures++;
                $rows[] = [$basename, '-', '-', 0, 0, 0, 0, 'échoué'];
                $errors[] = sprintf('%s : %s', $basename, $exception->getMessage());
                continue;
            }
            // Une ligne par compte du fichier (compte principal, puis livrets éventuels).
            foreach ($reports as $report) {
                if ('failed' === $report->status) {
                    $failures++;
                    $rows[] = [$basename, '-', '-', 0, 0, 0, 0, 'échoué (compte lié)'];
                    $errors[] = sprintf('%s (compte lié) : %s', $basename, $report->error);
                    continue;
                }
                $rows[] = [
                    $basename,
                    trim(($report->accountAlias ?? '?').' '.$report->accountMasked),
                    $this->formatPeriod($report),
                    $report->added,
                    $report->skipped,
                    $report->enriched,
                    $report->probableDuplicates,
                    $report->status,
                ];
                if ($report->isAlreadyImported()) {
                    // Compteurs de l'import d'origine : affichés sur la ligne, jamais cumulés.
                    continue;
                }
                $totalAdded += $report->added;
                $totalSkipped += $report->skipped;
                $totalProbable += $report->probableDuplicates;
                $totalEnriched += $report->enriched;
            }
        }

        $io->table(
            ['Fichier', 'Compte', 'Période', 'Ajoutées', 'Déjà connues', 'Enrichies', 'Doublons probables', 'Statut'],
            $rows,
        );
        $io->writeln(sprintf(
            'Totaux : %d fichier(s), %d ajoutée(s), %d déjà connue(s), %d enrichie(s), %d doublon(s) probable(s).',
            count($files),
            $totalAdded,
            $totalSkipped,
            $totalEnriched,
            $totalProbable,
        ));
        foreach ($errors as $error) {
            $io->writeln('<error>'.$error.'</error>');
        }

        // Post-traitement : appariement des virements internes puis catégorisation automatique,
        // uniquement si quelque chose a été ajouté ou enrichi (hors dry-run).
        if (!$options->dryRun && !$input->getOption('no-post-process') && ($totalAdded + $totalEnriched) > 0) {
            $this->postProcess($io);
        }

        return $failures > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Après import : appariement des virements internes puis catégorisation automatique,
     * uniquement si quelque chose a été ajouté ou enrichi (hors dry-run).
     * N'affiche que des compteurs.
     */
    private function postProcess(SymfonyStyle $io): void
    {
        try {
            /** @var HouseholdRepository $householdRepository */
            $householdRepository = $this->entityManager->getRepository(Household::class);
            $household = $householdRepository->findOneBy([]);
            if (!$household instanceof Household) {
                return;
            }

            $result = $this->postImportProcessor->process($household);
            $io->writeln(sprintf(
                'Post-traitement — virements internes appariés : %d, ambigus : %d.',
                $result->transfersMatched,
                $result->transfersAmbiguous,
            ));
            $report = $result->categorization;
            $io->writeln(sprintf(
                'Post-traitement — catégorisation : %d traitée(s), %d par règle, %d par similarité, %d non catégorisée(s).',
                $report->processed,
                $report->byRule,
                $report->bySimilarity,
                $report->stillUncategorized,
            ));
            $vehicles = $result->vehicles;
            $io->writeln(sprintf(
                'Post-traitement — véhicules : %d par règle, %d auto, %d retirée(s).',
                $vehicles->byRule,
                $vehicles->auto,
                $vehicles->cleared,
            ));
            $recurrences = $result->recurrences;
            $io->writeln(sprintf(
                'Post-traitement — récurrences : %d créée(s), %d mise(s) à jour, %d terminée(s), %d retirée(s), %d opération(s) liée(s).',
                $recurrences->created,
                $recurrences->updated,
                $recurrences->ended,
                $recurrences->removed,
                $recurrences->linked,
            ));
        } catch (\Throwable $exception) {
            // Un échec du post-traitement ne fait pas échouer l'import déjà réussi.
            $io->warning(sprintf('Post-traitement ignoré : %s', $exception->getMessage()));
        }
    }

    /**
     * Aplati les chemins (fichiers ou dossiers parcourus récursivement, .csv/.pdf),
     * triés par nom, sans doublons.
     *
     * @param list<string> $paths
     * @return list<string>
     */
    private function collectFiles(array $paths): array
    {
        $files = [];
        foreach ($paths as $path) {
            $path = (string) $path;
            if (is_file($path)) {
                $files[] = $path;
                continue;
            }
            if (!is_dir($path)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                    continue;
                }
                $extension = mb_strtolower($file->getExtension());
                if (in_array($extension, ['csv', 'pdf'], true)) {
                    $files[] = $file->getPathname();
                }
            }
        }
        $files = array_values(array_unique($files));
        sort($files, SORT_STRING);

        return $files;
    }

    private function formatPeriod(ImportReport $report): string
    {
        if (null === $report->periodStart || null === $report->periodEnd) {
            return '-';
        }

        return $report->periodStart->format('d/m/Y').' → '.$report->periodEnd->format('d/m/Y');
    }
}
