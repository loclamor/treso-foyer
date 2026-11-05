<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Command\MemberAddCommand;
use App\Command\SetupCommand;
use App\Entity\Category;
use App\Entity\Household;
use App\Entity\Member;
use App\Setup\DefaultCategories;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class SetupCommandTest extends KernelTestCase
{
    private function expectedCategoryCount(): int
    {
        $count = 0;
        foreach (DefaultCategories::TREE as $root) {
            $count += 1 + count($root['children']);
        }

        return $count;
    }

    public function testSetupCreatesHouseholdAndAllDefaultCategoriesThenIsIdempotent(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        /** @var SetupCommand $setup */
        $setup = $container->get(SetupCommand::class);
        $tester = new CommandTester($setup);
        $exitCode = $tester->execute(['--household-name' => 'Foyer test']);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('créé', $tester->getDisplay());

        $entityManager = $container->get(EntityManagerInterface::class);
        self::assertCount(1, $entityManager->getRepository(Household::class)->findAll());
        self::assertSame('Foyer test', $entityManager->getRepository(Household::class)->findOneBy([])->getName());
        self::assertCount($this->expectedCategoryCount(), $entityManager->getRepository(Category::class)->findAll());

        // Second run : rien de nouveau créé.
        $testerAgain = new CommandTester($setup);
        $exitCode = $testerAgain->execute([]);
        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('0 catégorie(s) créée(s)', $testerAgain->getDisplay());

        $entityManager->clear();
        self::assertCount(1, $entityManager->getRepository(Household::class)->findAll());
        self::assertCount($this->expectedCategoryCount(), $entityManager->getRepository(Category::class)->findAll());
    }

    public function testTransportVehicleCategoriesAreFlaggedVehicleRelated(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $setup = $container->get(SetupCommand::class);
        (new CommandTester($setup))->execute([]);

        $entityManager = $container->get(EntityManagerInterface::class);
        $flagged = [];
        foreach ($entityManager->getRepository(Category::class)->findAll() as $category) {
            if ($category->isVehicleRelated()) {
                $flagged[] = $category->getFullName();
            }
        }
        sort($flagged);

        self::assertSame([
            'Transport › Assurance auto',
            'Transport › Carburant',
            'Transport › Entretien véhicule',
            'Transport › Péages & parking',
        ], $flagged);
    }

    public function testMemberAddCreatesMemberAndRejectsDuplicateUsername(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $setup = $container->get(SetupCommand::class);
        (new CommandTester($setup))->execute([]);

        /** @var MemberAddCommand $memberAdd */
        $memberAdd = $container->get(MemberAddCommand::class);
        $tester = new CommandTester($memberAdd);
        $exitCode = $tester->execute(
            ['username' => 'alice', 'displayName' => 'Alice'],
            ['interactive' => false],
        );

        self::assertSame(Command::SUCCESS, $exitCode);

        $entityManager = $container->get(EntityManagerInterface::class);
        $member = $entityManager->getRepository(Member::class)->findOneBy(['username' => 'alice']);
        self::assertNotNull($member);
        self::assertSame('Alice', $member->getDisplayName());
        // Pas de mot de passe en non-interactif sans --password.
        self::assertNull($member->getPassword());

        $duplicateTester = new CommandTester($memberAdd);
        $exitCode = $duplicateTester->execute(
            ['username' => 'alice', 'displayName' => 'Alice bis'],
            ['interactive' => false],
        );
        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('déjà pris', $duplicateTester->getDisplay());
    }

    public function testMemberAddFailsWhenNoHouseholdExists(): void
    {
        self::bootKernel();

        /** @var MemberAddCommand $memberAdd */
        $memberAdd = self::getContainer()->get(MemberAddCommand::class);
        $tester = new CommandTester($memberAdd);
        $exitCode = $tester->execute(
            ['username' => 'bob', 'displayName' => 'Bob'],
            ['interactive' => false],
        );

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('app:setup', $tester->getDisplay());
    }
}
