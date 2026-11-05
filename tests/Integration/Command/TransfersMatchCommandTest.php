<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Command\SetupCommand;
use App\Command\TransfersMatchCommand;
use App\Entity\Account;
use App\Entity\InternalTransfer;
use App\Tests\Support\CategorizationFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class TransfersMatchCommandTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private Account $accountA;
    private Account $accountB;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        (new CommandTester($container->get(SetupCommand::class)))->execute([]);
        $household = $this->entityManager->getRepository(\App\Entity\Household::class)->findOneBy([]);
        $this->accountA = CategorizationFixtures::makeAccount($household, 'perso-a');
        $this->accountB = CategorizationFixtures::makeAccount($household, 'perso-b');
        $this->entityManager->persist($this->accountA);
        $this->entityManager->persist($this->accountB);
        $this->entityManager->flush();
    }

    public function testMatchPairsInternalTransfer(): void
    {
        $debit = CategorizationFixtures::makeOperation($this->accountA, '2026-01-10', -50000, 'VIREMENT DE PERSO A VERS PERSO B');
        $credit = CategorizationFixtures::makeOperation($this->accountB, '2026-01-11', 50000, 'VIREMENT DE PERSO A VERS PERSO B');
        $this->entityManager->persist($debit);
        $this->entityManager->persist($credit);
        $this->entityManager->flush();

        $tester = new CommandTester(self::getContainer()->get(TransfersMatchCommand::class));
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('appariés : 1', $tester->getDisplay());
        self::assertStringNotContainsString('VIREMENT DE PERSO', $tester->getDisplay());

        $this->entityManager->clear();
        self::assertCount(1, $this->entityManager->getRepository(InternalTransfer::class)->findAll());
    }

    public function testMatchDryRunWritesNothing(): void
    {
        $debit = CategorizationFixtures::makeOperation($this->accountA, '2026-01-10', -50000, 'VIREMENT DE PERSO A VERS PERSO B');
        $credit = CategorizationFixtures::makeOperation($this->accountB, '2026-01-11', 50000, 'VIREMENT DE PERSO A VERS PERSO B');
        $this->entityManager->persist($debit);
        $this->entityManager->persist($credit);
        $this->entityManager->flush();

        $tester = new CommandTester(self::getContainer()->get(TransfersMatchCommand::class));
        $exitCode = $tester->execute(['--dry-run' => true, '--days' => '5']);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('appariés : 1', $tester->getDisplay());
        $this->entityManager->clear();
        self::assertCount(0, $this->entityManager->getRepository(InternalTransfer::class)->findAll());
    }
}
