<?php

declare(strict_types=1);

namespace App\Tests\Unit\Vehicle;

use App\Entity\Household;
use App\Entity\OdometerReading;
use App\Entity\Vehicle;
use App\Vehicle\OdometerCalculator;
use App\Vehicle\OdometerDistance;
use PHPUnit\Framework\TestCase;

/**
 * Calculateur pur de distance kilométrique (lot 4) : interpolation linéaire,
 * bornes, 0/1 relevé, période hors relevés, relevés incohérents (km décroissant
 * avec la date ⇒ ignorés). Données fictives uniquement.
 */
final class OdometerCalculatorTest extends TestCase
{
    private OdometerCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new OdometerCalculator();
    }

    public function testExactBoundsOnReadings(): void
    {
        $distance = $this->calculator->distance([
            ['date' => '2026-01-01', 'km' => 1000],
            ['date' => '2026-01-31', 'km' => 4000],
        ], new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-31'));

        self::assertInstanceOf(OdometerDistance::class, $distance);
        self::assertSame(3000.0, $distance->km);
        self::assertSame('2026-01-01', $distance->coveredFrom->format('Y-m-d'));
        self::assertSame('2026-01-31', $distance->coveredTo->format('Y-m-d'));
        self::assertSame(['km' => 3000.0, 'coveredFrom' => '2026-01-01', 'coveredTo' => '2026-01-31'], $distance->toArray());
    }

    public function testLinearInterpolationAtBounds(): void
    {
        // Relevés 01-01: 1000, 01-11: 2000, 01-21: 4000 ; bornes 01-06 et 01-16.
        $distance = $this->calculator->distance([
            ['date' => '2026-01-01', 'km' => 1000],
            ['date' => '2026-01-11', 'km' => 2000],
            ['date' => '2026-01-21', 'km' => 4000],
        ], new \DateTimeImmutable('2026-01-06'), new \DateTimeImmutable('2026-01-16'));

        self::assertInstanceOf(OdometerDistance::class, $distance);
        // km(01-06) = 1000 + 1000 * 5/10 = 1500 ; km(01-16) = 2000 + 2000 * 5/10 = 3000.
        self::assertSame(1500.0, $distance->km);
        self::assertSame('2026-01-06', $distance->coveredFrom->format('Y-m-d'));
        self::assertSame('2026-01-16', $distance->coveredTo->format('Y-m-d'));
    }

    public function testPeriodIsClampedToReadingsRange(): void
    {
        $distance = $this->calculator->distance([
            ['date' => '2026-01-10', 'km' => 10000],
            ['date' => '2026-03-20', 'km' => 13000],
        ], new \DateTimeImmutable('2025-12-01'), new \DateTimeImmutable('2026-06-30'));

        self::assertInstanceOf(OdometerDistance::class, $distance);
        self::assertSame(3000.0, $distance->km);
        // Intersection avec [premier relevé, dernier relevé].
        self::assertSame('2026-01-10', $distance->coveredFrom->format('Y-m-d'));
        self::assertSame('2026-03-20', $distance->coveredTo->format('Y-m-d'));
    }

    public function testPeriodPartiallyOutsideReadings(): void
    {
        $distance = $this->calculator->distance([
            ['date' => '2026-01-10', 'km' => 10000],
            ['date' => '2026-03-20', 'km' => 13000],
        ], new \DateTimeImmutable('2025-12-01'), new \DateTimeImmutable('2026-02-19'));

        self::assertInstanceOf(OdometerDistance::class, $distance);
        self::assertSame('2026-01-10', $distance->coveredFrom->format('Y-m-d'));
        self::assertSame('2026-02-19', $distance->coveredTo->format('Y-m-d'));
        // km(02-19) = 10000 + 3000 * 40/69 (10/01 → 20/03 = 69 jours, 10/01 → 19/02 = 40 jours).
        self::assertEqualsWithDelta(3000.0 * 40 / 69, $distance->km, 1e-9);
    }

    public function testPeriodOutsideReadingsReturnsNull(): void
    {
        $readings = [
            ['date' => '2026-01-10', 'km' => 10000],
            ['date' => '2026-03-20', 'km' => 13000],
        ];

        // Avant les relevés.
        self::assertNull($this->calculator->distance($readings, new \DateTimeImmutable('2025-01-01'), new \DateTimeImmutable('2025-12-31')));
        // Après les relevés.
        self::assertNull($this->calculator->distance($readings, new \DateTimeImmutable('2026-03-21'), new \DateTimeImmutable('2026-06-30')));
        // from > to.
        self::assertNull($this->calculator->distance($readings, new \DateTimeImmutable('2026-02-01'), new \DateTimeImmutable('2026-01-01')));
    }

    public function testLessThanTwoReadingsReturnsNull(): void
    {
        self::assertNull($this->calculator->distance([], new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-12-31')));
        self::assertNull($this->calculator->distance([
            ['date' => '2026-01-10', 'km' => 10000],
        ], new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-12-31')));
        // Un seul relevé cohérent après filtrage (l'autre incohérent) ⇒ null aussi.
        self::assertNull($this->calculator->distance([
            ['date' => '2026-01-10', 'km' => 10000],
            ['date' => '2026-01-20', 'km' => 9000],
        ], new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-12-31')));
    }

    public function testSingleDayPeriod(): void
    {
        $distance = $this->calculator->distance([
            ['date' => '2026-01-01', 'km' => 1000],
            ['date' => '2026-01-11', 'km' => 2000],
        ], new \DateTimeImmutable('2026-01-06'), new \DateTimeImmutable('2026-01-06'));

        self::assertInstanceOf(OdometerDistance::class, $distance);
        self::assertSame(0.0, $distance->km);
        self::assertSame('2026-01-06', $distance->coveredFrom->format('Y-m-d'));
        self::assertSame('2026-01-06', $distance->coveredTo->format('Y-m-d'));
    }

    public function testIncoherentReadingsAreIgnored(): void
    {
        // 01-10: 900 et 01-30: 2500 sont incohérents (km décroissant) ⇒ ignorés.
        $distance = $this->calculator->distance([
            ['date' => '2026-01-01', 'km' => 1000],
            ['date' => '2026-01-10', 'km' => 900],
            ['date' => '2026-01-20', 'km' => 3000],
            ['date' => '2026-01-30', 'km' => 2500],
            ['date' => '2026-02-05', 'km' => 3500],
        ], new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-02-28'));

        self::assertInstanceOf(OdometerDistance::class, $distance);
        // Relevés conservés : 1000 (01-01), 3000 (01-20), 3500 (02-05).
        self::assertSame(2500.0, $distance->km);
        self::assertSame('2026-01-01', $distance->coveredFrom->format('Y-m-d'));
        self::assertSame('2026-02-05', $distance->coveredTo->format('Y-m-d'));
    }

    public function testEqualKmIsCoherent(): void
    {
        // km constant = cohérent (0 km parcouru), ce n'est pas une incohérence.
        $distance = $this->calculator->distance([
            ['date' => '2026-01-01', 'km' => 1000],
            ['date' => '2026-01-10', 'km' => 1000],
            ['date' => '2026-01-20', 'km' => 1000],
        ], new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-31'));

        self::assertInstanceOf(OdometerDistance::class, $distance);
        self::assertSame(0.0, $distance->km);
        self::assertSame('2026-01-20', $distance->coveredTo->format('Y-m-d'));
    }

    public function testUnsortedMixedEntitiesAndArrays(): void
    {
        $vehicle = new Vehicle(new Household('Foyer test'), 'Clio');
        $readings = [
            new OdometerReading($vehicle, new \DateTimeImmutable('2026-01-21'), 4000),
            ['date' => '2026-01-11', 'km' => 2000],
            new OdometerReading($vehicle, new \DateTimeImmutable('2026-01-01'), 1000),
        ];

        $distance = $this->calculator->distance($readings, new \DateTimeImmutable('2026-01-06'), new \DateTimeImmutable('2026-01-16'));

        self::assertInstanceOf(OdometerDistance::class, $distance);
        self::assertSame(1500.0, $distance->km);
        self::assertSame('2026-01-06', $distance->coveredFrom->format('Y-m-d'));
        self::assertSame('2026-01-16', $distance->coveredTo->format('Y-m-d'));
    }

    public function testDuplicateDateKeepsFirstReading(): void
    {
        $distance = $this->calculator->distance([
            ['date' => '2026-01-01', 'km' => 1000],
            ['date' => '2026-01-01', 'km' => 9000], // même date : ignoré
            ['date' => '2026-01-11', 'km' => 2000],
        ], new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-31'));

        self::assertInstanceOf(OdometerDistance::class, $distance);
        self::assertSame(1000.0, $distance->km);
        self::assertSame('2026-01-11', $distance->coveredTo->format('Y-m-d'));
    }

    public function testInvalidReadingThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->calculator->distance([['km' => 1000]], new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-31'));
    }

    public function testKmAtInterpolatesExactAndOutOfRange(): void
    {
        $readings = [
            ['date' => '2026-01-01', 'km' => 1000],
            ['date' => '2026-01-11', 'km' => 2000],
            ['date' => '2026-01-21', 'km' => 4000],
        ];

        // Interpolation entre 01-11 et 01-21 : km(01-16) = 2000 + 2000 × 5/10 = 3000.
        self::assertSame(3000.0, $this->calculator->kmAt($readings, new \DateTimeImmutable('2026-01-16')));
        // Exact sur un point.
        self::assertSame(2000.0, $this->calculator->kmAt($readings, new \DateTimeImmutable('2026-01-11')));
        self::assertSame(1000.0, $this->calculator->kmAt($readings, new \DateTimeImmutable('2026-01-01')));
        // Hors [premier, dernier] ⇒ null (pas d'extrapolation).
        self::assertNull($this->calculator->kmAt($readings, new \DateTimeImmutable('2025-12-31')));
        self::assertNull($this->calculator->kmAt($readings, new \DateTimeImmutable('2026-01-22')));
    }

    public function testKmAtWithLessThanTwoPoints(): void
    {
        self::assertNull($this->calculator->kmAt([], new \DateTimeImmutable('2026-01-10')));
        // Un seul point exactement à la date ⇒ son km.
        self::assertSame(10000.0, $this->calculator->kmAt([['date' => '2026-01-10', 'km' => 10000]], new \DateTimeImmutable('2026-01-10')));
        // Un seul point à une autre date ⇒ null.
        self::assertNull($this->calculator->kmAt([['date' => '2026-01-10', 'km' => 10000]], new \DateTimeImmutable('2026-01-11')));
    }

    public function testKmAtIgnoresIncoherentPoints(): void
    {
        // 01-10 à 900 ignoré (km décroissant) ⇒ points retenus 01-01 (1000) et 01-21 (4000).
        $readings = [
            ['date' => '2026-01-01', 'km' => 1000],
            ['date' => '2026-01-10', 'km' => 900],
            ['date' => '2026-01-21', 'km' => 4000],
        ];

        // km(01-11) = 1000 + 3000 × 10/20 = 2500.
        self::assertSame(2500.0, $this->calculator->kmAt($readings, new \DateTimeImmutable('2026-01-11')));
    }
}
