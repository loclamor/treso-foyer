<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Command\RulesSeedCommand;
use App\Command\SetupCommand;
use App\Entity\CategorizationRule;
use App\Setup\DefaultRules;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class RulesSeedCommandTest extends KernelTestCase
{
    public function testSetupSeedsDefaultRulesAndSeedIsIdempotent(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);

        $setup = $container->get(SetupCommand::class);
        $exitCode = (new CommandTester($setup))->execute([]);
        self::assertSame(Command::SUCCESS, $exitCode);

        $expected = count(DefaultRules::all());
        self::assertCount($expected, $entityManager->getRepository(CategorizationRule::class)->findAll());

        // app:rules:seed après app:setup : rien de nouveau.
        $seed = $container->get(RulesSeedCommand::class);
        $tester = new CommandTester($seed);
        $exitCode = $tester->execute([]);
        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('0 règle(s) par défaut créée(s)', $tester->getDisplay());

        // Second app:setup : toujours rien de recréé (sortie normalisée sur une ligne).
        $testerAgain = new CommandTester($setup);
        $testerAgain->execute([]);
        $display = (string) preg_replace('/\s+/', ' ', $testerAgain->getDisplay());
        self::assertStringContainsString('0 catégorie(s) créée(s), 0 règle(s) par défaut créée(s)', $display);

        $entityManager->clear();
        self::assertCount($expected, $entityManager->getRepository(CategorizationRule::class)->findAll());
    }

    public function testSeedFailsWithoutHousehold(): void
    {
        self::bootKernel();
        $seed = self::getContainer()->get(RulesSeedCommand::class);
        $tester = new CommandTester($seed);
        $exitCode = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('app:setup', $tester->getDisplay());
    }
}
