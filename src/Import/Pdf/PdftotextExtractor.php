<?php

declare(strict_types=1);

namespace App\Import\Pdf;

use App\Import\Exception\ParseException;
use Symfony\Component\Process\Process;

/**
 * Extraction du texte d'un PDF texte par `pdftotext -layout -enc UTF-8` (poppler-utils,
 * installé dans l'image php). Le `-layout` conserve la position des colonnes,
 * indispensable pour distinguer débit et crédit (voir docs/adr/0003).
 */
final class PdftotextExtractor implements PdfTextExtractorInterface
{
    private const TIMEOUT_SECONDS = 30;

    public function extract(string $path): string
    {
        $process = new Process(['pdftotext', '-layout', '-enc', 'UTF-8', $path, '-']);
        $process->setTimeout(self::TIMEOUT_SECONDS);
        try {
            $process->run();
        } catch (\Throwable $exception) {
            throw new ParseException(sprintf('Extraction du texte PDF impossible : %s.', basename($path)), 0, $exception);
        }
        if (!$process->isSuccessful()) {
            throw new ParseException(sprintf('Extraction du texte PDF impossible : %s.', basename($path)));
        }
        $text = $process->getOutput();
        if ('' === trim($text)) {
            throw new ParseException(sprintf('Aucun texte extrait du PDF : %s.', basename($path)));
        }

        return $text;
    }
}
