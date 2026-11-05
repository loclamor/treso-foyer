<?php

declare(strict_types=1);

namespace App\Tests\Unit\Import;

use App\Import\LabelNormalizer;
use PHPUnit\Framework\TestCase;

final class LabelNormalizerTest extends TestCase
{
    private LabelNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new LabelNormalizer();
    }

    public function testAchatCb(): void
    {
        $label = $this->normalizer->normalize('ACHAT CB SUPERMARCHE EXEMPLE 25.08.26 CARTE NUMERO                123  ');
        self::assertSame('ACHAT CB SUPERMARCHE EXEMPLE', $label->normalized);
        self::assertSame('SUPERMARCHE EXEMPLE', $label->merchant);
    }

    public function testAvoirCarte(): void
    {
        $label = $this->normalizer->normalize('AVOIR CARTE LEROY MERLIN 01.02.26 CARTE NUMERO 9');
        self::assertSame('AVOIR CARTE LEROY MERLIN', $label->normalized);
        self::assertSame('LEROY MERLIN', $label->merchant);
    }

    public function testPrelevement(): void
    {
        $label = $this->normalizer->normalize('PRELEVEMENT DE FOURNISSEUR ENERGIE REF : ABC-0000000001 IDENT : FR00ZZZ000000 ');
        self::assertSame('PRELEVEMENT DE FOURNISSEUR ENERGIE', $label->normalized);
        self::assertSame('FOURNISSEUR ENERGIE', $label->merchant);
    }

    public function testVirementDe(): void
    {
        $label = $this->normalizer->normalize('VIREMENT DE EMPLOYEUR FICTIF SA SALAIRE AOUT REFERENCE : 0000000000000000');
        self::assertSame('VIREMENT DE EMPLOYEUR FICTIF SA SALAIRE AOUT', $label->normalized);
        self::assertSame('EMPLOYEUR FICTIF SA SALAIRE AOUT', $label->merchant);
    }

    public function testVirementPermanentPour(): void
    {
        $label = $this->normalizer->normalize('VIREMENT PERMANENT POUR BENEFICIAIRE FICTIF 123456789');
        self::assertSame('BENEFICIAIRE FICTIF', $label->merchant);
    }

    public function testVirementInstantaneA(): void
    {
        $label = $this->normalizer->normalize('VIREMENT INSTANTANE A SOCIETE FICTIVE');
        self::assertSame('SOCIETE FICTIVE', $label->merchant);
    }

    public function testCheque(): void
    {
        $label = $this->normalizer->normalize('CHEQUE N 1234567');
        self::assertSame('CHEQUE', $label->merchant);
        self::assertSame('CHEQUE N 1234567', $label->normalized);
    }

    public function testRetraitDab(): void
    {
        $label = $this->normalizer->normalize('RETRAIT DAB 26.08.26 123');
        self::assertSame('RETRAIT DAB', $label->merchant);
        self::assertSame('RETRAIT DAB 123', $label->normalized);
    }

    public function testLigneDevise(): void
    {
        $label = $this->normalizer->normalize('USD 12,34 CARTE NUMERO 123');
        self::assertSame('USD 12,34', $label->normalized);
        self::assertSame('USD', $label->merchant);
    }

    public function testDefautTroisMotsSansChiffres(): void
    {
        $label = $this->normalizer->normalize('COTISATION TRIMESTRIELLE DE 2026 ASSURANCE');
        self::assertSame('COTISATION TRIMESTRIELLE DE', $label->merchant);
    }

    public function testKeyIdentiqueChequeCsvEtPdf(): void
    {
        $keyCsv = $this->normalizer->key('CHEQUE N 1234567');
        $keyPdf = $this->normalizer->key("CHEQUE N\u{00B0} 1234567");
        self::assertSame($keyCsv, $keyPdf);
        self::assertSame('CHEQUEN1234567', $keyCsv);
    }

    public function testKeyIdentiqueCarteNumeroDifferentEtEspaces(): void
    {
        $key1 = $this->normalizer->key('ACHAT CB SUPERMARCHE EXEMPLE 25.08.26 CARTE NUMERO 123');
        $key2 = $this->normalizer->key('ACHAT CB   SUPERMARCHE  EXEMPLE 25.08.26 CARTE NUMERO                456');
        self::assertSame($key1, $key2);
    }

    public function testKeyMax24Caracteres(): void
    {
        $key = $this->normalizer->key('VIREMENT DE EMPLOYEUR FICTIF SA SALAIRE AOUT REFERENCE : 0000000000000000 TRES LONG LIBELLE');
        self::assertSame(LabelNormalizer::KEY_LENGTH, strlen($key));
        self::assertSame(24, strlen($key));
        self::assertMatchesRegularExpression('/^[A-Z0-9]+$/', $key);
    }

    public function testKeyCsvTronqueEtPdfComplet(): void
    {
        // Le CSV tronque le nom du commerçant, le PDF donne le libellé complet :
        // la clé à 24 caractères doit être identique (voir docs/adr/0005, mesure 207/207).
        $keyCsv = $this->normalizer->key('ACHAT CB LIBRAIRIE DU MONDE ENT    30.12.26 CARTE NUMERO                123  ');
        $keyPdf = $this->normalizer->key("ACHAT CB LIBRAIRIE DU MONDE ENTIER ET D'AILLEURS 30.12.26 USD 12,34 CARTE NUMERO 123");
        self::assertSame($keyCsv, $keyPdf);
        self::assertSame(LabelNormalizer::KEY_LENGTH, strlen($keyCsv));
        self::assertSame('ACHATCBLIBRAIRIEDUMONDEE', $keyCsv);
    }

    public function testKeyRobusteAUneCoupureDeMotEnFinDeLignePdf(): void
    {
        // pdftotext peut couper un mot entre deux lignes, recollées avec une espace.
        self::assertSame(
            $this->normalizer->key('PRELEVEMENT DE MUTUELLE EXEMPLAIRE REF : 1'),
            $this->normalizer->key('PRELEVEMENT DE MUTUELLE EXEMPL AIRE REF : 1'),
        );
    }

    public function testNormalizedMax255(): void
    {
        $label = $this->normalizer->normalize(str_repeat('A ', 300));
        self::assertLessThanOrEqual(255, mb_strlen($label->normalized));
    }
}
