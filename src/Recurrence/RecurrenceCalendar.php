<?php

declare(strict_types=1);

namespace App\Recurrence;

use App\Enum\RecurrenceFrequency;

/**
 * Calendrier pur des échéances d'une série récurrente : avancement d'une date selon la
 * fréquence (mois calendaires en conservant le jour de l'ancre, borné au dernier jour du
 * mois cible ; +7 jours × cycles pour l'hebdomadaire), en sautant les mois hors `activeMonths`.
 */
final class RecurrenceCalendar
{
    /**
     * Première échéance strictement après $date (les mois hors activeMonths sont sautés).
     * Le jour conservé est celui de $date (ancre) : 31 janvier → 28 février → 31 mars.
     *
     * @param list<int>|null $activeMonths
     */
    public static function nextAfter(\DateTimeImmutable $date, RecurrenceFrequency $frequency, ?array $activeMonths): \DateTimeImmutable
    {
        if (null === $activeMonths) {
            return self::addCycles($date, $frequency, 1);
        }
        // Borne de garde : on ne saute jamais plus de 24 cycles.
        for ($cycles = 1; $cycles <= 24; ++$cycles) {
            $candidate = self::addCycles($date, $frequency, $cycles);
            if (\in_array((int) $candidate->format('n'), $activeMonths, true)) {
                return $candidate;
            }
        }

        return self::addCycles($date, $frequency, 1);
    }

    /** $date + $cycles cycles de la fréquence (jour de $date conservé, borné au dernier jour du mois cible). */
    public static function addCycles(\DateTimeImmutable $date, RecurrenceFrequency $frequency, int $cycles): \DateTimeImmutable
    {
        $months = $frequency->months();
        if (null === $months) {
            return $date->modify('+'.(7 * $cycles).' days');
        }

        $total = (int) $date->format('Y') * 12 + ((int) $date->format('n') - 1) + $months * $cycles;
        $year = intdiv($total, 12);
        $month = $total % 12 + 1;
        $day = min((int) $date->format('j'), (int) (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->format('t'));

        return new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
    }
}
