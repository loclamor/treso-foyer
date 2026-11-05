<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Household;
use App\Entity\Vehicle;
use App\Enum\VehicleType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Violation\ConstraintViolationBuilderInterface;

final class VehicleTest extends TestCase
{
    public function testConstructorDefaults(): void
    {
        $vehicle = new Vehicle(new Household('Foyer test'), 'Clio');

        self::assertSame(VehicleType::Car, $vehicle->getType());
        self::assertFalse($vehicle->isArchived());
        self::assertNull($vehicle->getPlate());
        self::assertNull($vehicle->getAcquiredAt());
        self::assertNull($vehicle->getDisposedAt());
    }

    public function testSetPlateNormalizes(): void
    {
        $vehicle = new Vehicle(new Household('Foyer test'), 'Clio');
        $vehicle->setPlate('ab-123-cd');

        self::assertSame('AB-123-CD', $vehicle->getPlate());

        $vehicle->setPlate('1234 AB 75');
        self::assertSame('1234AB75', $vehicle->getPlate());

        $vehicle->setPlate(null);
        self::assertNull($vehicle->getPlate());
    }

    public function testSetPlateInvalidThrows(): void
    {
        $vehicle = new Vehicle(new Household('Foyer test'), 'Clio');

        $this->expectException(\InvalidArgumentException::class);
        $vehicle->setPlate('pas une plaque');
    }

    public function testIsActiveOnWithoutDatesIsAlwaysTrue(): void
    {
        $vehicle = new Vehicle(new Household('Foyer test'), 'Clio');

        self::assertTrue($vehicle->isActiveOn(new \DateTimeImmutable('2020-01-01')));
        self::assertTrue($vehicle->isActiveOn(new \DateTimeImmutable('2026-11-05')));
    }

    public function testIsActiveOnBounds(): void
    {
        $vehicle = new Vehicle(new Household('Foyer test'), 'Clio');
        $vehicle->setAcquiredAt(new \DateTimeImmutable('2026-01-01'));
        $vehicle->setDisposedAt(new \DateTimeImmutable('2026-06-30'));

        self::assertFalse($vehicle->isActiveOn(new \DateTimeImmutable('2025-12-31')));
        self::assertTrue($vehicle->isActiveOn(new \DateTimeImmutable('2026-01-01'))); // borne acquisition
        self::assertTrue($vehicle->isActiveOn(new \DateTimeImmutable('2026-03-15')));
        self::assertTrue($vehicle->isActiveOn(new \DateTimeImmutable('2026-06-30'))); // borne cession
        self::assertFalse($vehicle->isActiveOn(new \DateTimeImmutable('2026-07-01')));
    }

    public function testArchivedVehicleStaysActiveOnItsPastPeriod(): void
    {
        $vehicle = new Vehicle(new Household('Foyer test'), 'Clio');
        $vehicle->setAcquiredAt(new \DateTimeImmutable('2024-01-01'));
        $vehicle->setDisposedAt(new \DateTimeImmutable('2026-01-31'));
        $vehicle->setArchived(true);

        self::assertTrue($vehicle->isActiveOn(new \DateTimeImmutable('2025-06-15')));
        self::assertFalse($vehicle->isActiveOn(new \DateTimeImmutable('2026-11-05')));
    }

    public function testValidateCoherenceAcceptsValidVehicle(): void
    {
        $vehicle = new Vehicle(new Household('Foyer test'), 'Clio');
        $vehicle->setAcquiredAt(new \DateTimeImmutable('2026-01-01'));
        $vehicle->setDisposedAt(new \DateTimeImmutable('2026-01-01'));
        $vehicle->setVin('VF1AB12CD34EF56GH'); // 17 caractères, fictif

        $context = $this->createMock(ExecutionContextInterface::class);
        $context->expects(self::never())->method('buildViolation');

        $vehicle->validateCoherence($context);
    }

    public function testValidateCoherenceRejectsDisposedBeforeAcquired(): void
    {
        $vehicle = new Vehicle(new Household('Foyer test'), 'Clio');
        $vehicle->setAcquiredAt(new \DateTimeImmutable('2026-06-30'));
        $vehicle->setDisposedAt(new \DateTimeImmutable('2026-01-01'));

        $context = $this->createMock(ExecutionContextInterface::class);
        $builder = $this->createMock(ConstraintViolationBuilderInterface::class);
        $builder->expects(self::once())->method('atPath')->with('disposedAt')->willReturnSelf();
        $context->expects(self::once())->method('buildViolation')->willReturn($builder);

        $vehicle->validateCoherence($context);
    }

    public function testValidateCoherenceRejectsWrongVinLength(): void
    {
        $vehicle = new Vehicle(new Household('Foyer test'), 'Clio');
        $vehicle->setVin('TROP COURT');

        $context = $this->createMock(ExecutionContextInterface::class);
        $builder = $this->createMock(ConstraintViolationBuilderInterface::class);
        $builder->expects(self::once())->method('atPath')->with('vin')->willReturnSelf();
        $context->expects(self::once())->method('buildViolation')->willReturn($builder);

        $vehicle->validateCoherence($context);
    }
}
