<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Household;
use App\Merchant\MerchantGrouper;
use App\Repository\HouseholdRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Regroupe les variantes de commerçant (voir docs/adr/0013-regroupement-commercants.md).
 * Idempotent. N'affiche que des compteurs.
 */
#[AsCommand(name: 'app:merchants:group', description: 'Regroupe les variantes de libellé d’un même commerçant (idempotent)')]
final class MerchantsGroupCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MerchantGrouper $merchantGrouper,
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

        $report = $this->merchantGrouper->sync($household, dryRun: (bool) $input->getOption('dry-run'));
        $io->writeln(sprintf('Commerçants : %d groupe(s) de variantes, %d opération(s) mise(s) à jour.', $report->groups, $report->updated));
        if ($input->getOption('dry-run')) {
            $io->note('Dry-run : rien n’a été écrit.');
        }

        return Command::SUCCESS;
    }
}
