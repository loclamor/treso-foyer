<?php

declare(strict_types=1);

namespace App\Import;

/**
 * Conversion montants texte <-> centimes (entiers), sans jamais passer par un float
 * (voir docs/adr/0004-montants-en-centimes.md).
 *
 * Formats acceptés en entrée : `-27,56`, `2350,00`, `-1 234,56` (espace, espace
 * insécable U+00A0 ou espace fine U+202F comme séparateur de milliers), avec
 * éventuellement un `+` frontal et 1 ou 2 décimales.
 */
final class Money
{
    private const THOUSAND_SEPARATORS = ["\u{00A0}", "\u{202F}", ' ', "\t"];

    /**
     * Parse un montant texte en centimes.
     *
     * @throws \InvalidArgumentException si le format est invalide
     */
    public static function parse(string $text): int
    {
        $clean = trim(str_replace('€', '', $text));
        $clean = str_replace(self::THOUSAND_SEPARATORS, '', $clean);
        $clean = str_replace(',', '.', $clean);

        if (!preg_match('/^([+-]?)(\d+)(?:\.(\d{1,2}))?$/', $clean, $m)) {
            throw new \InvalidArgumentException(sprintf('Montant invalide : « %s » (forme attendue : -1 234,56).', trim($text)));
        }

        $sign = '-' === $m[1] ? -1 : 1;
        $euros = (int) $m[2];
        $cents = isset($m[3]) ? (int) str_pad($m[3], 2, '0') : 0;

        return $sign * ($euros * 100 + $cents);
    }

    /**
     * Formate des centimes en texte, ex `-1 234,56 €` (espace insécable U+00A0
     * comme séparateur de milliers).
     */
    public static function format(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $abs = abs($cents);
        $euros = intdiv($abs, 100);
        $rest = $abs % 100;

        $grouped = preg_replace('/\B(?=(\d{3})+(?!\d))/', "\u{00A0}", (string) $euros);

        return sprintf('%s%s,%02d €', $sign, $grouped, $rest);
    }
}
