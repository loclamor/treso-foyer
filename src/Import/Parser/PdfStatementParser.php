<?php

declare(strict_types=1);

namespace App\Import\Parser;

use App\Enum\ImportFormat;
use App\Import\Exception\NotAnAccountStatementException;
use App\Import\Exception\ParseException;
use App\Import\Model\ParsedOperation;
use App\Import\Model\ParsedStatement;
use App\Import\Money;
use App\Import\Pdf\PdfTextExtractorInterface;

/**
 * Parseur des relevés PDF La Banque Postale (texte extrait par `pdftotext -layout`, voir
 * docs/adr/0003-import-pdf-pdftotext.md).
 *
 * Format : bloc « Situation de votre CCP n° … » (ou IBAN en repli), puis par page une
 * en-tête « Date  Opération  Débit (¤)  Crédit (¤) » dont les positions de colonnes
 * varient d'une page à l'autre, des lignes d'opération « JJ/MM  libellé  montant » et
 * des lignes de continuation sans date. Le signe d'un montant se déduit de la colonne
 * (Débit/Crédit de l'en-tête de la page courante) la plus proche de la fin du montant.
 *
 * Contrôles de cohérence obligatoires : ancien solde + Σ opérations = nouveau solde, et
 * totaux calculés = ligne « Total des opérations » (si présente) ; sinon ParseException.
 * Les messages d'erreur ne contiennent jamais de libellé d'opération ni de numéro de compte.
 */
final class PdfStatementParser implements StatementParserInterface
{
    /** Montant en fin de ligne : `2 350,00` (espace, espace insécable ou espace fine comme séparateur de milliers). */
    private const MONTANT = "\d{1,3}(?:[ \u{00A0}\u{202F}]\d{3})*,\d{2}";

    public function __construct(private readonly PdfTextExtractorInterface $extractor)
    {
    }

    public function supports(string $path, string $content): bool
    {
        return str_starts_with($content, '%PDF');
    }

    public function parse(string $path): ParsedStatement
    {
        return $this->parseText($this->extractor->extract($path));
    }

