<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Normalise une plaque d'immatriculation française.
 * Format SIV (depuis 2009) : renvoyé « AA-123-AA » ; ancien format FNI : renvoyé sans séparateurs.
 */
final class PlateNormalizer
{
    private const SIV_PATTERN = '/^[A-Z]{2}\d{3}[A-Z]{2}$/';
    private const FNI_PATTERN = '/^\d{1,4}[A-Z]{1,3}\d{2,3}$/';

    /**
     * @throws \InvalidArgumentException si ni SIV ni FNI (la plaque n'est jamais recopiée dans le message)
     */
    public static function normalize(string $plate): string
    {
        $clean = mb_strtoupper(str_replace([' ', '-', '.'], '', $plate), 'UTF-8');

        if (1 === preg_match(self::SIV_PATTERN, $clean)) {
            return substr($clean, 0, 2).'-'.substr($clean, 2, 3).'-'.substr($clean, 5, 2);
        }
        if (1 === preg_match(self::FNI_PATTERN, $clean)) {
            return $clean;
        }

        throw new \InvalidArgumentException('Format de plaque d’immatriculation non reconnu.');
    }

    public static function isValid(string $plate): bool
    {
        try {
            self::normalize($plate);

            return true;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }
}
