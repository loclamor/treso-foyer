<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Account;
use App\Entity\Category;
use App\Entity\Household;
use App\Entity\Member;
use App\Entity\Operation;
use App\Enum\AccountType;
use App\Enum\CategorizationSource;
use App\Enum\CategoryKind;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SchemaTest extends KernelTestCase
{
    private function makeAccount(Household $household, string $alias): Account
    {
        return new Account(
            $household,
            $alias,
            'Compte '.$alias,
            AccountType::Personal,
            hash_hmac('sha256', 'FIXTURE-COMPTE-'.$alias, 'test-pepper'),
            '****'.substr($alias, -4),
        );
    }

    private function makeOperation(Account $account, string $fingerprint, int $amount = -2756): Operation
    {
        return new Operation(
            $account,
            new \DateTimeImmutable('2026-01-10'),
            $amount,
            'CARTE INTERMARCHE',
            'CARTE INTERMARCHE',
            $fingerprint,
        );
    }

    public function testEntitiesPersistTogether(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $household = new Household('Foyer test');
        $member = new Member($household, 'testeur', 'Testeur');
        $account = $this->makeAccount($household, 'perso');
        $account->addHolder($member);
        $category = new Category($household, 'Courses', CategoryKind::Expense);
        $operation = $this->makeOperation($account, hash('sha256', 'FIXTURE-OP-1'));
        $operation->categorize($category, CategorizationSource::Manual);

        $entityManager->persist($household);
        $entityManager->persist($member);
        $entityManager->persist($account);
        $entityManager->persist($category);
        $entityManager->persist($operation);
        $entityManager->flush();

        self::assertNotNull($operation->getId());
        self::assertNotNull($household->getId());
        self::assertSame($category, $operation->getCategory());
        self::assertNotNull($operation->getCategorizedAt());
        self::assertContains('ROLE_USER', $member->getRoles());
        self::assertSame('testeur', $member->getUserIdentifier());
    }

    public function testFingerprintIsUnique(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $household = new Household('Foyer test');
        $account = $this->makeAccount($household, 'perso');
        $entityManager->persist($household);
        $entityManager->persist($account);

        $fingerprint = hash('sha256', 'FIXTURE-DOUBLON');
        $entityManager->persist($this->makeOperation($account, $fingerprint));
        $entityManager->flush();

        $entityManager->persist($this->makeOperation($account, $fingerprint, -1000));
        $this->expectException(UniqueConstraintViolationException::class);
        $entityManager->flush();
    }

    public function testTwoRootCategoriesWithSameNameAreRejected(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $household = new Household('Foyer test');
        $entityManager->persist($household);
        $entityManager->persist(new Category($household, 'Logement', CategoryKind::Expense));
        $entityManager->flush();

        $entityManager->persist(new Category($household, 'Logement', CategoryKind::Expense));
        $this->expectException(UniqueConstraintViolationException::class);
        $entityManager->flush();
    }

    public function testNormalizedLabelIndexUsesGinTrgm(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);

        $indexdef = (string) $connection->fetchOne(
            "SELECT indexdef FROM pg_indexes WHERE schemaname = 'public' AND indexname = 'idx_operation_normalized_label_trgm'",
        );

        self::assertStringContainsStringIgnoringCase('USING gin', $indexdef);
        self::assertStringContainsString('gin_trgm_ops', $indexdef);
    }
}
