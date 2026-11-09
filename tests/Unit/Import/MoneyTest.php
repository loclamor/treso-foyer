<?php

declare(strict_types=1);

namespace App\Tests\Unit\Import;

use App\Import\Exception\ParseException;
use App\Import\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function testParseFormatsSimples(): void
    {
        self::assertSame(-2756, Money::parse('-27,56'));
        self::assertSame(235000, Money::parse('2350,00'));
        self::assertSame(0, Money::parse('0,00'));
        self::assertSame(1250, Money::parse('12,5'));
        self::assertSame(5, Money::parse('0,05'));
        self::assertSame(100, Money::parse('1,00'));
    }

    public function testParseMilliers(): void
    {
        self::assertSame(-123456, Money::parse('-1 234,56'));
        self::assertSame(-123456, Money::parse("-1\u{00A0}234,56"));
        self::assertSame(-123456, Money::parse("-1\u{202F}234,56"));
        self::assertSame(123456789, Money::parse('1 234 567,89'));
    }

    #[DataProvider('provideInvalid')]
    public function testParseInvalide(string $invalid): void
    {
        $this->expectException(ParseException::class);
        Money::parse($invalid);
    }

    /** @return iterable<string, array{string}> */
    public static function provideInvalid(): iterable
    {
        foreach (['', 'abc', '1.234,56', '12,345', '--12,00', '1,2,3'] as $i => $invalid) {
            yield 'invalide-'.$i => [$invalid];
        }
    }

    public function testFormat(): void
    {
        self::assertSame("-1\u{00A0}234,56 €", Money::format(-123456));
        self::assertSame("2\u{00A0}350,00 €", Money::format(235000));
        self::assertSame('-73,91 €', Money::format(-7391));
        self::assertSame('0,05 €', Money::format(5));
        self::assertSame('0,00 €', Money::format(0));
    }

    public function testParseFormatARond(): void
    {
        foreach ([-123456, -2756, 235000, 5, 0, 99999999] as $cents) {
            self::assertSame($cents, Money::parse(Money::format($cents)));
        }
    }
}
