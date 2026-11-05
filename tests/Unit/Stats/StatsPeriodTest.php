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
}
