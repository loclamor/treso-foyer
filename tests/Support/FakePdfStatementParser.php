<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Enum\ImportFormat;
use App\Import\Model\ParsedStatement;
use App\Import\Parser\CsvStatementParser;
use App\Import\Parser\StatementParserInterface;

/**
 * Parseur de test : lit un fichier *.fakepdf écrit au format CSV et le présente comme un relevé PDF.
 * Permet de tester la consolidation PDF > CSV sans dépendre du parseur PDF (étape 5).
 */
final class FakePdfStatementParser implements StatementParserInterface
{
    public function __construct(private readonly CsvStatementParser $csvParser)
    {
    }

    public function supports(string $path, string $content): bool
    {
        return str_ends_with($path, '.fakepdf');
    }

    public function parse(string $path): ParsedStatement
    {
        $csv = $this->csvParser->parse($path);

        return new ParsedStatement(
            $csv->accountNumber,
            ImportFormat::Pdf,
            $csv->openingDate,
            $csv->openingBalance,
            $csv->closingDate,
            $csv->closingBalance,
            $csv->operations,
        );
    }
}
