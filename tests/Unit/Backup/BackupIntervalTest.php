<?php

declare(strict_types=1);

namespace App\Tests\Unit\Backup;

use App\Backup\BackupInterval;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BackupIntervalTest extends TestCase
{
    public function testPlainSeconds(): void
    {
        self::assertSame(3600, BackupInterval::toSeconds('3600'));
        self::assertSame(1, BackupInterval::toSeconds('1'));
    }

    public function testSuffixedUnits(): void
    {
        self::assertSame(1800, BackupInterval::toSeconds('30m'));
        self::assertSame(43200, BackupInterval::toSeconds('12h'));
        self::assertSame(86400, BackupInterval::toSeconds('1d'));
        self::assertSame(1209600, BackupInterval::toSeconds('2w'));
    }

    public function testWhitespaceIsTrimmed(): void
    {
        self::assertSame(43200, BackupInterval::toSeconds(' 12h '));
    }

    #[DataProvider('invalidIntervals')]
    public function testInvalidValuesThrow(string $invalid): void
    {
        $this->expectException(\InvalidArgumentException::class);
        BackupInterval::toSeconds($invalid);
    }

    /** @return list<array{string}> */
    public static function invalidIntervals(): array
    {
        return array_map(static fn (string $v): array => [$v], ['', 'abc', '1x', '-5', '0', '0d', 'h', '1.5h']);
    }
}
