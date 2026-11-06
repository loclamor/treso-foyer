<?php

declare(strict_types=1);

namespace App\Tests\Unit\Recurrence;

use App\Recurrence\TrigramSimilarity;
use PHPUnit\Framework\TestCase;

final class TrigramSimilarityTest extends TestCase
{
    public function testIdenticalAndDisjoint(): void
    {
        self::assertSame(1.0, TrigramSimilarity::similarity('VIR EMPLOYEUR SA', 'vir employeur sa'));
        self::assertSame(0.0, TrigramSimilarity::similarity('ABC', 'XYZ'));
        self::assertSame(0.0, TrigramSimilarity::similarity('', 'XYZ'));
    }

    public function testMatchesPgTrgmDefinition(): void
    {
        // pg_trgm : similarity('word', 'words') = 4 trigrammes communs / 7 distincts = 0.571428…
        self::assertEqualsWithDelta(0.5714, TrigramSimilarity::similarity('word', 'words'), 0.0001);
    }

    public function testCsvAndPdfLabelsOfSameMerchantAreClose(): void
    {
        self::assertGreaterThanOrEqual(0.6, TrigramSimilarity::similarity('ENTREPRISE EXEMPLE DE LA REGION', 'ENTREPRISE EXEMPLE DE L REGION'));
    }
}
