<?php

declare(strict_types=1);

namespace App\Backup;

/**
 * Convertit un intervalle de planification lisible ("3600", "30m", "12h", "1d", "1w")
 * en secondes. Utilisé par app:backup --watch et par le service rclone (équivalent shell
 * dans compose.yaml).
 */
final class BackupInterval
{
    public static function toSeconds(string $interval): int
    {
        $interval = trim($interval);
        if (preg_match('/^(\d+)([smhdw]?)$/', $interval, $m) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                'Intervalle invalide "%s" (attendu : nombre de secondes, ou suffixe m/h/d/w, ex. 3600, 30m, 12h, 1d, 1w).',
                $interval,
            ));
        }

        $value = (int) $m[1];
        $seconds = match ($m[2]) {
            'w' => $value * 604800,
            'd' => $value * 86400,
            'h' => $value * 3600,
            'm' => $value * 60,
            default => $value,
        };

        if ($seconds < 1) {
            throw new \InvalidArgumentException(sprintf('Intervalle invalide "%s" : doit être au moins 1 seconde.', $interval));
        }

        return $seconds;
    }
}
