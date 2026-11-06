<?php

declare(strict_types=1);

namespace App\Tests\Unit\Import;

use App\Import\CardDateExtractor;
use PHPUnit\Framework\TestCase;

/**
 * Extraction de la date réelle des libellés CB (« ACHAT CB <COMMERÇANT> JJ.MM.AA … »)
 * et garde-fous (date valide, ≤ date du relevé, écart ≤ 31 jours).
 */
final class CardDateExtractorTest extends TestCase
{
    private CardDateExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new CardDateExtractor();
    }

    public function testAchatCb(): void
    {
        $spentAt = $this->extractor->extract('ACHAT CB SUPERMARCHE EXEMPLE 25.08.26 CARTE NUMERO 123');
        self::assertSame('2026-08-25', $spentAt?->format('Y-m-d'));
    }

    public function testAvoirCarte(): void
    {
        $spentAt = $this->extractor->extract('AVOIR CARTE SUPERMARCHE EXEMPLE 01.02.26 CARTE XX 456');
        self::assertSame('2026-02-01', $spentAt?->format('Y-m-d'));
    }

    public function testAutreMotCarte(): void
    {
        $spentAt = $this->extractor->extract('PAIEMENT CARTE BOULANGERIE CHEZ MICHELE 03.04.26');
        self::assertSame('2026-04-03', $spentAt?->format('Y-m-d'));
    }

    public function testAnneeSurDeuxChiffres(): void
    {
        // Année 20AA : 99 → 2099 (cohérent avec le regex JJ.MM.AA de LabelNormalizer).
        $spentAt = $this->extractor->extract('ACHAT CB MAGASIN 31.12.99');
        self::assertSame('2099-12-31', $spentAt?->format('Y-m-d'));
    }

    public function testSansDate(): void
    {
        self::assertNull($this->extractor->extract('PRELEVEMENT DE FOURNISSEUR ENERGIE REF : ABC-0001'));
        self::assertNull($this->extractor->extract('VIREMENT DE EMPLOYEUR FICTIF'));
    }

    public function testDateInvalide(): void
    {
        // 31 février n'existe pas.
        self::assertNull($this->extractor->extract('ACHAT CB MAGASIN 31.02.26'));
        self::assertNull($this->extractor->extract('ACHAT CB MAGASIN 00.08.26'));
    }

    public function testAccentsEtCasse(): void
    {
        $spentAt = $this->extractor->extract('Achat CB Boulangerie Chez Michèle 26.08.26 carte numero 456');
        self::assertSame('2026-08-26', $spentAt?->format('Y-m-d'));
    }

    public function testGuardNull(): void
    {
        self::assertNull($this->extractor->guard(null, new \DateTimeImmutable('2026-08-26')));
    }

    public function testGuardDateUlterieureAuReleve(): void
    {
        // La date réelle ne peut pas dépasser la date du relevé.
        self::assertNull($this->extractor->guard(new \DateTimeImmutable('2026-08-27'), new \DateTimeImmutable('2026-08-26')));
    }

    public function testGuardEcartMax31Jours(): void
    {
        // Écart de 31 jours pile : accepté.
        self::assertSame(
            '2026-07-26',
            $this->extractor->guard(new \DateTimeImmutable('2026-07-26'), new \DateTimeImmutable('2026-08-26'))?->format('Y-m-d'),
        );
        // Écart de 32 jours : refusé.
        self::assertNull($this->extractor->guard(new \DateTimeImmutable('2026-07-25'), new \DateTimeImmutable('2026-08-26')));
    }

    public function testGuardDateEgaleConservée(): void
    {
        // Une date égale à la date du relevé est conservée (sans effet sur la date effective).
        self::assertSame(
            '2026-08-26',
            $this->extractor->guard(new \DateTimeImmutable('2026-08-26'), new \DateTimeImmutable('2026-08-26'))?->format('Y-m-d'),
        );
    }
}
