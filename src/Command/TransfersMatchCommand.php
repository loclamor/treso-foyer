<?php

declare(strict_types=1);

namespace App\Command;

use App\Categorization\InternalTransferMatcher;
use App\Entity\Household;
use App\Repository\HouseholdRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Apparie les virements internes entre comptes du foyer (montants opposés, ± jours,
 * voir docs/adr/0009-multi-comptes-foyer.md). N'affiche que des compteurs.
 */
#[AsCommand(name: 'app:transfers:match', description: 'Apparie les virements internes entre comptes du foyer')]
final class TransfersMatchCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly InternalTransferMatcher $matcher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Calcule sans rien écrire en base')
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Fenêtre de dates en jours autour de la contrepartie', '3');
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

        $report = $this->matcher->match(
            $household,
            dryRun: (bool) $input->getOption('dry-run'),
            days: (int) $input->getOption('days'),
        );

        $io->writeln(sprintf('Virements internes appariés : %d | ambigus (plusieurs candidats) : %d.', $report->matched, $report->ambiguous));
        if ($input->getOption('dry-run')) {
            $io->note('Dry-run : rien n’a été écrit.');
        }

        return Command::SUCCESS;
    }
}
