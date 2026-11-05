<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Import\Exception\ParseException;
use App\Import\Pdf\PdfTextExtractorInterface;

/**
 * Extracteur de test : renvoie un contenu texte fixé par nom de fichier, sans appeler pdftotext.
 * Permet de tester le parseur PDF et l'importeur avec les fixtures de tests/Fixtures/pdf-text/.
 */
final class FakePdfTextExtractor implements PdfTextExtractorInterface
{
    /** @param array<string, string> $textsByBasename contenu texte renvoyé, indexé par nom de fichier */
    public function __construct(private readonly array $textsByBasename)
    {
    }

    public function extract(string $path): string
    {
        $text = $this->textsByBasename[basename($path)] ?? null;
        if (null === $text) {
            throw new ParseException(sprintf('Extracteur de test : aucun texte associé à « %s ».', basename($path)));
        }

        return $text;
    }
}
