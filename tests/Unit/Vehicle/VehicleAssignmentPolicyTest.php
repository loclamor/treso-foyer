<?php

declare(strict_types=1);

namespace App\Tests\Unit\Vehicle;

use App\Entity\Household;
use App\Entity\Vehicle;
use App\Enum\VehicleSource;
use App\Vehicle\PeriodVehicle;
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

    public function testPeriodVehicleWinsOverSingleActiveVehicle(): void
    {
        $vehicles = [$this->vehicle(1, null, null), $this->vehicle(2, null, null)];
        $periods = [$this->period('2026-08-01', '2026-08-15', 2)];

        // Deux véhicules actifs (auto impossible) : la période tranche.
        $decision = $this->policy->decide(null, true, new \DateTimeImmutable('2026-08-05'), $vehicles, false, $periods);
        self::assertSame(['vehicleId' => 2, 'source' => VehicleSource::Period], $decision);

        // Un seul véhicule actif (1) : la période (2) l'emporte quand même sur l'auto.
        $decision = $this->policy->decide(null, true, new \DateTimeImmutable('2026-08-05'), [$vehicles[0]], false, $periods);
        self::assertSame(['vehicleId' => 2, 'source' => VehicleSource::Period], $decision);
    }

    public function testPeriodBoundsAreInclusive(): void
    {
        $vehicles = [$this->vehicle(1, null, null)];
        $periods = [$this->period('2026-08-01', '2026-08-15', 9)];

        foreach (['2026-08-01', '2026-08-15 18:30:00'] as $day) {
            $decision = $this->policy->decide(null, true, new \DateTimeImmutable($day), $vehicles, false, $periods);
            self::assertSame(['vehicleId' => 9, 'source' => VehicleSource::Period], $decision, $day);
        }
        foreach (['2026-07-31', '2026-08-16'] as $day) {
            $decision = $this->policy->decide(null, true, new \DateTimeImmutable($day), $vehicles, false, $periods);
            self::assertSame(['vehicleId' => 1, 'source' => VehicleSource::Auto], $decision, $day);
        }
    }

    public function testRuleWinsOverNonOverridingPeriod(): void
    {
        $periods = [$this->period('2026-08-01', '2026-08-15', 2)];

        $decision = $this->policy->decide(7, true, new \DateTimeImmutable('2026-08-05'), [], false, $periods);

        self::assertSame(['vehicleId' => 7, 'source' => VehicleSource::Rule], $decision);
    }

    public function testOverridingPeriodWinsOverRule(): void
    {
        $periods = [$this->period('2026-08-01', '2026-08-15', 2, overridesRules: true)];

        $decision = $this->policy->decide(7, true, new \DateTimeImmutable('2026-08-05'), [], false, $periods);
        self::assertSame(['vehicleId' => 2, 'source' => VehicleSource::Period], $decision);

        // Hors période : la règle reprend la main.
        $decision = $this->policy->decide(7, true, new \DateTimeImmutable('2026-08-20'), [], false, $periods);
        self::assertSame(['vehicleId' => 7, 'source' => VehicleSource::Rule], $decision);
    }

    public function testPeriodIgnoresFinancingAndNonTransportCategories(): void
    {
        $vehicles = [$this->vehicle(1, null, null)];
        $periods = [$this->period('2026-08-01', '2026-08-15', 2, overridesRules: true)];
        $date = new \DateTimeImmutable('2026-08-05');

        // Crédit véhicule (vehicleRelated + financing) : la période ne s'applique pas.
        self::assertSame(
            ['vehicleId' => 1, 'source' => VehicleSource::Auto],
            $this->policy->decide(null, true, $date, $vehicles, true, $periods),
        );
        self::assertSame(
            ['vehicleId' => 5, 'source' => VehicleSource::Rule],
            $this->policy->decide(5, true, $date, $vehicles, true, $periods),
        );
        // Catégorie non vehicleRelated : rien.
        self::assertNull($this->policy->decide(null, false, $date, $vehicles, false, $periods));
    }

    public function testOverlappingPeriodsWithDifferentVehiclesAreIgnored(): void
    {
        $vehicles = [$this->vehicle(1, null, null)];
        $date = new \DateTimeImmutable('2026-08-05');

        // Deux véhicules différents au même niveau ⇒ ambiguïté ⇒ retour à l'auto.
        $periods = [$this->period('2026-08-01', '2026-08-15', 2), $this->period('2026-08-05', '2026-08-06', 3)];
        self::assertSame(
            ['vehicleId' => 1, 'source' => VehicleSource::Auto],
            $this->policy->decide(null, true, $date, $vehicles, false, $periods),
        );

        // Même véhicule ⇒ pas d'ambiguïté.
        $periods = [$this->period('2026-08-01', '2026-08-15', 2), $this->period('2026-08-05', '2026-08-06', 2)];
        self::assertSame(
            ['vehicleId' => 2, 'source' => VehicleSource::Period],
            $this->policy->decide(null, true, $date, $vehicles, false, $periods),
        );

        // Niveaux différents : la période prioritaire l'emporte.
        $periods = [$this->period('2026-08-01', '2026-08-15', 2), $this->period('2026-08-05', '2026-08-06', 3, overridesRules: true)];
        self::assertSame(
            ['vehicleId' => 3, 'source' => VehicleSource::Period],
            $this->policy->decide(null, true, $date, $vehicles, false, $periods),
        );
    }

    private function period(string $start, string $end, int $vehicleId, bool $overridesRules = false): PeriodVehicle
    {
        return new PeriodVehicle(new \DateTimeImmutable($start), new \DateTimeImmutable($end), $vehicleId, $overridesRules);
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
