<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\MergedFingerprint;
use App\Entity\Operation;
use App\Import\LabelNormalizer;
use App\Import\OperationFingerprinter;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Recalcule `fingerprint` et `occurrenceIndex` de toutes les opérations à partir de `rawLabel`
 * (rang d'occurrence = rang par id croissant au sein de (compte, date, montant, clé)), à lancer
 * après un changement de normalisation (ex. clé passée de 40 à 24 caractères, voir
 * docs/adr/0005-dedoublonnage.md). `--renormalize` recalcule aussi `normalizedLabel` et `merchant`.
 *
 * Mise à jour en deux temps dans une transaction (d'abord `fingerprint = 'tmp-' || id` en SQL,
 * puis les valeurs finales) pour ne jamais heurter la contrainte unique. Idempotente.
 */
#[AsCommand(name: 'app:operations:rebuild-fingerprints', description: 'Recalcule les empreintes des opérations depuis leurs libellés bruts')]
final class RebuildFingerprintsCommand extends Command
{
    public function __construct(
        private readonly ManagerRegistry $registry,
        private readonly LabelNormalizer $labelNormalizer,
        private readonly OperationFingerprinter $fingerprinter,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('renormalize', null, InputOption::VALUE_NONE, 'Recalcule aussi normalizedLabel et merchant depuis rawLabel')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Affiche le nombre de modifications sans rien écrire')
            ->setHelp(<<<'HELP'
                Après un changement de normalisation des libellés (ex. clé 40 → 24 caractères) :
                  <info>app:operations:rebuild-fingerprints --dry-run</info>
                  <info>app:operations:rebuild-fingerprints --renormalize</info>
                HELP,
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $renormalize = (bool) $input->getOption('renormalize');
        $dryRun = (bool) $input->getOption('dry-run');

        $entityManager = $this->registry->getManager();
        \assert($entityManager instanceof EntityManagerInterface);

        // Les saisies manuelles en attente (awaiting_statement) sont ignorées : leur empreinte
        // « manual:… » aléatoire est conservée telle quelle (jamais retrouvée par l'import exact).
        /** @var list<Operation> $operations triées par id croissant */
        $operations = $entityManager->getRepository(Operation::class)->findBy(['awaitingStatement' => false], ['id' => 'ASC']);

        // Nouvelles valeurs : rang d'occurrence par id croissant au sein de (compte, date, montant, clé).
        $counters = [];
        $modified = 0;
        /** @var array<int, array{fingerprint: string, occurrenceIndex: int, normalizedLabel: ?string, merchant: ?string}> $newValues */
        $newValues = [];
        foreach ($operations as $operation) {
            $account = $operation->getAccount();
            $key = $this->labelNormalizer->key($operation->getRawLabel());
            $group = $account->getId().'|'.$operation->getDate()->format('Y-m-d').'|'.$operation->getAmount().'|'.$key;
            $rank = $counters[$group] ?? 0;
            $counters[$group] = $rank + 1;
            $fingerprint = $this->fingerprinter->fingerprint($account, $operation->getDate(), $operation->getAmount(), $key, $rank);
            $normalizedLabel = null;
            $merchant = null;
            if ($renormalize) {
                $normalized = $this->labelNormalizer->normalize($operation->getRawLabel());
                $normalizedLabel = $normalized->normalized;
                $merchant = $normalized->merchant;
            }
            $id = (int) $operation->getId();
            $newValues[$id] = [
                'fingerprint' => $fingerprint,
                'occurrenceIndex' => $rank,
                'normalizedLabel' => $normalizedLabel,
                'merchant' => $merchant,
            ];
            if ($fingerprint !== $operation->getFingerprint()
                || $rank !== $operation->getOccurrenceIndex()
                || ($renormalize && ($normalizedLabel !== $operation->getNormalizedLabel() || $merchant !== $operation->getMerchant()))
            ) {
                $modified++;
            }
        }

        // Pierres tombales des doublons fusionnés : même recalcul, rang d'origine conservé
        // (l'opération supprimée n'existe plus ; le compte est celui de l'opération conservée).
        /** @var array<int, string> $tombstoneValues id => nouvelle empreinte */
        $tombstoneValues = [];
        $seen = [];
        foreach ($entityManager->getRepository(MergedFingerprint::class)->findBy([], ['id' => 'ASC']) as $tombstone) {
            $fingerprint = $this->fingerprinter->fingerprint(
                $tombstone->getKeptOperation()->getAccount(),
                $tombstone->getDate(),
                $tombstone->getAmount(),
                $this->labelNormalizer->key($tombstone->getRawLabel()),
                $tombstone->getOccurrenceIndex(),
            );
            // Deux pierres tombales devenues identiques : une seule suffit (empreinte unique).
            $tombstoneValues[(int) $tombstone->getId()] = isset($seen[$fingerprint]) ? '' : $fingerprint;
            $seen[$fingerprint] = true;
        }

        $io->writeln(sprintf(
            '%d opération(s) analysée(s), %d modifiée(s)%s.',
            count($operations),
            $modified,
            $dryRun ? ' (dry-run, rien n’est écrit)' : '',
        ));

        if ($dryRun || [] === $newValues) {
            return Command::SUCCESS;
        }

        $connection = $entityManager->getConnection();
        $connection->beginTransaction();
        try {
            // Phase 1 : empreintes temporaires uniques, pour ne jamais heurter la contrainte unique
            // (les saisies en attente gardent leur empreinte « manual:… »).
            $connection->executeStatement("UPDATE operation SET fingerprint = 'tmp-' || id WHERE NOT awaiting_statement");
            // Phase 2 : valeurs finales.
            foreach ($newValues as $id => $values) {
                if ($renormalize) {
                    $connection->executeStatement(
                        'UPDATE operation SET fingerprint = ?, occurrence_index = ?, normalized_label = ?, merchant = ? WHERE id = ?',
                        [$values['fingerprint'], $values['occurrenceIndex'], $values['normalizedLabel'], $values['merchant'], $id],
                    );
                } else {
                    $connection->executeStatement(
                        'UPDATE operation SET fingerprint = ?, occurrence_index = ? WHERE id = ?',
                        [$values['fingerprint'], $values['occurrenceIndex'], $id],
                    );
                }
            }
            if ([] !== $tombstoneValues) {
                $connection->executeStatement("UPDATE merged_fingerprint SET fingerprint = 'tmp-' || id");
                foreach ($tombstoneValues as $id => $fingerprint) {
                    '' === $fingerprint
                        ? $connection->executeStatement('DELETE FROM merged_fingerprint WHERE id = ?', [$id])
                        : $connection->executeStatement('UPDATE merged_fingerprint SET fingerprint = ? WHERE id = ?', [$fingerprint, $id]);
                }
            }
            $connection->commit();
        } catch (\Throwable $exception) {
            $connection->rollBack();
            throw $exception;
        }
        $entityManager->clear();

        $io->success(sprintf('%d opération(s) modifiée(s).', $modified));

        return Command::SUCCESS;
    }
}
