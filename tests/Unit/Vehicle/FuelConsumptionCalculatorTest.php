<?php

declare(strict_types=1);

namespace App\Tests\Unit\Vehicle;

use App\Vehicle\FuelConsumptionCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Calcul pur de la consommation (méthode du plein complet, ADR 0015) : segments entre
 * pleins complets, pleins partiels comptés dans le segment suivant, km interpolé depuis
 * les relevés, premier plein ignoré, segment sans km ignoré, période qui coupe.
 * Données fictives uniquement.
 */
final class FuelConsumptionCalculatorTest extends TestCase
{
    private FuelConsumptionCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new FuelConsumptionCalculator();
    }

    /** @return array{id: int, date: string, volumeMl: int, fullTank: bool, odometerKm: ?int, amount: int} */
    private function fill(int $id, string $date, int $volumeMl, bool $fullTank = true, ?int $odometerKm = null, int $amount = -6000): array
    {
        return ['id' => $id, 'date' => $date, 'volumeMl' => $volumeMl, 'fullTank' => $fullTank, 'odometerKm' => $odometerKm, 'amount' => $amount];
    }

    public function testTwoFullTanksWithKm(): void
    {
        // Entrée non triée : le calculateur trie par date.
        $result = $this->calculator->compute([
            $this->fill(2, '2026-02-10', 45000, true, 10800, -6800),
            $this->fill(1, '2026-01-10', 40000, true, 10000, -6000),
        ], []);

        self::assertCount(2, $result['fills']);
        // Premier plein complet : pas de segment (pas de plein complet précédent).
        self::assertSame(1, $result['fills'][0]['id']);
        self::assertNull($result['fills'][0]['consumption']);
        self::assertNull($result['fills'][0]['segmentKm']);
        // Second plein : segment = 45 L ÷ 800 km × 100 = 5.625 → 5.63 L/100 km.
        self::assertSame(2, $result['fills'][1]['id']);
        self::assertEqualsWithDelta(5.63, $result['fills'][1]['consumption'], 1e-9);
        self::assertEqualsWithDelta(800.0, $result['fills'][1]['segmentKm'], 1e-9);

        $summary = $result['summary'];
        self::assertSame(2, $summary['count']);
        self::assertSame(85000, $summary['volumeMl']);
        self::assertSame(-12800, $summary['amount']);
        // Prix moyen = (6000 + 6800) c€ × 10 / 85000 mL = 128 € / 85 L = 1.506 €/L.
        self::assertEqualsWithDelta(1.506, $summary['avgPricePerLiter'], 1e-9);
        self::assertEqualsWithDelta(5.63, $summary['consumption'], 1e-9);
        self::assertSame(800, $summary['distanceKm']);
    }

    public function testPartialTankBetweenTwoFullTanksIsCountedInTheSegment(): void
    {
        $result = $this->calculator->compute([
            $this->fill(1, '2026-01-10', 40000, true, 10000, -6000),
            $this->fill(2, '2026-01-20', 30000, false, null, -4500), // plein partiel (plafond de carte)
            $this->fill(3, '2026-02-10', 45000, true, 10800, -6800),
        ], []);

        // Le partiel n'ouvre ni ne ferme de segment.
        self::assertNull($result['fills'][1]['consumption']);
        // Segment du plein 3 = volumes des pleins 2 et 3 = 75 L ÷ 800 km × 100 = 9.375 → 9.38.
        self::assertEqualsWithDelta(9.38, $result['fills'][2]['consumption'], 1e-9);
        self::assertEqualsWithDelta(800.0, $result['fills'][2]['segmentKm'], 1e-9);
        self::assertSame(3, $result['summary']['count']);
        self::assertSame(115000, $result['summary']['volumeMl']);
        self::assertEqualsWithDelta(9.38, $result['summary']['consumption'], 1e-9);
        self::assertSame(800, $result['summary']['distanceKm']);
    }

    public function testFullTankWithoutKmIsInterpolatedFromOdometerPoints(): void
    {
        $points = [
            ['date' => '2026-01-01', 'km' => 10000],
            ['date' => '2026-01-31', 'km' => 10600], // 600 km sur 30 jours
        ];
        $result = $this->calculator->compute([
            $this->fill(1, '2026-01-11', 40000, true, null, -6000), // km interpolé = 10000 + 600 × 10/30 = 10200
            $this->fill(2, '2026-01-21', 20000, true, 10400, -3000),
        ], $points);

        // Segment du plein 2 : 20 L ÷ (10400 − 10200) km × 100 = 10 L/100 km.
        self::assertNull($result['fills'][0]['consumption']);
        self::assertEqualsWithDelta(10.0, $result['fills'][1]['consumption'], 1e-9);
        self::assertEqualsWithDelta(200.0, $result['fills'][1]['segmentKm'], 1e-9);
        self::assertEqualsWithDelta(10.0, $result['summary']['consumption'], 1e-9);
        self::assertSame(200, $result['summary']['distanceKm']);
    }

    public function testFirstFullTankAndEarlierFillsAreIgnored(): void
    {
        $result = $this->calculator->compute([
            $this->fill(1, '2026-01-05', 10000, false, 9500, -1500), // avant le premier plein complet
            $this->fill(2, '2026-01-10', 40000, true, 10000, -6000), // premier plein complet
            $this->fill(3, '2026-02-10', 40000, true, 10800, -6800),
        ], []);

        self::assertNull($result['fills'][0]['consumption']);
        self::assertNull($result['fills'][1]['consumption']);
        // Segment du plein 3 = 40 L ÷ 800 km × 100 = 5 L/100 km (le plein 2 n'est pas dans son propre segment).
        self::assertEqualsWithDelta(5.0, $result['fills'][2]['consumption'], 1e-9);
        self::assertSame(3, $result['summary']['count']); // le partiel compte dans le volume de la période
        self::assertSame(90000, $result['summary']['volumeMl']);
        self::assertEqualsWithDelta(5.0, $result['summary']['consumption'], 1e-9);
    }

    public function testSegmentWithoutKmIsNull(): void
    {
        // Aucun point de compteur et aucun km saisi sur le premier plein ⇒ segment ignoré.
        $result = $this->calculator->compute([
            $this->fill(1, '2026-01-10', 40000, true, null, -6000),
            $this->fill(2, '2026-02-10', 40000, true, 10800, -6800),
        ], []);

        self::assertNull($result['fills'][1]['consumption']);
        self::assertNull($result['fills'][1]['segmentKm']);
        self::assertNull($result['summary']['consumption']);
        self::assertNull($result['summary']['distanceKm']);
        // Mais le volume et le montant de la période sont bien comptés.
        self::assertSame(2, $result['summary']['count']);
        self::assertSame(80000, $result['summary']['volumeMl']);
        self::assertEqualsWithDelta(1.6, $result['summary']['avgPricePerLiter'], 1e-9); // 128 € / 80 L
    }

    public function testSegmentWithNonPositiveDistanceIsIgnored(): void
    {
        $result = $this->calculator->compute([
            $this->fill(1, '2026-01-10', 40000, true, 10000, -6000),
            $this->fill(2, '2026-02-10', 40000, true, 10000, -6800), // même km ⇒ distance 0
        ], []);

        self::assertNull($result['fills'][1]['consumption']);
        self::assertNull($result['summary']['consumption']);
        self::assertNull($result['summary']['distanceKm']);
    }

    public function testPeriodCuttingKeepsSegmentsEndingInsideIt(): void
    {
        $fills = [
            $this->fill(1, '2025-12-10', 40000, true, 10000, -6000), // plein complet hors période
            $this->fill(2, '2026-01-10', 40000, true, 10800, -6800),
            $this->fill(3, '2026-02-10', 32000, true, 11600, -5500),
        ];
        $result = $this->calculator->compute($fills, [], new \DateTimeImmutable('2026-01-01'));

        // Seuls les pleins de la période sont en sortie.
        self::assertCount(2, $result['fills']);
        self::assertSame(2, $result['fills'][0]['id']);
        self::assertSame(3, $result['fills'][1]['id']);
        // Le segment du plein 2 se termine dans la période : son plein précédent (hors période) compte.
        self::assertEqualsWithDelta(5.0, $result['fills'][0]['consumption'], 1e-9); // 40 L / 800 km
        self::assertEqualsWithDelta(4.0, $result['fills'][1]['consumption'], 1e-9); // 32 L / 800 km
        // Synthèse = Σ volumes des segments ÷ Σ km × 100 = 72 L / 1600 km × 100 = 4.5.
        self::assertSame(2, $result['summary']['count']);
        self::assertSame(72000, $result['summary']['volumeMl']);
        self::assertSame(-12300, $result['summary']['amount']);
        self::assertEqualsWithDelta(4.5, $result['summary']['consumption'], 1e-9);
        self::assertSame(1600, $result['summary']['distanceKm']);
    }

    public function testPeriodWithoutFillsReturnsEmptySummary(): void
    {
        $result = $this->calculator->compute([
            $this->fill(1, '2026-01-10', 40000, true, 10000, -6000),
        ], [], new \DateTimeImmutable('2027-01-01'), new \DateTimeImmutable('2027-12-31'));

        self::assertSame([], $result['fills']);
        self::assertSame([
            'count' => 0,
            'volumeMl' => 0,
            'amount' => 0,
            'avgPricePerLiter' => null,
            'consumption' => null,
            'distanceKm' => null,
        ], $result['summary']);
    }

    public function testFullPeriodBoundsAreInclusive(): void
    {
        $fills = [
            $this->fill(1, '2026-01-10', 40000, true, 10000, -6000),
            $this->fill(2, '2026-02-10', 40000, true, 10800, -6800),
        ];
        $result = $this->calculator->compute($fills, [], new \DateTimeImmutable('2026-01-10'), new \DateTimeImmutable('2026-02-10'));

        self::assertCount(2, $result['fills']);
        self::assertSame(2, $result['summary']['count']);
    }
}
