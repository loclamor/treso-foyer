<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Household;
use App\Repository\HouseholdRepository;
use App\Setup\DefaultRulesSeeder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Insère les règles de catégorisation par défaut (src/Setup/DefaultRules.php).
 * Idempotent : ne recrée pas une règle de même (catégorie, champ, type, motif).
 * Appelée aussi automatiquement à la fin de app:setup.
 */
#[AsCommand(name: 'app:rules:seed', description: 'Insère les règles de catégorisation par défaut (idempotent)')]
final class RulesSeedCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DefaultRulesSeeder $seeder,
    ) {
        parent::__construct();
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

        try {
            $created = $this->seeder->seed($household);
        } catch (\InvalidArgumentException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf('%d règle(s) par défaut créée(s).', $created));

        return Command::SUCCESS;
    }
}
