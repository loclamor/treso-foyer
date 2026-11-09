<?php

declare(strict_types=1);

namespace App\Tests\Unit\Vehicle;

use App\Vehicle\AdblueConsumptionCalculator;
use App\Vehicle\OdometerCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Consommation d'AdBlue (ADR 0016) : Σ volumes des remplissages datés dans
 * ]coveredFrom, coveredTo] ÷ km, en L/1000 km. Données fictives uniquement.
 */
final class AdblueConsumptionCalculatorTest extends TestCase
{
    private AdblueConsumptionCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new AdblueConsumptionCalculator(new OdometerCalculator());
    }

    /** @return array{date: \DateTimeImmutable, volumeMl: int} */
    private static function fill(string $date, int $volumeMl): array
    {
        return ['date' => new \DateTimeImmutable($date), 'volumeMl' => $volumeMl];
    }

    public function testVolumesOverCoveredWindowExcludingStartDay(): void
    {
        $points = [['date' => '2026-01-01', 'km' => 10000], ['date' => '2026-07-01', 'km' => 20000]];
        $fills = [
            self::fill('2026-01-01', 9000),  // jour de coveredFrom : compense l'avant, exclu
            self::fill('2026-03-15', 5000),
            self::fill('2026-06-20', 7000),
            self::fill('2026-08-01', 4000),  // après coveredTo : exclu
        ];

        $result = $this->calculator->compute($fills, $points, new \DateTimeImmutable('1900-01-01'), new \DateTimeImmutable('2999-12-31'));

        // 12 000 mL sur 10 000 km ⇒ 1,2 L/1000 km.
        self::assertSame(1.2, $result['consumption']);
        self::assertSame(10000, $result['distanceKm']);
    }

    public function testPeriodRestrictsWindowWithInterpolatedBounds(): void
    {
        $points = [['date' => '2026-01-01', 'km' => 10000], ['date' => '2026-12-31', 'km' => 46400]];
        $fills = [self::fill('2026-02-10', 3000), self::fill('2026-05-10', 6000)];

        $result = $this->calculator->compute($fills, $points, new \DateTimeImmutable('2026-04-01'), new \DateTimeImmutable('2026-06-30'));

        // Seul le remplissage du 10/05 est dans la fenêtre ; 100 km/j × 90 j = 9 000 km.
        self::assertSame(9000, $result['distanceKm']);
        self::assertSame(round(6000 / 9000, 2), $result['consumption']);
    }

    public function testNullWithoutDistanceOrFills(): void
    {
        $oneReading = [['date' => '2026-01-01', 'km' => 10000]];
        self::assertSame(['consumption' => null, 'distanceKm' => null], $this->calculator->compute([self::fill('2026-02-01', 5000)], $oneReading, new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-12-31')));

        $points = [['date' => '2026-01-01', 'km' => 10000], ['date' => '2026-03-01', 'km' => 12000]];
        $result = $this->calculator->compute([], $points, new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-12-31'));
        self::assertNull($result['consumption']);
        self::assertSame(2000, $result['distanceKm']);
    }
}
