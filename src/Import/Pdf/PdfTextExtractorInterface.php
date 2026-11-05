<?php

declare(strict_types=1);

namespace App\Import\Pdf;

use App\Import\Exception\ParseException;

/**
 * Extrait le texte d'un fichier PDF (mise en page conservée, ex. `pdftotext -layout`).
 * Voir docs/adr/0003-import-pdf-pdftotext.md.
 */
interface PdfTextExtractorInterface
{
    /**
     * @throws ParseException si l'extraction échoue (le message ne contient jamais le contenu du fichier)
     */
    public function extract(string $path): string;
}
