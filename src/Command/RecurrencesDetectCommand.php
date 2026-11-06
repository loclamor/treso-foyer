<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Household;
use App\Recurrence\RecurrenceDetector;
use App\Repository\HouseholdRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Détecte les dépenses et revenus récurrents du foyer (voir docs/adr/0012-recurrences.md),
 * met à jour les séries et relie les opérations. Idempotent. N'affiche que des compteurs.
 */
#[AsCommand(name: 'app:recurrences:detect', description: 'Détecte les dépenses et revenus récurrents du foyer (idempotent)')]
final class RecurrencesDetectCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RecurrenceDetector $recurrenceDetector,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Calcule sans rien écrire en base');
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

        $report = $this->recurrenceDetector->sync($household, $this->clock->now(), dryRun: (bool) $input->getOption('dry-run'));

        $io->writeln(sprintf(
            'Récurrences : %d créée(s), %d mise(s) à jour, %d terminée(s), %d retirée(s), %d opération(s) liée(s).',
            $report->created,
            $report->updated,
            $report->ended,
            $report->removed,
            $report->linked,
        ));
        if ($input->getOption('dry-run')) {
            $io->note('Dry-run : rien n’a été écrit.');
        }

        return Command::SUCCESS;
    }
}
