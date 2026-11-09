<?php

declare(strict_types=1);

namespace App\Tests\Unit\Import;

use App\Enum\ImportFormat;
use App\Import\Exception\ParseException;
use App\Import\Parser\CsvStatementParser;
use PHPUnit\Framework\TestCase;

final class CsvStatementParserTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../../Fixtures/csv';

    private CsvStatementParser $parser;

    protected function setUp(): void
    {
        $this->parser = new CsvStatementParser();
    }

    public function testSupportsCsv(): void
    {
        $path = self::FIXTURES.'/releve-a.csv';
        $content = (string) file_get_contents($path);
        self::assertTrue($this->parser->supports($path, $content));
        self::assertFalse($this->parser->supports('releve.pdf', $content));
        self::assertFalse($this->parser->supports('notes.csv', 'rien à voir ici'));
    }

    public function testParseEnTeteEtSolde(): void
    {
        $statement = $this->parser->parse(self::FIXTURES.'/releve-a.csv');

        self::assertSame('1234567R999', $statement->accountNumber);
        self::assertSame(ImportFormat::Csv, $statement->format);
        self::assertNull($statement->openingDate);
        self::assertNull($statement->openingBalance);
        self::assertSame('2026-08-27', $statement->closingDate?->format('Y-m-d'));
        self::assertSame(-7391, $statement->closingBalance);
    }

    public function testParseOperationsOrdreChronologiqueEtMontants(): void
    {
        $statement = $this->parser->parse(self::FIXTURES.'/releve-a.csv');

        self::assertCount(7, $statement->operations);
        $dates = array_map(static fn ($op) => $op->date->format('Y-m-d'), $statement->operations);
        self::assertSame('2026-08-24', $dates[0]);
        self::assertSame('2026-08-25', $dates[1]);
        self::assertSame('2026-08-26', $dates[2]);
        self::assertSame(['2026-08-24', '2026-08-25', '2026-08-26', '2026-08-26', '2026-08-26', '2026-08-26', '2026-08-26'], $dates);

        // Montants exacts en centimes.
        self::assertSame(235000, $statement->operations[0]->amount);
        self::assertSame(-8510, $statement->operations[1]->amount);
        self::assertSame(-500, $statement->operations[2]->amount);
        self::assertSame(-2756, $statement->operations[5]->amount);
        self::assertSame(-2756, $statement->operations[6]->amount);

        // Ordre relatif des opérations du 26/08 : inversé de façon stable par rapport au fichier.
        self::assertSame('ACHAT CB "SUPER; MARKET" 26.08.26 CARTE NUMERO 321', $statement->operations[2]->rawLabel);
        self::assertSame('ACHAT CB BOULANGERIE CHEZ MICHÈLE 26.08.26 CARTE NUMERO 456', $statement->operations[3]->rawLabel);
        self::assertSame('ACHAT CB BOULANGERIE CHEZ MICHÈLE 26.08.26 CARTE NUMERO 456', $statement->operations[4]->rawLabel);
        self::assertSame('ACHAT CB SUPERMARCHE EXEMPLE 25.08.26 CARTE NUMERO                123', $statement->operations[5]->rawLabel);
        self::assertSame('ACHAT CB SUPERMARCHE EXEMPLE 25.08.26 CARTE NUMERO                123', $statement->operations[6]->rawLabel);
    }

    public function testParseLibelleAvecPointVirguleEtGuillemets(): void
    {
        $statement = $this->parser->parse(self::FIXTURES.'/releve-a.csv');
        $labels = array_map(static fn ($op) => $op->rawLabel, $statement->operations);
        self::assertContains('ACHAT CB "SUPER; MARKET" 26.08.26 CARTE NUMERO 321', $labels);
    }

    public function testParseUtf8DejaValideAvecBomEtLf(): void
    {
        // Contenu déjà UTF-8 valide (avec BOM et fins de ligne LF) : non re-converti.
        $utf8 = "\xEF\xBB\xBF".str_replace("\r\n", "\n", mb_convert_encoding(
            (string) file_get_contents(self::FIXTURES.'/releve-d.csv'),
            'UTF-8',
            'ISO-8859-1',
        ));
        $tmp = tempnam(sys_get_temp_dir(), 'csv_utf8_').'.csv';
        file_put_contents($tmp, $utf8);
        try {
            $statement = $this->parser->parse($tmp);
            self::assertSame('1234567R999', $statement->accountNumber);
            self::assertCount(1, $statement->operations);
            self::assertSame(-2756, $statement->operations[0]->amount);
        } finally {
            unlink($tmp);
        }
    }

    public function testParseFichierInvalide(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessageMatches('/Ligne \d+/');
        $this->parser->parse(self::FIXTURES.'/releve-invalide.csv');
    }

    public function testSoldeIllisibleIndiqueLaLigne(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'csv_bad_').'.csv';
        file_put_contents($tmp, "Numéro Compte   ;1234567R999\nSolde (EUROS)   ;abc\nDate;Libellé;Montant(EUROS)\n");
        try {
            $this->expectException(ParseException::class);
            $this->expectExceptionMessage('Ligne 2 : solde illisible.');
            $this->parser->parse($tmp);
        } finally {
            unlink($tmp);
        }
    }

    public function testParseSansNumeroCompte(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'csv_bad_').'.csv';
        file_put_contents($tmp, "Date;Libellé;Montant(EUROS)\n26/08/2026;\"X\";-1,00\n");
        try {
            $this->expectException(ParseException::class);
            $this->parser->parse($tmp);
        } finally {
            unlink($tmp);
        }
    }
}
