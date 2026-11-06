<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Account;
use App\Entity\Household;
use App\Entity\Operation;
use App\Enum\AccountType;
use PHPUnit\Framework\TestCase;

/**
 * Saisie manuelle (Operation::manualEntry, ADR 0014) : date = spentAt, en attente de relevé,
 * empreinte « manual:… » aléatoire (jamais un sha256 d'import), date effective.
 */
final class OperationManualEntryTest extends TestCase
{
    private function makeAccount(): Account
    {
        return new Account(
            new Household('Foyer test'),
            'perso',
            'Compte perso',
            AccountType::Personal,
            hash('sha256', 'test-1'),
            '****1234',
        );
    }

    public function testManualEntry(): void
    {
        $account = $this->makeAccount();
        $spentAt = new \DateTimeImmutable('2026-10-20');
        $operation = Operation::manualEntry($account, $spentAt, -4599, 'ACHAT CB AMAZON 20.10.26', 'ACHAT CB AMAZON', 'AMAZON');

        self::assertSame($spentAt, $operation->getDate());
        self::assertSame($spentAt, $operation->getSpentAt());
        self::assertSame($spentAt, $operation->getEffectiveDate());
        self::assertSame(-4599, $operation->getAmount());
        self::assertSame('ACHAT CB AMAZON 20.10.26', $operation->getRawLabel());
        self::assertSame('AMAZON', $operation->getMerchant());
        self::assertTrue($operation->isAwaitingStatement());
        self::assertNull($operation->getReconciledAt());
        self::assertNull($operation->getLabelSource());
        self::assertNull($operation->getImportBatch());
        // Empreinte « manual:… » : jamais un sha256 hexadécimal d'import (64 caractères),
        // donc jamais retrouvée par le dédoublonnage exact de l'import.
        self::assertStringStartsWith('manual:', $operation->getFingerprint());
        self::assertSame(0, preg_match('/^[0-9a-f]{64}$/', $operation->getFingerprint()));
    }

    public function testManualEntryEmpreintesUniques(): void
    {
        $account = $this->makeAccount();
        $a = Operation::manualEntry($account, new \DateTimeImmutable('2026-10-20'), -100, 'A', 'A', null);
        $b = Operation::manualEntry($account, new \DateTimeImmutable('2026-10-20'), -100, 'A', 'A', null);
        self::assertNotSame($a->getFingerprint(), $b->getFingerprint());
    }

    public function testEffectiveDateFallbackSurDateDuReleve(): void
    {
        $account = $this->makeAccount();
        $operation = new Operation(
            $account,
            new \DateTimeImmutable('2026-08-26'),
            -2756,
            'ACHAT CB X 25.08.26',
            'ACHAT CB X',
            hash('sha256', 'test-op-1'),
        );
        // Sans spentAt : la date effective est la date du relevé.
        self::assertSame('2026-08-26', $operation->getEffectiveDate()->format('Y-m-d'));
        self::assertFalse($operation->isAwaitingStatement());

        $operation->setSpentAt(new \DateTimeImmutable('2026-08-25'));
        self::assertSame('2026-08-25', $operation->getEffectiveDate()->format('Y-m-d'));
        // La date du relevé n'est pas modifiée.
        self::assertSame('2026-08-26', $operation->getDate()->format('Y-m-d'));
    }

    public function testEffectiveDateSqlEtDql(): void
    {
        self::assertSame('COALESCE(o.spent_at, o.date)', Operation::effectiveDateSql('o'));
        self::assertSame('COALESCE(x.spent_at, x.date)', Operation::effectiveDateSql('x'));
        self::assertSame(
            'CASE WHEN o.spentAt IS NULL THEN o.date ELSE o.spentAt END',
            Operation::effectiveDateDql('o'),
        );
    }
}
