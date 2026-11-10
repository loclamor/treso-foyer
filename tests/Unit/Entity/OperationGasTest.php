<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Account;
use App\Entity\Household;
use App\Entity\Operation;
use App\Enum\AccountType;
use PHPUnit\Framework\TestCase;

/**
 * Achat de gaz en bouteille sur Operation (ADR 0017) : recordGas/clearGas/hasGas,
 * prix au kg calculé, exceptions (masse ≤ 0, crédit). Données fictives.
 */
final class OperationGasTest extends TestCase
{
    private function makeOperation(int $amount = -3990): Operation
    {
        $household = new Household('Foyer test');
        $account = new Account($household, 'perso', 'Compte perso', AccountType::Personal, hash('sha256', 'FIXTURE-COMPTE-PERSO'), '****PERSO');

        return new Operation($account, new \DateTimeImmutable('2026-03-01'), $amount, 'ACHAT CB INTERMARCHE 01.03.26', 'ACHAT CB INTERMARCHE 01.03.26', 'fp-'.bin2hex(random_bytes(8)));
    }

    public function testRecordGasAndGetters(): void
    {
        $operation = $this->makeOperation();
        self::assertFalse($operation->hasGas());
        self::assertNull($operation->getGasPricePerKg());
        self::assertNull($operation->getGasMassG());

        $operation->recordGas(13000); // bonbonne 13 kg

        self::assertTrue($operation->hasGas());
        self::assertSame(13000, $operation->getGasMassG());
        // Prix au kg = |−3990 c| × 10 / 13000 g = 3.069 €/kg.
        self::assertSame(3.069, $operation->getGasPricePerKg());
    }

    public function testRecordGasReplacesExistingGas(): void
    {
        $operation = $this->makeOperation();
        $operation->recordGas(13000);
        $operation->recordGas(6000);

        self::assertSame(6000, $operation->getGasMassG());
        // 3990 × 10 / 6000 = 6.65 €/kg.
        self::assertSame(6.65, $operation->getGasPricePerKg());
    }

    public function testClearGas(): void
    {
        $operation = $this->makeOperation();
        $operation->recordGas(13000);
        $operation->clearGas();

        self::assertFalse($operation->hasGas());
        self::assertNull($operation->getGasMassG());
        self::assertNull($operation->getGasPricePerKg());
    }

    public function testRecordGasThrowsOnNonPositiveMass(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->makeOperation()->recordGas(0);
    }

    public function testRecordGasThrowsOnCredit(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->makeOperation(5000)->recordGas(13000);
    }
}
