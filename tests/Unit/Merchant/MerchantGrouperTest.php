<?php

declare(strict_types=1);

namespace App\Tests\Unit\Merchant;

use App\Merchant\MerchantGrouper;
use PHPUnit\Framework\TestCase;

final class MerchantGrouperTest extends TestCase
{
    public function testPunctuationVariantsAreGrouped(): void
    {
        $mapping = MerchantGrouper::groups(['MP*CARREFOUR D' => 10, 'MP CARREFOUR D' => 2, 'NETFLIX.COM' => 30, 'NETFLIX COM' => 1]);

        self::assertSame('MP*CARREFOUR D', $mapping['MP CARREFOUR D']);
        self::assertSame('MP*CARREFOUR D', $mapping['MP*CARREFOUR D']);
        self::assertSame('NETFLIX.COM', $mapping['NETFLIX COM']);
    }

    public function testTruncatedAndCutWordVariantsAreGrouped(): void
    {
        $mapping = MerchantGrouper::groups([
            'ECOLE EXEMPLE SAINTE MARIE' => 20,
            'ECOLE EXEMPLE SAINT' => 5,          // troncature (préfixe compact, ≥ 12 caractères)
            'ENERGIE CLIENTS PARTICUL IERS' => 30,
            'ENERGIE CLIENTS PARTI IERS' => 1,   // mot coupé (similarité ≥ 0.8, même premier mot)
        ]);

        self::assertSame('ECOLE EXEMPLE SAINTE MARIE', $mapping['ECOLE EXEMPLE SAINT']);
        self::assertSame('ENERGIE CLIENTS PARTICUL IERS', $mapping['ENERGIE CLIENTS PARTI IERS']);
    }

    public function testDistinctMerchantsWithCloseLabelsAreNotGrouped(): void
    {
        $mapping = MerchantGrouper::groups([
            'PHARMACIE DE L' => 10,
            'PHARMACIE DU L' => 10,   // autre pharmacie
            'BOULANGER' => 3,
            'BOULANGERIE D' => 40,    // trop court pour un préfixe
            'SUPERMARCHE' => 50,
            'SUPERMARCHE NF' => 20,   // autre magasin, trop court
            'SUPERMARCHE GI' => 20,
            'PHARMACIE DU G' => 5,    // similarité élevée mais libellés courts
            'BANQUE EXEMPLE' => 4,    // préfixe de plusieurs libellés : générique, pas une troncature
            'BANQUE EXEMPLE CREDIT' => 10,
            'BANQUE EXEMPLE ASSURANCES' => 10,
        ]);

        self::assertSame([], $mapping);
    }

    public function testRepresentativeIsMostFrequentThenLongest(): void
    {
        $mapping = MerchantGrouper::groups(['ABONNEMENT EXEMPLE' => 3, 'ABONNEMENT EXEMPLE SAS' => 3, 'ABONNEMENT.EXEMPLE' => 1]);

        self::assertSame('ABONNEMENT EXEMPLE SAS', $mapping['ABONNEMENT EXEMPLE']);
        self::assertSame('ABONNEMENT EXEMPLE SAS', $mapping['ABONNEMENT.EXEMPLE']);
    }
}
