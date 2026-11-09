<?php

declare(strict_types=1);

namespace App\Import\Parser;

use App\Enum\ImportFormat;
use App\Import\Exception\ParseException;
use App\Import\Model\ParsedOperation;
use App\Import\Model\ParsedStatement;
use App\Import\Money;

/**
 * Parseur des exports CSV La Banque Postale (espace client) :
 * encodage ISO-8859-1 (ou UTF-8 déjà valide accepté), fins de ligne CRLF (LF toléré),
 * séparateur `;`, en-tête « clé ; valeur » puis ligne vide puis
 * `Date;Libellé;Montant(EUROS)` et les opérations de la plus récente à la plus ancienne.
 */
final class CsvStatementParser implements StatementParserInterface
{
    private const HEADER_DATA = 'Date;Libellé;Montant(EUROS)';

    public function supports(string $path, string $content): bool
    {
        if (!str_ends_with(mb_strtolower($path), '.csv')) {
            return false;
        }
        $text = $this->toUtf8($content);

        return str_contains($text, 'Numéro Compte') || str_contains($text, 'Solde (EUROS)');
    }

    public function parse(string $path): ParsedStatement
    {
        $raw = @file_get_contents($path);
        if (false === $raw) {
            throw new ParseException(sprintf('Fichier illisible : %s.', basename($path)));
        }
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $this->toUtf8($raw)));

        $accountNumber = null;
        $closingDate = null;
        $closingBalance = null;

        $lineNo = 0;
        $count = count($lines);
        // En-tête « clé ; valeur » jusqu'à la première ligne vide.
        while ($lineNo < $count) {
            $lineNo++;
            $line = trim($lines[$lineNo - 1]);
            if ('' === $line) {
                break;
            }
            $parts = str_getcsv($line, ';', '"', '');
            if (count($parts) < 2) {
                throw new ParseException(sprintf('Ligne %d : ligne d’en-tête « clé ; valeur » attendue.', $lineNo));
            }
            $key = trim($parts[0]);
            $value = trim($parts[1]);
            switch ($key) {
                case 'Numéro Compte':
                    $accountNumber = $value;
                    break;
                case 'Date':
                    $closingDate = $this->parseDate($value, $lineNo);
                    break;
                case 'Solde (EUROS)':
                    try {
                        $closingBalance = Money::parse($value);
                    } catch (ParseException) {
                        throw new ParseException(sprintf('Ligne %d : solde illisible.', $lineNo));
                    }
                    break;
                default:
                    // Type, Compte tenu en, etc. : ignorés.
            }
        }

        if (null === $accountNumber || '' === $accountNumber) {
            throw new ParseException('En-tête incomplet : « Numéro Compte » manquant.');
        }

        // Ligne vide puis en-tête de données.
        while ($lineNo < $count && '' === trim($lines[$lineNo])) {
            $lineNo++;
        }
        if ($lineNo >= $count) {
            throw new ParseException(sprintf('En-tête de données « %s » manquante.', self::HEADER_DATA));
        }
        $lineNo++;
        $header = str_getcsv(trim($lines[$lineNo - 1]), ';', '"', '');
        if (3 !== count($header) || !str_contains($header[0], 'Date') || !str_contains($header[1], 'Libellé') || !str_contains($header[2], 'Montant')) {
            throw new ParseException(sprintf('Ligne %d : en-tête de données « %s » attendue.', $lineNo, self::HEADER_DATA));
        }

        /** @var list<ParsedOperation> $operations */
        $operations = [];
        for (; $lineNo < $count; $lineNo++) {
            $line = trim($lines[$lineNo]);
            if ('' === $line) {
                continue; // lignes vides finales tolérées
            }
            $rowNo = $lineNo + 1;
            $parts = str_getcsv($lines[$lineNo], ';', '"', '');
            if (3 !== count($parts)) {
                throw new ParseException(sprintf('Ligne %d : 3 colonnes attendues (Date;Libellé;Montant), %d trouvée(s).', $rowNo, count($parts)));
            }
            [$dateRaw, $label, $amountRaw] = $parts;
            $date = $this->parseDate(trim($dateRaw), $rowNo);
            try {
                $amount = Money::parse(trim($amountRaw));
            } catch (\InvalidArgumentException) {
                throw new ParseException(sprintf('Ligne %d : montant illisible.', $rowNo));
            }
            $operations[] = new ParsedOperation($date, $amount, trim($label));
        }

        // Passage en ordre chronologique croissant, de façon stable :
        // on inverse d'abord (le fichier va de la plus récente à la plus ancienne),
        // puis on trie par date croissante (usort est stable depuis PHP 8.0).
        $operations = array_reverse($operations);
        usort($operations, static fn (ParsedOperation $a, ParsedOperation $b): int => $a->date <=> $b->date);

        return new ParsedStatement(
            accountNumber: $accountNumber,
            format: ImportFormat::Csv,
            openingDate: null,
            openingBalance: null,
            closingDate: $closingDate,
            closingBalance: $closingBalance,
            operations: $operations,
        );
    }

    private function parseDate(string $value, int $lineNo): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!d/m/Y', $value);
        $errors = \DateTimeImmutable::getLastErrors();
        if (false === $date || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new ParseException(sprintf('Ligne %d : date illisible (format JJ/MM/AAAA attendu).', $lineNo));
        }

        return $date;
    }

    /**
     * Supprime un éventuel BOM, puis convertit l'ISO-8859-1 en UTF-8
     * (un contenu déjà UTF-8 valide est laissé tel quel).
     */
    private function toUtf8(string $raw): string
    {
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;
        if (mb_check_encoding($raw, 'UTF-8')) {
            return $raw;
        }
        $converted = @iconv('ISO-8859-1', 'UTF-8//IGNORE', $raw);

        return is_string($converted) && '' !== $converted ? $converted : $raw;
    }
}