    /**
     * Parse le texte (sortie `pdftotext -layout`) d'un relevé.
     *
     * @throws NotAnAccountStatementException si le document n'a pas d'en-tête d'opérations (ex. relevé annuel de frais)
     * @throws ParseException si le relevé est illisible ou incohérent
     */
    public function parseText(string $text): ParsedStatement
    {
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $text));

        // Un relevé mensuel peut contenir en annexe le décompte annuel des frais : seul compte l'en-tête d'opérations.
        if (!$this->hasOperationsHeader($lines)) {
            throw new NotAnAccountStatementException('Document sans en-tête d’opérations de relevé de compte (relevé annuel de frais ?).');
        }

        // Date de clôture (pré-scan) : elle fixe l'année des opérations « JJ/MM ».
        $closingDate = $this->scanClosingDate($lines);
        if (null === $closingDate) {
            throw new ParseException('En-tête incomplet : « Nouveau solde au JJ/MM/AAAA » manquant.');
        }

        $accountNumber = null;
        $openingDate = null;
        $openingBalance = null;
        $closingBalance = null;
        $totalDebits = null;
        $totalCredits = null;
        $debitEnd = null;
        $creditEnd = null;
        $inOperations = false;
        /** @var list<array{day: int, month: int, amount: int, label: list<string>}> $rows */
        $rows = [];
        /** @var array{day: int, month: int, amount: int, label: list<string>}|null $current */
        $current = null;
        // Clôt l'opération en cours (la pousse dans la liste).
        $close = function () use (&$current, &$rows): void {
            if (null !== $current) {
                $rows[] = $current;
                $current = null;
            }
        };

        foreach ($lines as $lineNo => $line) {
            $trimmed = trim($line);

            // Ligne vide : clôt l'opération en cours (la zone continue).
            if ('' === $trimmed) {
                $close();
                continue;
            }
            // Pied de page : termine la zone d'opérations jusqu'au prochain en-tête.
            if (preg_match('/\bPage \d+\/\d+\b/', $line) || str_starts_with($trimmed, 'La Banque Postale - S.A.')) {
                $close();
                $inOperations = false;
                continue;
            }
            // Début d'une page d'opérations (« Vos opérations » ou « … (suite) »).
            if (str_starts_with($trimmed, 'Vos opérations')) {
                $close();
                $inOperations = true;
                if (null === $accountNumber && preg_match('/n°\s*([0-9A-Z ]+?)\s*(?:\(suite\))?\s*$/u', $trimmed, $m)) {
                    $accountNumber = $this->normalizeAccountNumber($m[1]);
                }
                continue;
            }
            // En-tête de page : positions des colonnes Débit/Crédit (variables d'une page à l'autre).
            if (str_contains($line, 'Débit (¤)') && str_contains($line, 'Crédit (¤)')) {
                $debitEnd = (int) strpos($line, 'Débit (¤)') + strlen('Débit (¤)');
                $creditEnd = (int) strpos($line, 'Crédit (¤)') + strlen('Crédit (¤)');
                $inOperations = true;
                continue;
            }
            // Numéro de compte : ligne « Situation » ou IBAN (repli : caractères 14 à 24 inclus).
            if (preg_match('/^Situation de votre (?:CCP|Compte Courant Postal) n°\s*(.+?)\s*$/u', $trimmed, $m)) {
                $accountNumber ??= $this->normalizeAccountNumber($m[1]);
                continue;
            }
            if (null === $accountNumber && preg_match('/^IBAN\s*:\s*([A-Z0-9 ]+)/u', $trimmed, $m)) {
                $iban = str_replace(' ', '', strtoupper($m[1]));
                if (strlen($iban) >= 25) {
                    $accountNumber = substr($iban, 14, 11);
                }
                continue;
            }
            if (preg_match('/^Ancien solde au (\d{2})\/(\d{2})\/(\d{4})/u', $trimmed, $m)) {
                $close();
                $openingDate = $this->makeDate((int) $m[1], (int) $m[2], (int) $m[3], $lineNo);
                $openingBalance = $this->parseTrailingAmount($line, $debitEnd, $creditEnd);
                if (null === $openingBalance) {
                    throw new ParseException(sprintf('Ligne %d : solde d’ouverture illisible.', $lineNo + 1));
                }
                continue;
            }
            if (str_starts_with($trimmed, 'Total des opérations')) {
                $close();
                // « Total des opérations   <total débits>   <total crédits> » : ordre fixe.
                if (preg_match_all('/'.self::MONTANT.'/u', $line, $mm)) {
                    $totalDebits = Money::parse($mm[0][0]);
                    $totalCredits = isset($mm[0][1]) ? Money::parse($mm[0][1]) : null;
                }
                continue;
            }
            if (preg_match('/^Nouveau solde au (\d{2})\/(\d{2})\/(\d{4})/u', $trimmed, $m)) {
                $close();
                $inOperations = false;
                $closingBalance = $this->parseTrailingAmount($line, $debitEnd, $creditEnd);
                if (null === $closingBalance) {
                    throw new ParseException(sprintf('Ligne %d : solde de clôture illisible.', $lineNo + 1));
                }
                continue;
            }
            // Ligne d'opération : JJ/MM + libellé + montant en fin de ligne.
            if ($inOperations && null !== $debitEnd
                && preg_match('/^\s*(\d{2})\/(\d{2})\s+(.+)\s{2,}('.self::MONTANT.')\s*$/u', $line, $m, PREG_OFFSET_CAPTURE)
            ) {
                $close(); // clôt l'opération précédente
                $amount = Money::parse($m[4][0]);
                $montantEnd = $m[4][1] + strlen($m[4][0]);
                // Débit ou crédit selon la colonne la plus proche de la fin du montant.
                if (abs($montantEnd - $debitEnd) <= abs($montantEnd - (int) $creditEnd)) {
                    $amount = -abs($amount);
                }
                // Artefact PDF : renvoi de note collé (ex. « 4FRAIS … ») retiré du début du libellé.
                $label = preg_replace('/^\d(?=[A-Z]{4})/u', '', trim($m[3][0])) ?? '';
                $current = ['day' => (int) $m[1][0], 'month' => (int) $m[2][0], 'amount' => $amount, 'label' => [$label]];
                continue;
            }
            // Ligne de continuation (sans date) : ajoutée au libellé de l'opération en cours.
            if ($inOperations && null !== $current) {
                $current['label'][] = $trimmed;
                continue;
            }
            // Sinon : bloc adresse, mentions légales isolées, etc. → ignoré.
        }
        $close();

        if (null === $accountNumber || '' === $accountNumber) {
            throw new ParseException('En-tête incomplet : numéro de compte manquant.');
        }
        if (null === $openingDate || null === $openingBalance || null === $closingBalance) {
            throw new ParseException('En-tête incomplet : solde d’ouverture ou de clôture manquant.');
        }

        // Année des opérations « JJ/MM » : année du nouveau solde, −1 si le mois est supérieur
        // (relevé de janvier contenant des opérations de décembre).
        $closingYear = (int) $closingDate->format('Y');
        $closingMonth = (int) $closingDate->format('n');
        $operations = [];
        foreach ($rows as $row) {
            $year = $row['month'] > $closingMonth ? $closingYear - 1 : $closingYear;
            $operations[] = new ParsedOperation(
                $this->makeDate($row['day'], $row['month'], $year, 0),
                $row['amount'],
                implode(' ', $row['label']),
            );
        }
        // Ordre chronologique croissant, stable (l'ordre du relevé est conservé pour une même date).
        usort($operations, static fn (ParsedOperation $a, ParsedOperation $b): int => $a->date <=> $b->date);

        // Contrôle 1 : ancien solde + Σ opérations = nouveau solde.
        $sum = array_sum(array_map(static fn (ParsedOperation $op): int => $op->amount, $operations));
        if ($openingBalance + $sum !== $closingBalance) {
            throw new ParseException(sprintf(
                'Relevé incohérent : écart de %s entre l’ancien solde + les opérations et le nouveau solde.',
                Money::format(abs($closingBalance - ($openingBalance + $sum))),
            ));
        }
        // Contrôle 2 : totaux calculés = ligne « Total des opérations » (si présente).
        if (null !== $totalDebits) {
            $debits = 0;
            $credits = 0;
            foreach ($operations as $operation) {
                if ($operation->amount < 0) {
                    $debits += -$operation->amount;
                } else {
                    $credits += $operation->amount;
                }
            }
            if ($debits !== $totalDebits) {
                throw new ParseException(sprintf(
                    'Relevé incohérent : écart de %s sur le total des débits.',
                    Money::format(abs($debits - $totalDebits)),
                ));
            }
            if (null !== $totalCredits && $credits !== $totalCredits) {
                throw new ParseException(sprintf(
                    'Relevé incohérent : écart de %s sur le total des crédits.',
                    Money::format(abs($credits - $totalCredits)),
                ));
            }
        }

        return new ParsedStatement(
            accountNumber: $accountNumber,
            format: ImportFormat::Pdf,
            openingDate: $openingDate,
            openingBalance: $openingBalance,
            closingDate: $closingDate,
            closingBalance: $closingBalance,
            operations: $operations,
        );
    }

    /** Le texte contient-il au moins un en-tête « Vos opérations » ? */
    private function hasOperationsHeader(array $lines): bool
    {
        foreach ($lines as $line) {
            if (str_starts_with(trim($line), 'Vos opérations')) {
                return true;
            }
        }

        return false;
    }

    /** Date de clôture du relevé (« Nouveau solde au JJ/MM/AAAA », repli « Votre nouveau solde au … »). */
    private function scanClosingDate(array $lines): ?\DateTimeImmutable
    {
        foreach (['/^Nouveau solde au (\d{2})\/(\d{2})\/(\d{4})/u', '/^Votre nouveau solde au (\d{2})\/(\d{2})\/(\d{4})/u'] as $pattern) {
            foreach ($lines as $line) {
                if (preg_match($pattern, trim($line), $m)) {
                    return $this->makeDate((int) $m[1], (int) $m[2], (int) $m[3], 0);
                }
            }
        }

        return null;
    }

    /**
     * Montant en fin de ligne, signé : signe explicite « + »/« - » si présent, sinon colonne
     * (Débit ⇒ négatif) la plus proche de la fin du montant ; positif si en-tête inconnu.
     */
    private function parseTrailingAmount(string $line, ?int $debitEnd, ?int $creditEnd): ?int
    {
        if (!preg_match('/(?<sign>[+-])?\s*(?<montant>'.self::MONTANT.')\s*¤?\s*$/u', $line, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $amount = Money::parse($m['montant'][0]);
        if (isset($m['sign'][0]) && '' !== $m['sign'][0]) {
            return '-' === $m['sign'][0] ? -abs($amount) : $amount;
        }
        if (null === $debitEnd || null === $creditEnd) {
            return $amount;
        }
        $end = $m['montant'][1] + strlen($m['montant'][0]);

        return abs($end - $debitEnd) <= abs($end - $creditEnd) ? -abs($amount) : $amount;
    }

    private function normalizeAccountNumber(string $raw): string
    {
        return strtoupper((string) preg_replace('/\s+/', '', $raw));
    }

    private function makeDate(int $day, int $month, int $year, int $lineNo): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!d/m/Y', sprintf('%02d/%02d/%04d', $day, $month, $year));
        $errors = \DateTimeImmutable::getLastErrors();
        if (false === $date || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new ParseException(sprintf('Ligne %d : date illisible (format JJ/MM/AAAA attendu).', $lineNo + 1));
        }

        return $date;
    }
}
