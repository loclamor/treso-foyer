<?php

declare(strict_types=1);

namespace App\Command;

use App\Categorization\CategorizeOptions;
use App\Categorization\Categorizer;
use App\Entity\Household;
use App\Entity\Operation;
use App\Repository\HouseholdRepository;
use App\Repository\OperationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Catégorise automatiquement les opérations du foyer (règles puis similarité, voir
 * docs/adr/0006-categorisation.md). Par défaut seules les opérations non catégorisées ;
 * --all re-catégorise toutes les opérations non manuelles.
 * N'affiche que des compteurs.
 */
#[AsCommand(name: 'app:categorize', description: 'Catégorise automatiquement les opérations (règles + similarité)')]
final class CategorizeCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Categorizer $categorizer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('all', null, InputOption::VALUE_NONE, 'Re-catégorise toutes les opérations non manuelles (pas seulement les non catégorisées)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Calcule sans rien écrire en base')
            ->addOption('threshold', null, InputOption::VALUE_REQUIRED, 'Seuil de similarité pg_trgm', '0.6')
            ->addOption('reset-unmatched', null, InputOption::VALUE_NONE, 'Retire la catégorie des opérations que plus rien ne correspond')
            ->addOption('account', null, InputOption::VALUE_REQUIRED, 'Restreint à un compte (id)');
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

        $accountId = null !== $input->getOption('account') ? (int) $input->getOption('account') : null;
        $onlyUncategorized = !$input->getOption('all');

        /** @var OperationRepository $operationRepository */
        $operationRepository = $this->entityManager->getRepository(Operation::class);
        $queryBuilder = $operationRepository->createQueryBuilder('o')
            ->join('o.account', 'a')
            ->where('a.household = :household')
            ->orderBy('o.id', 'ASC')
            ->setParameter('household', $household);
        if (null !== $accountId) {
            $queryBuilder->andWhere('a.id = :accountId')->setParameter('accountId', $accountId);
        }
        if ($onlyUncategorized) {
            $queryBuilder->andWhere('o.category IS NULL');
        }

        $options = new CategorizeOptions(
            onlyUncategorized: $onlyUncategorized,
            dryRun: (bool) $input->getOption('dry-run'),
            similarityThreshold: (float) $input->getOption('threshold'),
            resetUnmatched: (bool) $input->getOption('reset-unmatched'),
            accountId: $accountId,
        );
        $report = $this->categorizer->categorize($queryBuilder->getQuery()->toIterable(), $options);

        $total = (int) $operationRepository->createQueryBuilder('o')
            ->select('COUNT(o.id)')
            ->join('o.account', 'a')
            ->where('a.household = :household')
            ->setParameter('household', $household)
            ->getQuery()->getSingleScalarResult();
        $categorized = (int) $operationRepository->createQueryBuilder('o')
            ->select('COUNT(o.id)')
            ->join('o.account', 'a')
            ->where('a.household = :household')
            ->andWhere('o.category IS NOT NULL')
            ->setParameter('household', $household)
            ->getQuery()->getSingleScalarResult();
        $percent = $total > 0 ? round(100 * $categorized / $total, 1) : 0.0;

        $io->writeln(sprintf(
            'Traitées : %d | par règle : %d | par similarité : %d | inchangées : %d | non catégorisées : %d.',
            $report->processed,
            $report->byRule,
            $report->bySimilarity,
            $report->unchanged,
            $report->stillUncategorized,
        ));
        $io->writeln(sprintf('Opérations catégorisées du foyer : %d/%d (%.1f %%).', $categorized, $total, $percent));
        if ($options->dryRun) {
            $io->note('Dry-run : rien n’a été écrit.');
        }

        return Command::SUCCESS;
    }
}
