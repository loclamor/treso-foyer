<?php

declare(strict_types=1);

namespace App\Tests\Unit\Vehicle;

use App\Entity\Household;
use App\Entity\Vehicle;
use App\Enum\VehicleSource;
use App\Vehicle\VehicleAssignmentPolicy;
use PHPUnit\Framework\TestCase;

final class VehicleAssignmentPolicyTest extends TestCase
{
    private VehicleAssignmentPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new VehicleAssignmentPolicy();
    }

    public function testRuleVehicleWinsOverEverything(): void
    {
        $date = new \DateTimeImmutable('2026-03-10');
        $vehicles = [
            $this->vehicle(1, '2026-01-01', '2026-06-01'),
            $this->vehicle(2, '2026-01-01', null),
        ];

        // Règle prioritaire même avec 2 véhicules actifs et catégorie non vehicleRelated.
        $decision = $this->policy->decide(7, false, $date, $vehicles);

        self::assertSame(['vehicleId' => 7, 'source' => VehicleSource::Rule], $decision);
    }

    public function testRuleVehicleWinsEvenWithoutCategoryRelated(): void
    {
        $decision = $this->policy->decide(3, false, new \DateTimeImmutable('2026-03-10'), []);

        self::assertSame(['vehicleId' => 3, 'source' => VehicleSource::Rule], $decision);
    }

    public function testSingleActiveVehicleGivesAuto(): void
    {
        $date = new \DateTimeImmutable('2026-03-10');
        $vehicles = [
            $this->vehicle(1, '2026-01-01', '2026-06-01'), // actif à la date
            $this->vehicle(2, '2026-04-01', null),          // pas encore acquis
        ];

        $decision = $this->policy->decide(null, true, $date, $vehicles);

        self::assertSame(['vehicleId' => 1, 'source' => VehicleSource::Auto], $decision);
    }

    public function testTwoActiveVehiclesGiveNothing(): void
    {
        $date = new \DateTimeImmutable('2026-03-10');
        $vehicles = [
            $this->vehicle(1, null, null),
            $this->vehicle(2, '2026-01-01', null),
        ];

        self::assertNull($this->policy->decide(null, true, $date, $vehicles));
    }

    public function testNoActiveVehicleGivesNothing(): void
    {
        $date = new \DateTimeImmutable('2026-03-10');
        $vehicles = [
            $this->vehicle(1, '2026-01-01', '2026-03-01'), // cédé avant la date
            $this->vehicle(2, '2026-04-01', null),          // acquis après la date
        ];

        self::assertNull($this->policy->decide(null, true, $date, $vehicles));
    }

    public function testNoVehicleAtAllGivesNothing(): void
    {
        self::assertNull($this->policy->decide(null, true, new \DateTimeImmutable('2026-03-10'), []));
    }

    public function testAcquiredAtBoundIsInclusive(): void
    {
        $vehicle = $this->vehicle(1, '2026-03-10', null);

        // Acquis le jour J : actif le jour J.
        $decision = $this->policy->decide(null, true, new \DateTimeImmutable('2026-03-10'), [$vehicle]);
        self::assertSame(['vehicleId' => 1, 'source' => VehicleSource::Auto], $decision);

        // La veille : pas encore actif.
        self::assertNull($this->policy->decide(null, true, new \DateTimeImmutable('2026-03-09'), [$vehicle]));
    }

    public function testDisposedAtBoundIsInclusive(): void
    {
        $vehicle = $this->vehicle(1, '2026-01-01', '2026-03-10');

        // Cédé le jour J : encore actif le jour J.
        $decision = $this->policy->decide(null, true, new \DateTimeImmutable('2026-03-10'), [$vehicle]);
        self::assertSame(['vehicleId' => 1, 'source' => VehicleSource::Auto], $decision);

        // Le lendemain : plus actif.
        self::assertNull($this->policy->decide(null, true, new \DateTimeImmutable('2026-03-11'), [$vehicle]));
    }

    public function testCategoryNotVehicleRelatedGivesNothing(): void
    {
        $vehicles = [$this->vehicle(1, null, null)];

        self::assertNull($this->policy->decide(null, false, new \DateTimeImmutable('2026-03-10'), $vehicles));
    }

    /**
     * Véhicule fictif avec un id fixé (sans persistance) pour tester la policy.
     */
    private function vehicle(int $id, ?string $acquiredAt, ?string $disposedAt): Vehicle
    {
        $vehicle = new Vehicle(new Household('Foyer test'), 'V'.$id);
        $vehicle->setAcquiredAt(null === $acquiredAt ? null : new \DateTimeImmutable($acquiredAt));
        $vehicle->setDisposedAt(null === $disposedAt ? null : new \DateTimeImmutable($disposedAt));
        $idProperty = new \ReflectionProperty(Vehicle::class, 'id');
        $idProperty->setValue($vehicle, $id);

        return $vehicle;
    }
}
