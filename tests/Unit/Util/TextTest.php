<?php

declare(strict_types=1);

namespace App\Tests\Unit\Util;

use App\Util\Text;
use PHPUnit\Framework\TestCase;

final class TextTest extends TestCase
{
    public function testFoldUppercasesAndRemovesAccents(): void
    {
        self::assertSame('CAFE CREME BRULEE A GOGO', Text::fold('Café crème brûlée à gogo'));
        self::assertSame('ETUDE', Text::fold('étude'));
        self::assertSame('GARCON', Text::fold('garçon'));
    }

    public function testFoldCollapsesWhitespaceAndTrims(): void
    {
        self::assertSame('A B C', Text::fold("  a\t\n b   c  "));
    }

    public function testFoldTransliteratesLigatures(): void
    {
        self::assertSame('OEUF BROUILLE', Text::fold('Œuf brouillé'));
    }

    public function testFoldOnEmptyOrBlankString(): void
    {
        self::assertSame('', Text::fold(''));
        self::assertSame('', Text::fold("   \n "));
    }
}
