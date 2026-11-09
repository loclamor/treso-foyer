<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Account;
use App\Entity\Household;
use App\Entity\Operation;
use App\Enum\AccountType;
use PHPUnit\Framework\TestCase;

/**
 * Plein de carburant sur Operation (ADR 0015) : recordFuel/clearFuel/hasFuel,
 * prix au litre calculé, exceptions (volume ≤ 0, km < 0, crédit). Données fictives.
 */
final class OperationFuelTest extends TestCase
{
    private function makeOperation(int $amount = -6795): Operation
    {
        $household = new Household('Foyer test');
        $account = new Account($household, 'perso', 'Compte perso', AccountType::Personal, hash('sha256', 'FIXTURE-COMPTE-PERSO'), '****PERSO');

        return new Operation($account, new \DateTimeImmutable('2026-03-01'), $amount, 'ESSENCE STATION', 'ESSENCE STATION', 'fp-'.bin2hex(random_bytes(8)));
    }

    public function testRecordFuelAndGetters(): void
    {
        $operation = $this->makeOperation();
        self::assertFalse($operation->hasFuel());
        self::assertNull($operation->getFuelPricePerLiter());

        $operation->recordFuel(45000, true, 48200);

        self::assertTrue($operation->hasFuel());
        self::assertSame(45000, $operation->getFuelVolumeMl());
        self::assertTrue($operation->getFuelFullTank());
        self::assertSame(48200, $operation->getOdometerKm());
        // Prix au litre = |−6795 c| × 10 / 45000 mL = 1.51 €/L.
        self::assertSame(1.51, $operation->getFuelPricePerLiter());
    }

    public function testRecordFuelDefaultsToFullTankWithoutOdometer(): void
    {
        $operation = $this->makeOperation();
        $operation->recordFuel(40000);

        self::assertTrue($operation->getFuelFullTank());
        self::assertNull($operation->getOdometerKm());
    }

    public function testRecordFuelPartialTank(): void
    {
        $operation = $this->makeOperation();
        $operation->recordFuel(30000, false);

        self::assertFalse($operation->getFuelFullTank());
        self::assertSame(30000, $operation->getFuelVolumeMl());
    }

    public function testRecordFuelReplacesExistingFuel(): void
    {
        $operation = $this->makeOperation();
        $operation->recordFuel(45000, true, 48200);
        $operation->recordFuel(30000, false, 49000);

        self::assertSame(30000, $operation->getFuelVolumeMl());
        self::assertFalse($operation->getFuelFullTank());
        self::assertSame(49000, $operation->getOdometerKm());
    }

    public function testClearFuel(): void
    {
        $operation = $this->makeOperation();
        $operation->recordFuel(45000, true, 48200);
        $operation->clearFuel();

        self::assertFalse($operation->hasFuel());
        self::assertNull($operation->getFuelVolumeMl());
        self::assertNull($operation->getFuelFullTank());
        self::assertNull($operation->getOdometerKm());
        self::assertNull($operation->getFuelPricePerLiter());
    }

    public function testRecordFuelThrowsOnNonPositiveVolume(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->makeOperation()->recordFuel(0);
    }

    public function testRecordFuelThrowsOnNegativeOdometer(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->makeOperation()->recordFuel(40000, true, -1);
    }

    public function testRecordFuelThrowsOnCredit(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->makeOperation(5000)->recordFuel(40000);
    }
}
