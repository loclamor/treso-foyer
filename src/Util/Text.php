<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Helpers de normalisation de texte (comparaisons insensibles à la casse et aux accents).
 */
final class Text
{
    /** Ligatures et caractères spéciaux translittérés avant conversion ASCII. */
    private const LIGATURES = [
        'œ' => 'oe', 'Œ' => 'OE',
        'æ' => 'ae', 'Æ' => 'AE',
        'ß' => 'ss',
        'ø' => 'o', 'Ø' => 'O',
        'å' => 'a', 'Å' => 'A',
        'đ' => 'd', 'Đ' => 'D',
        'ł' => 'l', 'Ł' => 'L',
        'þ' => 'th', 'Þ' => 'TH',
    ];

    /**
     * Replie une chaîne : majuscules, sans accents, espaces multiples réduits, trim.
     */
    public static function fold(string $text): string
    {
        $decomposed = \Normalizer::normalize($text, \Normalizer::FORM_D);
        if (!is_string($decomposed)) {
            $decomposed = $text;
        }
        // Supprime les accents combinants (é -> e + U+0301 -> e).
        $withoutMarks = preg_replace('/\p{Mn}+/u', '', $decomposed) ?? $decomposed;
        $withoutMarks = strtr($withoutMarks, self::LIGATURES);
        $translit = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $withoutMarks);
        $ascii = is_string($translit) && '' !== $translit ? $translit : $withoutMarks;
        $upper = mb_strtoupper($ascii, 'UTF-8');
        $collapsed = preg_replace('/\s+/u', ' ', $upper);

        return trim(is_string($collapsed) ? $collapsed : $upper);
    }
}
