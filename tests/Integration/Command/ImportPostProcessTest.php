<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Command\ImportCommand;
use App\Command\SetupCommand;
use App\Entity\Operation;
use App\Enum\CategorizationSource;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Post-traitement de app:import : appariement des virements internes puis
 * catégorisation automatique après un import réel (fixtures CSV fictives).
 */
final class ImportPostProcessTest extends KernelTestCase
{
    private const FIXTURE_CSV = __DIR__.'/../../Fixtures/csv/releve-a.csv';

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        // Foyer + catégories + règles par défaut.
        (new CommandTester($container->get(SetupCommand::class)))->execute([]);
    }

    public function testImportTriggersTransferMatchingAndCategorization(): void
    {
        $command = self::getContainer()->get(ImportCommand::class);
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'paths' => [self::FIXTURE_CSV],
            '--auto-create-account' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $display = $tester->getDisplay();
        self::assertStringContainsString('Post-traitement', $display);
        // Les 2 opérations « BOULANGERIE » de la fixture sont catégorisées par règle.
        self::assertStringContainsString('2 par règle', $display);
        // Jamais de libellé d'opération dans la sortie.
        self::assertStringNotContainsString('BOULANGERIE', $display);
        self::assertStringNotContainsString('1234567R999', $display);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $operations = $entityManager->getRepository(Operation::class)->findAll();
        self::assertCount(7, $operations);
        $byRule = array_filter(
            $operations,
            static fn (Operation $o): bool => CategorizationSource::Rule === $o->getCategorizationSource(),
        );
        self::assertCount(2, $byRule);
        foreach ($byRule as $operation) {
            self::assertSame('Alimentation › Boulangerie & snacks', $operation->getCategory()?->getFullName());
        }
    }

    public function testNoPostProcessOptionSkipsIt(): void
    {
        $command = self::getContainer()->get(ImportCommand::class);
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'paths' => [self::FIXTURE_CSV],
            '--auto-create-account' => true,
            '--no-post-process' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringNotContainsString('Post-traitement', $tester->getDisplay());

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        foreach ($entityManager->getRepository(Operation::class)->findAll() as $operation) {
            self::assertNull($operation->getCategory());
        }
    }

    public function testDryRunImportSkipsPostProcess(): void
    {
        $command = self::getContainer()->get(ImportCommand::class);
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'paths' => [self::FIXTURE_CSV],
            '--auto-create-account' => true,
            '--dry-run' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringNotContainsString('Post-traitement', $tester->getDisplay());
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        self::assertCount(0, $entityManager->getRepository(Operation::class)->findAll());
    }
}
