<?php

declare(strict_types=1);

namespace App\Import\Parser;

use App\Enum\AccountType;
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

        // Relevés jusqu'à début 2017 : colonne « Soit en francs » (contre-valeur toujours signée) après
        // la colonne Crédit. On la retire pour que le montant en euros redevienne le dernier de la ligne.
        // Son signe est conservé : il fait foi pour les soldes, dont la colonne est ambiguë.
        /** @var array<int, string> $francSigns n° de ligne => « + » ou « - » */
        $francSigns = [];
        if (str_contains($text, 'Soit en francs')) {
            foreach ($lines as $i => $line) {
                if (preg_match('/\s{2,}([+-])\s?'.self::MONTANT.'\s*$/u', $line, $fm, PREG_OFFSET_CAPTURE)) {
                    $francSigns[$i] = $fm[1][0];
                    $lines[$i] = substr($line, 0, $fm[0][1]);
                }
            }
        }

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
        /** @var array<string, array{label: string, balance: int}> $savingsBalances n° de compte d'épargne => solde de la synthèse */
        $savingsBalances = [];
        $mainEnd = count($lines);
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
            // Synthèse de la page 1 (avant la 1re en-tête de colonnes) : solde de chaque compte d'épargne,
            // ex. « Livret A n° 000 0000000 L    + 9 505,60 ¤ ». Le CCP y figure aussi : on l'exclut.
            if (null === $debitEnd
                && preg_match('/^(?<label>[A-Z][^|]*?)\s+n°\s*(?<num>\d[0-9A-Z ]*?[0-9A-Z])\s+(?<sign>[+-])\s?(?<amount>'.self::MONTANT.')\s*¤?$/u', $trimmed, $m)
                && !preg_match('/^(CCP|Compte Courant)/u', $m['label'])
            ) {
                $number = $this->normalizeAccountNumber($m['num']);
                $amount = Money::parse($m['amount']);
                $savingsBalances[$number] = ['label' => $m['label'], 'balance' => '-' === $m['sign'] ? -$amount : $amount];
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
                if (isset($francSigns[$lineNo])) {
                    $openingBalance = '-' === $francSigns[$lineNo] ? -abs($openingBalance) : abs($openingBalance);
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
                if (isset($francSigns[$lineNo])) {
                    $closingBalance = '-' === $francSigns[$lineNo] ? -abs($closingBalance) : abs($closingBalance);
                }
                // Fin du compte courant : la suite (livrets, « Comptes d'Épargne »…) a ses propres
                // soldes et opérations, lus à part (parseSavings) pour ne pas les mêler au CCP.
                $mainEnd = $lineNo;
                break;
            }
            // Ligne d'opération : JJ/MM + libellé + montant en fin de ligne.
            if ($inOperations && null !== $debitEnd
                && preg_match('/^\s*(\d{2})\/(\d{2})\s+(.+)\s{2,}('.self::MONTANT.')\s*$/u', $line, $m, PREG_OFFSET_CAPTURE)
            ) {
                $close(); // clôt l'opération précédente
                $amount = Money::parse($m[4][0]);
                $montantEnd = $m[4][1] + strlen($m[4][0]);
                // Débit ou crédit selon la colonne la plus proche de la fin du montant.
                if ($this->isDebitColumn($montantEnd, $debitEnd, (int) $creditEnd)) {
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

        $operations = $this->buildOperations($rows, $closingDate);

        // Contrôle 1 : ancien solde + Σ opérations = nouveau solde.
        $this->checkBalance($openingBalance, $operations, $closingBalance, 'Relevé incohérent');
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
            related: $this->parseSavings(array_slice($lines, $mainEnd + 1, null, true), $francSigns, $savingsBalances, $openingDate, $closingDate),
        );
    }

    /**
     * Comptes d'épargne du relevé (livrets…) : un relevé par compte listé dans la synthèse.
     * Section « Comptes d'Épargne » présente (mois avec mouvements) : soldes et opérations de la section ;
     * sinon, relevé sans opération dont les soldes d'ouverture et de clôture valent le solde de la synthèse.
     *
     * @param array<int, string>                                $lines           lignes après le nouveau solde du CCP (clés = n° de ligne)
     * @param array<int, string>                                $francSigns
     * @param array<string, array{label: string, balance: int}> $savingsBalances
     *
     * @return list<ParsedStatement>
     */
    private function parseSavings(array $lines, array $francSigns, array $savingsBalances, \DateTimeImmutable $openingDate, \DateTimeImmutable $closingDate): array
    {
        /** @var array<string, array{opening: ?int, openingDate: ?\DateTimeImmutable, closing: ?int, rows: list<array{day: int, month: int, amount: int, label: list<string>}>}> $sections */
        $sections = [];
        $number = null;
        $debitEnd = null;
        $creditEnd = null;
        $current = null;
        $close = function () use (&$current, &$sections, &$number): void {
            if (null !== $current && null !== $number) {
                $sections[$number]['rows'][] = $current;
            }
            $current = null;
        };

        foreach ($lines as $lineNo => $line) {
            $trimmed = trim($line);
            if ('' === $trimmed || preg_match('/\bPage \d+\/\d+\b/', $line)) {
                $close();
                continue;
            }
            if (str_contains($line, 'Débit (¤)') && str_contains($line, 'Crédit (¤)')) {
                $close();
                $debitEnd = (int) strpos($line, 'Débit (¤)') + strlen('Débit (¤)');
                $creditEnd = (int) strpos($line, 'Crédit (¤)') + strlen('Crédit (¤)');
                continue;
            }
            // En-tête de section : « Livret A n° 000 0000000 L » (seulement un compte de la synthèse).
            if (preg_match('/^.+?\s+n°\s*(\d[0-9A-Z ]*?[0-9A-Z])\s*$/u', $trimmed, $m)
                && isset($savingsBalances[$this->normalizeAccountNumber($m[1])])
            ) {
                $close();
                $number = $this->normalizeAccountNumber($m[1]);
                $sections[$number] = ['opening' => null, 'openingDate' => null, 'closing' => null, 'rows' => []];
                continue;
            }
            if (null === $number || null === $debitEnd || null !== $sections[$number]['closing']) {
                continue;
            }
            if (preg_match('/^Ancien solde au (\d{2})\/(\d{2})\/(\d{4})/u', $trimmed, $m)) {
                $close();
                $sections[$number]['openingDate'] = $this->makeDate((int) $m[1], (int) $m[2], (int) $m[3], $lineNo);
                $sections[$number]['opening'] = $this->signedBalance($line, $lineNo, $francSigns, $debitEnd, (int) $creditEnd);
                continue;
            }
            if (preg_match('/^Nouveau solde au /u', $trimmed)) {
                $close();
                $sections[$number]['closing'] = $this->signedBalance($line, $lineNo, $francSigns, $debitEnd, (int) $creditEnd);
                continue;
            }
            if (preg_match('/^\s*(\d{2})\/(\d{2})\s+(.+)\s{2,}('.self::MONTANT.')\s*$/u', $line, $m, PREG_OFFSET_CAPTURE)) {
                $close();
                $amount = Money::parse($m[4][0]);
                if ($this->isDebitColumn($m[4][1] + strlen($m[4][0]), $debitEnd, (int) $creditEnd)) {
                    $amount = -abs($amount);
                }
                $current = ['day' => (int) $m[1][0], 'month' => (int) $m[2][0], 'amount' => $amount, 'label' => [trim($m[3][0])]];
                continue;
            }
            if (null !== $current) {
                $current['label'][] = $trimmed;
            }
        }
        $close();

        $statements = [];
        foreach ($savingsBalances as $savingsNumber => ['label' => $label, 'balance' => $balance]) {
            $section = $sections[$savingsNumber] ?? null;
            if (null === $section || null === $section['opening'] || null === $section['closing']) {
                $statements[] = new ParsedStatement($savingsNumber, ImportFormat::Pdf, $openingDate, $balance, $closingDate, $balance, [], accountLabel: $label, accountType: AccountType::Savings);
                continue;
            }
            $operations = $this->buildOperations($section['rows'], $closingDate);
            $this->checkBalance($section['opening'], $operations, $section['closing'], 'Compte d’épargne incohérent');
            if ($section['closing'] !== $balance) {
                throw new ParseException('Compte d’épargne incohérent : nouveau solde différent de celui de la synthèse.');
            }
            $statements[] = new ParsedStatement(
                $savingsNumber,
                ImportFormat::Pdf,
                $section['openingDate'],
                $section['opening'],
                $closingDate,
                $section['closing'],
                $operations,
                accountLabel: $label,
                accountType: AccountType::Savings,
            );
        }

        return $statements;
    }

    /** Solde en fin de ligne : signe de la colonne francs si présente, sinon colonne Débit/Crédit. */
    private function signedBalance(string $line, int $lineNo, array $francSigns, int $debitEnd, int $creditEnd): int
    {
        $balance = $this->parseTrailingAmount($line, $debitEnd, $creditEnd);
        if (null === $balance) {
            throw new ParseException(sprintf('Ligne %d : solde illisible.', $lineNo + 1));
        }
        if (isset($francSigns[$lineNo])) {
            return '-' === $francSigns[$lineNo] ? -abs($balance) : abs($balance);
        }

        return $balance;
    }

    /**
     * Opérations datées, en ordre chronologique croissant (stable pour une même date).
     * Année des « JJ/MM » : année du nouveau solde, −1 si le mois est supérieur (relevé de janvier
     * contenant des opérations de décembre).
     *
     * @param list<array{day: int, month: int, amount: int, label: list<string>}> $rows
     *
     * @return list<ParsedOperation>
     */
    private function buildOperations(array $rows, \DateTimeImmutable $closingDate): array
    {
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
        usort($operations, static fn (ParsedOperation $a, ParsedOperation $b): int => $a->date <=> $b->date);

        return $operations;
    }

    /**
     * Ancien solde + Σ opérations = nouveau solde, sinon ParseException.
     *
     * @param list<ParsedOperation> $operations
     */
    private function checkBalance(int $opening, array $operations, int $closing, string $prefix): void
    {
        $sum = array_sum(array_map(static fn (ParsedOperation $op): int => $op->amount, $operations));
        if ($opening + $sum !== $closing) {
            throw new ParseException(sprintf(
                '%s : écart de %s entre l’ancien solde + les opérations et le nouveau solde.',
                $prefix,
                Money::format(abs($closing - ($opening + $sum))),
            ));
        }
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

        return $this->isDebitColumn($end, $debitEnd, $creditEnd) ? -abs($amount) : $amount;
    }

    /**
     * Débit si la fin du montant est strictement plus proche de la fin de l'en-tête « Débit (¤) ».
     * À égale distance : crédit (les débits finissent quelques caractères avant la colonne Débit,
     * les crédits dans l'entre-deux ; ex. ancien solde positif du relevé réel de septembre 2018).
     */
    private function isDebitColumn(int $amountEnd, int $debitEnd, int $creditEnd): bool
    {
        return abs($amountEnd - $debitEnd) < abs($amountEnd - $creditEnd);
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
