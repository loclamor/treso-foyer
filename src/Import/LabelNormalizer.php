<?php

declare(strict_types=1);

namespace App\Import;

use App\Import\Model\NormalizedLabel;
use App\Util\Text;

/**
 * Normalise les libellés d'opérations bancaires (voir docs/adr/0005-dedoublonnage.md) :
 * texte replié sans les segments techniques, commerçant extrait selon la forme du libellé,
 * et clé de dédoublonnage stable inter-formats (CSV, PDF).
 */
final class LabelNormalizer
{
    /**
     * Longueur de la clé de dédoublonnage inter-formats (justification mesurée : docs/adr/0005-dedoublonnage.md).
     * Le CSV tronque les noms de commerçants à largeur fixe : sur 207 opérations présentes à la fois
     * en CSV et en PDF, une clé de 40 caractères ne donne que 192 correspondances, 24 caractères
     * en donnent 207/207 (quand les libellés divergent, jamais avant le 28ᵉ caractère).
     */
    public const KEY_LENGTH = 24;

    /**
     * Clé inter-formats : libellé replié, sans « CARTE NUMERO 123 » / « CARTE XX 123 »,
     * réduit aux seuls caractères [A-Z0-9] (espaces et ponctuation retirés : le PDF peut couper
     * un mot en fin de ligne, le CSV aligne avec des espaces), tronquée à KEY_LENGTH caractères.
     * « CHEQUE N 1234567 » (CSV) et « CHEQUE N° 1234567 » (PDF) produisent la même clé, de même
     * qu'un libellé CSV tronqué et sa version PDF complète (mesure : 207/207, cf. ADR 0005).
     */
    public function key(string $rawLabel): string
    {
        $folded = Text::fold(str_replace("\u{00B0}", '', $rawLabel));
        $folded = $this->stripCarte($folded);
        $alnum = preg_replace('/[^A-Z0-9]/', '', $folded) ?? '';

        return substr($alnum, 0, self::KEY_LENGTH);
    }

    public function normalize(string $rawLabel): NormalizedLabel
    {
        $folded = Text::fold($rawLabel);

        $merchant = $this->extractMerchant($folded);

        // Segments techniques « REF : … », « IDENT : … », « REFERENCE : … » et tout ce
        // qui suit un « XXX : » technique (mot en majuscules suivi de « : ») en fin.
        $normalized = preg_replace('/\s*\b(?:REF|REFERENCE|IDENT|MOTIF|MOT|[A-Z0-9]{3,})\s*:.*$/s', '', $folded) ?? $folded;
        $normalized = $this->stripCarte($normalized);
        // Dates de carte « JJ.MM.AA ».
        $normalized = preg_replace('/\b\d{2}\.\d{2}\.\d{2}\b/', '', $normalized) ?? $normalized;
        $normalized = trim((string) preg_replace('/\s+/u', ' ', $normalized));
        if (mb_strlen($normalized) > 255) {
            $normalized = mb_substr($normalized, 0, 255);
        }

        return new NormalizedLabel($normalized, $merchant, $this->key($rawLabel));
    }

    /** Supprime « CARTE NUMERO 123 » et « CARTE XX 123 ». */
    private function stripCarte(string $folded): string
    {
        return trim((string) preg_replace('/\bCARTE (?:NUMERO|[A-Z]{2}) \d+\b/', '', $folded));
    }

    /**
     * Extrait le commerçant / la contrepartie selon la forme du libellé (replié).
     * Le résultat ne contient pas de chiffres de référence finaux et fait au plus 120 caractères.
     */
    private function extractMerchant(string $folded): ?string
    {
        $folded = $this->stripCarte($folded);
        $merchant = null;

        if (preg_match('/^(?:ACHAT CB|AVOIR CARTE|[A-Z]+ CARTE)\s+(.+?)\s+\d{2}\.\d{2}\.\d{2}\b/s', $folded, $m)) {
            // ACHAT CB <COMMERÇANT> <JJ.MM.AA> … / AVOIR CARTE <COMMERÇANT> <JJ.MM.AA> …
            $merchant = $m[1];
        } elseif (preg_match('/^PRELEVEMENT(?: SEPA)? DE (.+?)(?:\s+\b(?:REF|IDENT|MOTIF|MOT|REFERENCE)\s*:|$)/s', $folded, $m)) {
            // PRELEVEMENT DE <CRÉANCIER> REF : … IDENT : …
            $merchant = $m[1];
        } elseif (preg_match('/\bVIREMENT\b.*?\b(?:DE|POUR|A)\b\s+(.+)$/s', $folded, $m)) {
            // VIREMENT [PERMANENT|INSTANTANE] DE/POUR/A <contrepartie> <références…>
            $merchant = $this->cutAtReference($m[1]);
        } elseif (str_starts_with($folded, 'CHEQUE') || str_starts_with($folded, 'REMISE DE CHEQUE')) {
            $merchant = 'CHEQUE';
        } elseif (str_starts_with($folded, 'RETRAIT')) {
            $merchant = 'RETRAIT DAB';
        } else {
            // Défaut : 3 premiers mots du libellé normalisé sans chiffres.
            $words = array_values(array_filter(
                explode(' ', $folded),
                static fn (string $w): bool => '' !== $w && !preg_match('/\d/', $w),
            ));
            $merchant = implode(' ', array_slice($words, 0, 3));
        }

        if (null === $merchant || '' === trim($merchant)) {
            return null;
        }

        // Supprime les jetons de référence finaux contenant des chiffres.
        $tokens = explode(' ', trim((string) preg_replace('/\s+/u', ' ', $merchant)));
        while (count($tokens) > 0 && preg_match('/\d/', (string) end($tokens))) {
            array_pop($tokens);
        }
        $merchant = implode(' ', $tokens);
        if ('' === $merchant) {
            return null;
        }

        return mb_strlen($merchant) > 120 ? mb_substr($merchant, 0, 120) : $merchant;
    }

    /**
     * Coupe la contrepartie au premier jeton contenant ≥ 6 chiffres, ou égal à
     * REFERENCE / MOTIF, ou contenant « : ».
     */
    private function cutAtReference(string $text): string
    {
        $tokens = explode(' ', trim($text));
        $kept = [];
        foreach ($tokens as $token) {
            if (preg_match('/\d{6,}/', $token) || str_contains($token, ':') || in_array($token, ['REFERENCE', 'MOTIF'], true)) {
                break;
            }
            $kept[] = $token;
        }

        return implode(' ', $kept);
    }
}
