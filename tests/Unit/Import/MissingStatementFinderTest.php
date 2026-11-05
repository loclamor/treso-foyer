<?php

declare(strict_types=1);

namespace App\Tests\Unit\Import;

use App\Import\MissingStatementFinder;
use PHPUnit\Framework\TestCase;

final class MissingStatementFinderTest extends TestCase
{
    private MissingStatementFinder $finder;

    protected function setUp(): void
    {
        $this->finder = new MissingStatementFinder();
    }

    public function testNoStatementMeansNothingToReport(): void
    {
        self::assertSame([], $this->finder->find([], new \DateTimeImmutable('2026-11-05')));
    }

    public function testCompleteSeriesUpToLastAvailableMonthReportsNothing(): void
    {
        // Le 5 novembre, le relevé de novembre n'est pas encore attendu.
        $missing = $this->finder->find(['2026-08', '2026-09', '2026-10'], new \DateTimeImmutable('2026-11-05'));

        self::assertSame([], $missing);
    }

    public function testGapsAreReportedInOrderAcrossYears(): void
    {
        $missing = $this->finder->find(
            ['2019-03', '2018-12', '2019-05', '2019-03', '2019-06'],
            new \DateTimeImmutable('2019-07-01'),
        );

        self::assertSame([
            ['month' => '2019-01', 'reason' => 'gap'],
            ['month' => '2019-02', 'reason' => 'gap'],
            ['month' => '2019-04', 'reason' => 'gap'],
        ], $missing);
    }

    public function testOverdueMonthsStartTheDayTheStatementBecomesAvailable(): void
    {
        $months = ['2026-08'];

        self::assertSame([
            ['month' => '2026-09', 'reason' => 'overdue'],
            ['month' => '2026-10', 'reason' => 'overdue'],
        ], $this->finder->find($months, new \DateTimeImmutable('2026-11-20')));

        self::assertSame([
            ['month' => '2026-09', 'reason' => 'overdue'],
            ['month' => '2026-10', 'reason' => 'overdue'],
            ['month' => '2026-11', 'reason' => 'overdue'],
        ], $this->finder->find($months, new \DateTimeImmutable('2026-11-21')));
    }

    public function testInvalidMonthIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->finder->find(['2026/08'], new \DateTimeImmutable('2026-11-05'));
    }

    public function testClosedAccountExpectsNothingAfterItsClosingMonth(): void
    {
        $today = new \DateTimeImmutable('2026-11-05');

        // Clôturé en janvier 2017 avec un dernier relevé de janvier : rien à réclamer.
        self::assertSame([], $this->finder->find(['2016-11', '2016-12', '2017-01'], $today, new \DateTimeImmutable('2017-01-17')));

        // Les trous avant la clôture restent signalés, ainsi que le relevé du mois de clôture s'il manque.
        self::assertSame([
            ['month' => '2016-12', 'reason' => 'gap'],
            ['month' => '2017-02', 'reason' => 'overdue'],
        ], $this->finder->find(['2016-11', '2017-01'], $today, new \DateTimeImmutable('2017-02-03')));
    }
}
