<?php

declare(strict_types=1);

namespace App\Tests\Unit\Stats;

use App\Api\BadRequestException;
use App\Stats\StatsPeriod;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class StatsPeriodTest extends TestCase
{
    public function testDefaultIs12FullMonthsPlusCurrentMonth(): void
    {
        $period = StatsPeriod::fromRequest(new Request(), new \DateTimeImmutable('2026-11-04'));

        self::assertSame('2025-11-01', $period->from->format('Y-m-d'));
        self::assertSame('2026-11-30', $period->to->format('Y-m-d'));
    }

    public function testExplicitFromTo(): void
    {
        $period = StatsPeriod::fromRequest(new Request(['from' => '2026-01-01', 'to' => '2026-03-31']));

        self::assertSame('2026-01-01', $period->from->format('Y-m-d'));
        self::assertSame('2026-03-31', $period->to->format('Y-m-d'));
    }

    public function testFromAfterToIsRejected(): void
    {
        $this->expectException(BadRequestException::class);
        StatsPeriod::fromRequest(new Request(['from' => '2026-03-31', 'to' => '2026-01-01']));
    }

    public function testInvalidDateFormatIsRejected(): void
    {
        $this->expectException(BadRequestException::class);
        StatsPeriod::fromRequest(new Request(['from' => '01/01/2026']));
    }

    public function testInexistantDateIsRejected(): void
    {
        $this->expectException(BadRequestException::class);
        StatsPeriod::fromRequest(new Request(['from' => '2026-02-30']));
    }

    public function testPreviousIsSameDurationBefore(): void
    {
        $period = new StatsPeriod(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-03-31'));
        $previous = $period->previous();

        self::assertSame('2025-10-03', $previous->from->format('Y-m-d'));
        self::assertSame('2025-12-31', $previous->to->format('Y-m-d'));
        // Même durée inclusive : 90 jours.
        self::assertSame(90, (int) $period->from->diff($period->to)->days + 1);
        self::assertSame(90, (int) $previous->from->diff($previous->to)->days + 1);
    }

    public function testSameLastYear(): void
    {
        $period = new StatsPeriod(new \DateTimeImmutable('2024-02-29'), new \DateTimeImmutable('2024-03-31'));
        $lastYear = $period->sameLastYear();

        self::assertSame('2023-02-28', $lastYear->from->format('Y-m-d'));
        self::assertSame('2023-03-31', $lastYear->to->format('Y-m-d'));
    }

    public function testLast12FullMonthsBeforeTo(): void
    {
        $period = new StatsPeriod(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-03-31'));
        $last12 = $period->last12FullMonths();

        self::assertSame('2025-03-01', $last12->from->format('Y-m-d'));
        self::assertSame('2026-02-28', $last12->to->format('Y-m-d'));
    }

    public function testMonthsIncludesEmptyMonths(): void
    {
        $period = new StatsPeriod(new \DateTimeImmutable('2026-01-15'), new \DateTimeImmutable('2026-03-02'));

        self::assertSame(['2026-01', '2026-02', '2026-03'], $period->months());
    }

    private static function period(string $from, string $to): StatsPeriod
    {
        return new StatsPeriod(new \DateTimeImmutable($from), new \DateTimeImmutable($to));
    }

    private static function assertPeriod(string $from, string $to, StatsPeriod $period): void
    {
        self::assertSame($from, $period->from->format('Y-m-d'));
        self::assertSame($to, $period->to->format('Y-m-d'));
    }

    public function testCompleteMonthsTwelveMonthsPlusCurrentMonth(): void
    {
        $now = new \DateTimeImmutable('2026-11-05');
        $period = self::period('2025-11-01', '2026-11-30');

        self::assertTrue($period->isIncomplete($now));
        self::assertPeriod('2025-11-01', '2026-10-31', $period->completeMonths($now));
        self::assertPeriod('2025-11-01', '2026-10-31', $period->comparable($now));
        // 12 mois calendaires complets (2025-11..2026-10) ⇒ les 12 mois précédents.
        self::assertPeriod('2024-11-01', '2025-10-31', $period->previousAligned($now));
    }

    public function testCompleteMonthsCurrentMonthAloneIsNull(): void
    {
        $now = new \DateTimeImmutable('2026-11-05');
        $period = self::period('2026-11-01', '2026-11-30');

        self::assertTrue($period->isIncomplete($now));
        self::assertNull($period->completeMonths($now));
        // « Mois à date » : [from, min(to, aujourd'hui)].
        self::assertPeriod('2026-11-01', '2026-11-05', $period->comparable($now));
        // Mêmes jours du mois précédent.
        self::assertPeriod('2026-10-01', '2026-10-05', $period->previousAligned($now));
        // sameLastYear s'applique à comparable.
        self::assertPeriod('2025-11-01', '2025-11-05', $period->comparable($now)->sameLastYear());
    }

    public function testCompleteMonthsLastMonthIsComplete(): void
    {
        $now = new \DateTimeImmutable('2026-11-05');
        $period = self::period('2026-10-01', '2026-10-31');

        self::assertFalse($period->isIncomplete($now));
        self::assertPeriod('2026-10-01', '2026-10-31', $period->completeMonths($now));
        self::assertPeriod('2026-10-01', '2026-10-31', $period->comparable($now));
        // 1 mois calendaire complet ⇒ le mois précédent.
        self::assertPeriod('2026-09-01', '2026-09-30', $period->previousAligned($now));
    }

    public function testCompleteMonthsCurrentYear(): void
    {
        $now = new \DateTimeImmutable('2026-11-05');
        $period = self::period('2026-01-01', '2026-12-31');

        self::assertPeriod('2026-01-01', '2026-10-31', $period->completeMonths($now));
        self::assertPeriod('2026-01-01', '2026-10-31', $period->comparable($now));
        // 10 mois calendaires complets (2026-01..2026-10) ⇒ les 10 mois précédents.
        self::assertPeriod('2025-03-01', '2025-12-31', $period->previousAligned($now));
    }

    public function testPreviousAlignedPastCustomPeriodFallsBackToPrevious(): void
    {
        $now = new \DateTimeImmutable('2026-11-05');
        $period = self::period('2026-05-10', '2026-08-20');

        // Période passée complète : comparable = elle-même, non alignée sur des mois.
        self::assertPeriod('2026-05-10', '2026-08-20', $period->comparable($now));
        self::assertPeriod('2026-01-27', '2026-05-09', $period->previousAligned($now));
    }

    public function testPreviousAlignedUnalignedPeriodSpanningCurrentMonth(): void
    {
        $now = new \DateTimeImmutable('2026-11-05');
        $period = self::period('2026-09-15', '2026-11-20');

        self::assertPeriod('2026-09-15', '2026-10-31', $period->completeMonths($now));
        self::assertPeriod('2026-09-15', '2026-10-31', $period->comparable($now));
        // Non alignée (from le 15) ⇒ previous() de comparable (même durée, 47 jours).
        self::assertPeriod('2026-07-30', '2026-09-14', $period->previousAligned($now));
    }

    public function testPreviousAlignedMonthToDateBoundedToPreviousMonthLength(): void
    {
        // 31 mars : février n'a que 28 jours en 2026, j1=29 et j2=31 sont bornés à 28.
        $now = new \DateTimeImmutable('2026-03-31');
        $period = self::period('2026-03-29', '2026-03-31');

        self::assertNull($period->completeMonths($now));
        self::assertPeriod('2026-03-29', '2026-03-31', $period->comparable($now));
        self::assertPeriod('2026-02-28', '2026-02-28', $period->previousAligned($now));
    }

    public function testPreviousAlignedFullCurrentMonthOnLastDay(): void
    {
        // Dernier jour du mois : le mois en cours est « à date » mais couvre un mois complet
        // ⇒ comparaison sur le mois calendaire précédent.
        $now = new \DateTimeImmutable('2026-01-31');
        $period = self::period('2026-01-01', '2026-01-31');

        self::assertNull($period->completeMonths($now));
        self::assertPeriod('2026-01-01', '2026-01-31', $period->comparable($now));
        self::assertPeriod('2025-12-01', '2025-12-31', $period->previousAligned($now));
    }

    public function testNowWithTimeOfDayStillDetectsCurrentMonth(): void
    {
        // « Maintenant » porte une heure (horloge réelle) : une période finissant le 1er du mois
        // en cours reste incomplète, et le « mois à date » s'arrête à la date du jour.
        $now = new \DateTimeImmutable('2026-11-05 20:15:00');

        $period = new StatsPeriod(new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-11-01'));
        self::assertTrue($period->isIncomplete($now));
        self::assertPeriod('2026-10-01', '2026-10-31', $period->completeMonths($now));

        $month = new StatsPeriod(new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2026-11-30'));
        self::assertPeriod('2026-11-01', '2026-11-05', $month->comparable($now));
        self::assertPeriod('2026-10-01', '2026-10-05', $month->previousAligned($now));
    }
}
