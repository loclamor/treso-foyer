<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Command\CategorizeCommand;
use App\Command\SetupCommand;
use App\Command\TransfersMatchCommand;
use App\Entity\Account;
use App\Entity\InternalTransfer;
use App\Entity\Operation;
use App\Enum\CategorizationSource;
use App\Setup\CategoryResolver;
use App\Tests\Support\CategorizationFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class CategorizeCommandTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private Account $account;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        (new CommandTester($container->get(SetupCommand::class)))->execute([]);
        $household = $this->entityManager->getRepository(\App\Entity\Household::class)->findOneBy([]);
        $this->account = CategorizationFixtures::makeAccount($household, 'perso-a');
        $this->entityManager->persist($this->account);
        $this->entityManager->flush();
    }

    private function persistOperation(string $date, int $amount, string $label, ?string $merchant = null): Operation
    {
        $operation = CategorizationFixtures::makeOperation($this->account, $date, $amount, $label, $merchant);
        $this->entityManager->persist($operation);
        $this->entityManager->flush();

        return $operation;
    }

    public function testCategorizeAppliesDefaultRulesAndShowsCounters(): void
    {
        $uncategorized = $this->persistOperation('2026-01-10', -2756, 'ACHAT CB CARREFOUR MARKET TOULOUSE', 'CARREFOUR MARKET TOULOUSE');
        $manual = $this->persistOperation('2026-01-11', -1200, 'ACHAT CB BOULANGERIE DU COIN', 'BOULANGERIE DU COIN');
        $manual->categorize(
            self::getContainer()->get(CategoryResolver::class)->require($manual->getAccount()->getHousehold(), 'Alimentation › Courses'),
            CategorizationSource::Manual,
        );
        $this->entityManager->flush();

        $command = self::getContainer()->get(CategorizeCommand::class);
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $display = $tester->getDisplay();
        self::assertStringContainsString('par règle : 1', $display);
        self::assertStringContainsString('non catégorisées : 0', $display);
        self::assertStringContainsString('2/2 (100.0 %)', $display);
        // Jamais de libellé d'opération dans la sortie.
        self::assertStringNotContainsString('CARREFOUR', $display);

        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(Operation::class, $uncategorized->getId());
        self::assertSame('Alimentation › Courses', $reloaded?->getCategory()?->getFullName());
        self::assertSame(CategorizationSource::Rule, $reloaded?->getCategorizationSource());
    }

    public function testCategorizeDryRunWritesNothing(): void
    {
        $operation = $this->persistOperation('2026-01-10', -2756, 'ACHAT CB CARREFOUR MARKET TOULOUSE', 'CARREFOUR MARKET TOULOUSE');

        $tester = new CommandTester(self::getContainer()->get(CategorizeCommand::class));
        $exitCode = $tester->execute(['--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('par règle : 1', $tester->getDisplay());
        $this->entityManager->clear();
        self::assertNull($this->entityManager->find(Operation::class, $operation->getId())?->getCategory());
    }

    public function testCategorizeAllRecategorizesNonManualOperations(): void
    {
        $operation = $this->persistOperation('2026-01-10', -2756, 'ACHAT CB CARREFOUR MARKET TOULOUSE', 'CARREFOUR MARKET TOULOUSE');
        $courses = self::getContainer()->get(CategoryResolver::class)->require($this->account->getHousehold(), 'Alimentation › Courses');
        $operation->categorize($courses, CategorizationSource::Rule);
        $this->entityManager->flush();

        // Sans --all : déjà catégorisée, non retraitée.
        $tester = new CommandTester(self::getContainer()->get(CategorizeCommand::class));
        $tester->execute([]);
        self::assertStringContainsString('Traitées : 0', $tester->getDisplay());

        // Avec --all : retraitée.
        $testerAll = new CommandTester(self::getContainer()->get(CategorizeCommand::class));
        $testerAll->execute(['--all' => true]);
        self::assertStringContainsString('Traitées : 1', $testerAll->getDisplay());
    }
}
