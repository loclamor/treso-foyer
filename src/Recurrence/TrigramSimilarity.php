<?php

declare(strict_types=1);

namespace App\Recurrence;

/**
 * Similarité trigramme, même définition que pg_trgm `similarity()` : mots alphanumériques
 * en minuscules, préfixés de deux espaces et suffixés d'une espace, ensemble des trigrammes,
 * |A ∩ B| / |A ∪ B|.
 */
final class TrigramSimilarity
{
    public static function similarity(string $a, string $b): float
    {
        $ta = self::trigrams($a);
        $tb = self::trigrams($b);
        if ([] === $ta || [] === $tb) {
            return 0.0;
        }
        $common = count(array_intersect_key($ta, $tb));

        return $common / (count($ta) + count($tb) - $common);
    }

    /** @return array<string, true> */
    private static function trigrams(string $text): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, \PREG_SPLIT_NO_EMPTY) ?: [];
        $trigrams = [];
        foreach ($words as $word) {
            $padded = '  '.$word.' ';
            $length = mb_strlen($padded);
            for ($i = 0; $i + 3 <= $length; ++$i) {
                $trigrams[mb_substr($padded, $i, 3)] = true;
            }
        }

        return $trigrams;
    }
}
