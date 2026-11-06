<?php

declare(strict_types=1);

namespace App\Tests\Unit\Recurrence;

use App\Enum\RecurrenceFrequency;
use App\Recurrence\RecurrenceCalendar;
use App\Recurrence\RecurrencePattern;
use PHPUnit\Framework\TestCase;

/**
 * Détection pure d'un motif récurrent (lot récurrences) : mensuel régulier, mensuel sur
 * 10 mois (mois sautés + activeMonths), annuel, bimestriel, irrégulier, trop ancien,
 * sous-ensemble ±15 %, montant attendu = médiane, fins de mois. Données fictives uniquement.
 */
final class RecurrencePatternTest extends TestCase
{
    private static function today(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-11-05');
    }

    /**
     * @param list<array{string, int}> $pairs [date 'AAAA-MM-JJ', montant en valeur absolue]
     * @return list<array{date: \DateTimeImmutable, amount: int, id: int}>
     */
    private static function ops(array $pairs): array
    {
        $operations = [];
        foreach ($pairs as $i => [$date, $amount]) {
            $operations[] = ['date' => new \DateTimeImmutable($date), 'amount' => $amount, 'id' => $i + 1];
        }

        return $operations;
    }

    public function testMonthlyRegular(): void
    {
        $analysis = RecurrencePattern::analyze(self::ops([
            ['2026-06-05', 90000],
            ['2026-07-05', 90000],
            ['2026-08-05', 91000],
            ['2026-09-05', 90000],
            ['2026-10-05', 90000],
            ['2026-11-03', 90000],
        ]), self::today());

        self::assertNotNull($analysis);
        self::assertSame(RecurrenceFrequency::Monthly, $analysis->frequency);
        self::assertSame(90000, $analysis->expectedAmount); // médiane des 6 derniers montants
        self::assertSame('2026-11-03', $analysis->lastOccurrence->format('Y-m-d'));
        self::assertSame('2026-12-03', $analysis->nextExpected->format('Y-m-d'));
        self::assertNull($analysis->activeMonths); // historique < 12 mois
        self::assertSame([1, 2, 3, 4, 5, 6], $analysis->linkedIds);
        self::assertGreaterThanOrEqual(100, $analysis->amountTolerance);
    }

    public function testMonthlyTenMonthsSkipsSummer(): void
    {
        // Mensualisation sur 10 mois (pas de juillet-août), sept. 2024 → oct. 2026.
        $rows = [];
        foreach (['2024-09', '2024-10', '2024-11', '2024-12', '2025-01', '2025-02', '2025-03', '2025-04', '2025-05', '2025-06',
            '2025-09', '2025-10', '2025-11', '2025-12', '2026-01', '2026-02', '2026-03', '2026-04', '2026-05', '2026-06',
            '2026-09', '2026-10'] as $month) {
            $rows[] = [$month.'-10', 10000];
        }
        $analysis = RecurrencePattern::analyze(self::ops($rows), self::today());

        self::assertNotNull($analysis);
        self::assertSame(RecurrenceFrequency::Monthly, $analysis->frequency);
        self::assertSame([1, 2, 3, 4, 5, 6, 9, 10, 11, 12], $analysis->activeMonths);
        self::assertSame('2026-11-10', $analysis->nextExpected->format('Y-m-d'));
    }

    public function testMonthlyDateShiftIsNotASeasonalGap(): void
    {
        // Prélèvement mensuel décalé : 30/10 puis 02/12 ⇒ novembre vide mais décembre compte 2
        // occurrences : pas de mois inactifs.
        $rows = [];
        foreach (['2025-01', '2025-02', '2025-03', '2025-04', '2025-05', '2025-06', '2025-07', '2025-08', '2025-09', '2025-10'] as $month) {
            $rows[] = [$month.'-28', 10000];
        }
        $rows[] = ['2025-12-02', 10000];
        $rows[] = ['2025-12-29', 10000];
        foreach (['2026-01', '2026-02', '2026-03', '2026-04', '2026-05', '2026-06', '2026-07', '2026-08', '2026-09', '2026-10'] as $month) {
            $rows[] = [$month.'-28', 10000];
        }
        $analysis = RecurrencePattern::analyze(self::ops($rows), self::today());

        self::assertNotNull($analysis);
        self::assertSame(RecurrenceFrequency::Monthly, $analysis->frequency);
        self::assertNull($analysis->activeMonths);
    }

