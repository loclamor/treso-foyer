<?php

declare(strict_types=1);

namespace App\Tests\Unit\Import\Parser;

use App\Enum\ImportFormat;
use App\Import\Exception\NotAnAccountStatementException;
use App\Import\Exception\ParseException;
use App\Import\Model\ParsedOperation;
use App\Import\Parser\PdfStatementParser;
use App\Tests\Support\FakePdfTextExtractor;
use PHPUnit\Framework\TestCase;

final class PdfStatementParserTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../../../Fixtures/pdf-text';

    private function parser(): PdfStatementParser
    {
        return new PdfStatementParser(new FakePdfTextExtractor([]));
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(self::FIXTURES.'/'.$name);
    }

    public function testSupportsUniquementLesPdf(): void
    {
        $parser = $this->parser();
        self::assertTrue($parser->supports('releve.pdf', "%PDF-1.4\n…"));
        self::assertFalse($parser->supports('releve.pdf', 'PDF fictif de test, aucun parseur ne le prend en charge.'));
    }

    public function testParseReleveJanvier2027(): void
    {
        $statement = $this->parser()->parseText($this->fixture('releve-janvier-2027.txt'));

        self::assertSame('1234567R999', $statement->accountNumber);
        self::assertSame(ImportFormat::Pdf, $statement->format);
        self::assertSame('2026-12-12', $statement->openingDate?->format('Y-m-d'));
        self::assertSame(-7391, $statement->openingBalance);
        self::assertSame('2027-01-12', $statement->closingDate?->format('Y-m-d'));
        self::assertSame(207102, $statement->closingBalance);

        self::assertCount(10, $statement->operations);

        // Ordre chronologique croissant.
        $dates = array_map(static fn (ParsedOperation $op): string => $op->date->format('Y-m-d'), $statement->operations);
        self::assertSame([
            '2026-12-28', '2026-12-30', '2026-12-31', '2027-01-02', '2027-01-02',
            '2027-01-02', '2027-01-05', '2027-01-08', '2027-01-10', '2027-01-12',
        ], $dates);

        [$supermarche, $prelevement, $librairie, $frais, $boulangerie1, $boulangerie2, $salaire, $cheque, $cotisation, $avoir] = $statement->operations;

        // Décembre en 2026, janvier en 2027 ; signes déduits des colonnes.
        self::assertSame(-2756, $supermarche->amount);
        self::assertSame('ACHAT CB SUPERMARCHE EXEMPLE 27.12.26 CARTE NUMERO 123', $supermarche->rawLabel);

        // Libellé multi-lignes recollé par une espace.
        self::assertSame(-8510, $prelevement->amount);
        self::assertSame('PRELEVEMENT DE FOURNISSEUR ENERGIE SA REF : ABC-0000000001 IDENT : FR00ZZZ000000 MANDAT : MDT-FICTIF-42', $prelevement->rawLabel);

        // Ligne « USD 12,34 CARTE NUMERO 123 » rattachée à l'achat du 31/12.
        self::assertSame('2026-12-31', $librairie->date->format('Y-m-d'));
        self::assertSame(-1150, $librairie->amount);
        self::assertSame("ACHAT CB LIBRAIRIE DU MONDE ENTIER ET D'AILLEURS 30.12.26 USD 12,34 CARTE NUMERO 123", $librairie->rawLabel);

        // Renvoi de note « 4 » retiré du début du libellé.
        self::assertSame('FRAIS TENUE DE COMPTE', $frais->rawLabel);
        self::assertSame(-300, $frais->amount);
        self::assertSame('COTISATION TRIMESTRIELLE DE VOTRE FORMULE DE COMPTE', $cotisation->rawLabel);
        self::assertSame(-1890, $cotisation->amount);

        // Les deux achats identiques du 02/01 sont présents deux fois.
        self::assertSame('2027-01-02', $boulangerie1->date->format('Y-m-d'));
        self::assertSame(-1200, $boulangerie1->amount);
        self::assertSame('ACHAT CB BOULANGERIE 02.01.27 CARTE NUMERO 123', $boulangerie1->rawLabel);
        self::assertSame($boulangerie1->rawLabel, $boulangerie2->rawLabel);
        self::assertSame($boulangerie1->amount, $boulangerie2->amount);

        // Salaires et avoir positifs (colonne Crédit).
        self::assertSame('2027-01-05', $salaire->date->format('Y-m-d'));
        self::assertSame(235000, $salaire->amount);
        self::assertSame('VIREMENT DE EMPLOYEUR FICTIF SA SALAIRE DECEMBRE REFERENCE : 0000000000000000', $salaire->rawLabel);
        self::assertSame('2027-01-12', $avoir->date->format('Y-m-d'));
        self::assertSame(999, $avoir->amount);
        self::assertSame('AVOIR CARTE SUPERMARCHE EXEMPLE 11.01.27 CARTE NUMERO 123', $avoir->rawLabel);

        self::assertSame('CHEQUE N° 1234567', $cheque->rawLabel);
        self::assertSame(-4500, $cheque->amount);

        // Aucune mention légale dans un libellé.
        foreach ($statement->operations as $operation) {
            self::assertStringNotContainsString('S.A.', $operation->rawLabel);
            self::assertStringNotContainsString('RCS', $operation->rawLabel);
            self::assertStringNotContainsString('Page', $operation->rawLabel);
        }
    }

    public function testParseReleveIncoherent(): void
    {
        try {
            $this->parser()->parseText($this->fixture('releve-incoherent.txt'));
            self::fail('Une ParseException était attendue.');
        } catch (ParseException $exception) {
            self::assertStringContainsString('Relevé incohérent', $exception->getMessage());
            self::assertStringContainsString('070,02', $exception->getMessage());
            // Jamais de libellé d'opération ni de numéro de compte en clair.
            self::assertStringNotContainsString('1234567', $exception->getMessage());
            self::assertStringNotContainsString('SUPERMARCHE', $exception->getMessage());
        }
    }

    public function testParseReleveIbanSeul(): void
    {
        $statement = $this->parser()->parseText($this->fixture('releve-iban-seul.txt'));

        // Numéro de compte déduit de l'IBAN (caractères 14 à 24 inclus, sans espaces).
        self::assertSame('1234567R999', $statement->accountNumber);
        self::assertSame(50000, $statement->openingBalance);
        self::assertSame(273734, $statement->closingBalance);
        self::assertCount(3, $statement->operations);
        self::assertSame(235000, $statement->operations[2]->amount);
    }

    public function testSoldeAEgaleDistanceDesColonnesEstUnCredit(): void
    {
        // Régression (relevé réel de septembre 2018, compte commun) : le montant de l'ancien solde finit
        // pile entre les colonnes Débit et Crédit ; les débits finissent bien avant la colonne Débit,
        // l'égalité est donc un crédit (solde positif).
        $statement = $this->parser()->parseText($this->fixture('releve-solde-equidistant.txt'));

        self::assertSame(15108, $statement->openingBalance);
        self::assertSame(90383, $statement->closingBalance);
        self::assertSame([77500, -2225], array_map(static fn (ParsedOperation $op): int => $op->amount, $statement->operations));
    }

    public function testReleveAnnuelDeFrais(): void
    {
        $this->expectException(NotAnAccountStatementException::class);
        $this->parser()->parseText($this->fixture('releve-annuel-frais.txt'));
    }

    public function testReleveMensuelAvecDecompteDeFraisEnAnnexe(): void
    {
        // Régression (relevé réel de mars 2020) : le décompte annuel des frais peut être annexé au relevé mensuel.
        $statement = $this->parser()->parseText((string) file_get_contents(__DIR__.'/../../../Fixtures/pdf-text/releve-avec-decompte-frais.txt'));
        self::assertCount(10, $statement->operations);
    }
}
