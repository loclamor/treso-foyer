<?php

declare(strict_types=1);

namespace App\Tests\Unit\Import;

use App\Import\Exception\ParseException;
use App\Import\Exception\UnknownAccountException;
use App\Import\Exception\UnsupportedFormatException;
use App\Import\ImportFailure;
use App\Import\Money;
use PHPUnit\Framework\TestCase;

final class ImportFailureTest extends TestCase
{
    public function testBusinessErrorsKeepTheirMessage(): void
    {
        foreach ([new ParseException('Solde incohérent'), new UnknownAccountException('Compte inconnu'), new UnsupportedFormatException('Format non pris en charge')] as $exception) {
            self::assertTrue(ImportFailure::isExpected($exception));
            self::assertSame($exception->getMessage(), ImportFailure::publicMessage($exception));
        }
    }

    public function testUnexpectedErrorsAreHidden(): void
    {
        $exception = new \RuntimeException('SQLSTATE[23505]: Unique violation … /app/var/data/uploads/abc.pdf');

        self::assertFalse(ImportFailure::isExpected($exception));
        self::assertSame(ImportFailure::GENERIC_MESSAGE, ImportFailure::publicMessage($exception));
    }

    public function testInvalidAmountIsABusinessError(): void
    {
        try {
            Money::parse('12,345');
            self::fail('Montant invalide accepté.');
        } catch (ParseException $exception) {
            self::assertStringContainsString('Montant invalide', ImportFailure::publicMessage($exception));
        }
    }
}