    public function testYearlyNeedsThreeOccurrences(): void
    {
        $analysis = RecurrencePattern::analyze(self::ops([
            ['2024-01-15', 118000],
            ['2025-01-15', 120000],
            ['2026-01-15', 125000],
        ]), self::today());

        self::assertNotNull($analysis);
        self::assertSame(RecurrenceFrequency::Yearly, $analysis->frequency);
        self::assertSame(120000, $analysis->expectedAmount); // médiane de [118000, 120000, 125000]
        self::assertSame('2027-01-15', $analysis->nextExpected->format('Y-m-d'));
        self::assertNull($analysis->activeMonths); // seulement weekly/monthly/bimonthly

        // Deux achats à un an d'écart ne suffisent pas.
        self::assertNull(RecurrencePattern::analyze(self::ops([
            ['2025-01-15', 6800],
            ['2026-01-15', 6800],
        ]), self::today()));
    }

    public function testWeeklyWithVariableAmountsIsNotRecurring(): void
    {
        // Courses hebdomadaires aux montants très variables.
        $rows = [];
        $amounts = [4200, 9800, 2500, 13100, 6100, 3300, 11000, 5200];
        $date = new \DateTimeImmutable('2026-09-05');
        foreach ($amounts as $amount) {
            $rows[] = [$date->format('Y-m-d'), $amount];
            $date = $date->modify('+7 days');
        }
        self::assertNull(RecurrencePattern::analyze(self::ops($rows), self::today()));
    }

    public function testBimonthly(): void
    {
        $analysis = RecurrencePattern::analyze(self::ops([
            ['2026-05-01', 30000],
            ['2026-07-01', 30000],
            ['2026-09-01', 30500],
            ['2026-11-01', 30000],
        ]), self::today());

        self::assertNotNull($analysis);
        self::assertSame(RecurrenceFrequency::Bimonthly, $analysis->frequency);
        self::assertSame('2027-01-01', $analysis->nextExpected->format('Y-m-d'));
    }

    public function testIrregularGroceriesAreNotRecurring(): void
    {
        $analysis = RecurrencePattern::analyze(self::ops([
            ['2026-09-02', 4500],
            ['2026-09-05', 3200],
            ['2026-09-17', 8100],
            ['2026-09-22', 2700],
            ['2026-10-12', 6600],
            ['2026-10-14', 1900],
            ['2026-11-02', 5400],
        ]), self::today());

        self::assertNull($analysis);
    }

    public function testTooOldIsNotDetected(): void
    {
        // Dernier prélèvement le 10/01/2026 : trop ancien au 05/11/2026.
        $analysis = RecurrencePattern::analyze(self::ops([
            ['2025-08-10', 50000],
            ['2025-09-10', 50000],
            ['2025-10-10', 50000],
            ['2025-11-10', 50000],
            ['2025-12-10', 50000],
            ['2026-01-10', 50000],
        ]), self::today());

        self::assertNull($analysis);
    }

    public function testSubsetWithin15PercentOfMedian(): void
    {
        // Abonnement mensuel à 9,99 € + achats ponctuels chez le même commerçant.
        $analysis = RecurrencePattern::analyze(self::ops([
            ['2026-06-05', 999],
            ['2026-06-20', 5000],
            ['2026-07-05', 999],
            ['2026-08-03', 3000],
            ['2026-08-05', 999],
            ['2026-09-05', 999],
            ['2026-10-05', 999],
            ['2026-11-03', 999],
        ]), self::today());

        self::assertNotNull($analysis);
        self::assertSame(RecurrenceFrequency::Monthly, $analysis->frequency);
        self::assertSame(999, $analysis->expectedAmount);
        // Seules les opérations à ±15 % de la médiane sont liées.
        self::assertSame([1, 3, 5, 6, 7, 8], $analysis->linkedIds);
    }

