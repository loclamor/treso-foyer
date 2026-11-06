<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Household;
use App\Reconciliation\ManualEntryReconciler;
use App\Repository\HouseholdRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Relance manuelle du rapprochement automatique des saisies de dépenses en attente avec
 * les lignes de relevé (montant exact, fenêtre −2/+10 jours, paire univoque, voir ADR 0014).
 * Lancé automatiquement après chaque import ; n'affiche que des compteurs.
 */
#[AsCommand(name: 'app:entries:reconcile', description: 'Rapproche les saisies manuelles en attente des lignes de relevé')]
final class EntriesReconcileCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ManualEntryReconciler $reconciler,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Calcule sans rien écrire en base');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var HouseholdRepository $householdRepository */
        $householdRepository = $this->entityManager->getRepository(Household::class);
        $household = $householdRepository->findOneBy([]);
        if (!$household instanceof Household) {
            $io->error('Aucun foyer : lancez d’abord app:setup.');

            return Command::FAILURE;
        }

        $report = $this->reconciler->reconcile($household, dryRun: (bool) $input->getOption('dry-run'));

        $io->writeln(sprintf(
            'Saisies rapprochées : %d | ambiguës (plusieurs candidats) : %d | sans candidat : %d.',
            $report->matched,
            $report->ambiguous,
            $report->pending,
        ));
        if ($input->getOption('dry-run')) {
            $io->note('Dry-run : rien n’a été écrit.');
        }

        return Command::SUCCESS;
    }
}