    public function testExpectedAmountIsMedianOfLastSix(): void
    {
        $analysis = RecurrencePattern::analyze(self::ops([
            ['2026-06-05', 1000],
            ['2026-07-05', 1100],
            ['2026-08-05', 1200],
            ['2026-09-05', 1050],
            ['2026-10-05', 980],
            ['2026-11-03', 1020],
        ]), self::today());

        self::assertNotNull($analysis);
        self::assertSame(1035, $analysis->expectedAmount); // médiane de [980, 1000, 1020, 1050, 1100, 1200]
        // Tolérance = max(100, round(0,15 × 1035) = 155, round(2 × écart médian 45) = 90).
        self::assertSame(155, $analysis->amountTolerance);
    }

    public function testSameDayOperationsAreMerged(): void
    {
        $analysis = RecurrencePattern::analyze([
            ['date' => new \DateTimeImmutable('2026-08-05'), 'amount' => 3000, 'id' => 1],
            ['date' => new \DateTimeImmutable('2026-08-05'), 'amount' => 2000, 'id' => 2],
            ['date' => new \DateTimeImmutable('2026-09-05'), 'amount' => 5000, 'id' => 3],
            ['date' => new \DateTimeImmutable('2026-10-05'), 'amount' => 5000, 'id' => 4],
            ['date' => new \DateTimeImmutable('2026-11-03'), 'amount' => 5000, 'id' => 5],
        ], self::today());

        self::assertNotNull($analysis);
        self::assertSame(RecurrenceFrequency::Monthly, $analysis->frequency);
        self::assertSame([1, 2, 3, 4, 5], $analysis->linkedIds);
        // 4 occurrences (une par jour fusionnée), montant attendu = médiane des 4 derniers jours.
        self::assertSame(5000, $analysis->expectedAmount);
    }

    public function testMonthEndClamping(): void
    {
        // 31 octobre + 1 mois = 30 novembre (borné au dernier jour du mois).
        $analysis = RecurrencePattern::analyze(self::ops([
            ['2026-07-31', 120000],
            ['2026-08-31', 120000],
            ['2026-09-30', 120000],
            ['2026-10-31', 120000],
        ]), self::today());

        self::assertNotNull($analysis);
        self::assertSame('2026-11-30', $analysis->nextExpected->format('Y-m-d'));

        // Calendrier : le jour de l'ancre est conservé d'un cycle à l'autre.
        self::assertSame('2026-02-28', RecurrenceCalendar::addCycles(new \DateTimeImmutable('2026-01-31'), RecurrenceFrequency::Monthly, 1)->format('Y-m-d'));
        self::assertSame('2026-03-31', RecurrenceCalendar::addCycles(new \DateTimeImmutable('2026-01-31'), RecurrenceFrequency::Monthly, 2)->format('Y-m-d'));
        // Saut de février (hors activeMonths) : 31 janvier → 31 mars.
        self::assertSame('2026-03-31', RecurrenceCalendar::nextAfter(new \DateTimeImmutable('2026-01-31'), RecurrenceFrequency::Monthly, [1, 3])->format('Y-m-d'));
        // Hebdomadaire : +7 jours.
        self::assertSame('2026-11-12', RecurrenceCalendar::addCycles(new \DateTimeImmutable('2026-11-05'), RecurrenceFrequency::Weekly, 1)->format('Y-m-d'));
    }

    public function testWeeklyNeedsSixOccurrences(): void
    {
        // 5 occurrences hebdo insuffisantes.
        $analysis = RecurrencePattern::analyze(self::ops([
            ['2026-10-01', 1500],
            ['2026-10-08', 1500],
            ['2026-10-15', 1500],
            ['2026-10-22', 1500],
            ['2026-10-29', 1500],
        ]), self::today());
        self::assertNull($analysis);

        // 6 occurrences hebdo détectées.
        $analysis = RecurrencePattern::analyze(self::ops([
            ['2026-10-01', 1500],
            ['2026-10-08', 1500],
            ['2026-10-15', 1500],
            ['2026-10-22', 1500],
            ['2026-10-29', 1500],
            ['2026-11-05', 1500],
        ]), self::today());
        self::assertNotNull($analysis);
        self::assertSame(RecurrenceFrequency::Weekly, $analysis->frequency);
        self::assertSame('2026-11-12', $analysis->nextExpected->format('Y-m-d'));
    }
}
