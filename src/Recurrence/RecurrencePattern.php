<?php

declare(strict_types=1);

namespace App\Recurrence;

use App\Enum\RecurrenceFrequency;

/**
 * Détection pure d'un motif récurrent dans un groupe d'opérations (même compte, même clé
 * de regroupement, même sens) : une seule opération par jour (fusionnées en sommant),
 * écarts entre dates successives comparés à la durée nominale de chaque fréquence.
 *
 * Classe PURE (sans base ni horloge externe) : testée unitairement.
 */
final class RecurrencePattern
{
    /** Part minimale d'écarts réguliers. */
    private const float REGULAR_RATIO = 0.75;
    /** Part minimale d'écarts à un seul cycle (k = 1). */
    private const float DIRECT_RATIO = 0.60;
    /** Seuil du sous-ensemble « montant proche de la médiane ». */
    private const float AMOUNT_BAND = 0.15;
    /** Dispersion relative maximale des montants (écart absolu médian / médiane) pour weekly. */
    private const float WEEKLY_MAX_DISPERSION = 0.25;

    /**
     * @param list<array{date: \DateTimeImmutable, amount: int, id?: int}> $operations montants en valeur absolue, quelconque ordre
     */
    public static function analyze(array $operations, \DateTimeImmutable $today): ?RecurrenceAnalysis
    {
        // Une seule occurrence par jour : on fusionne en sommant les montants.
        $byDay = [];
        foreach ($operations as $operation) {
            $day = $operation['date']->format('Y-m-d');
            if (!isset($byDay[$day])) {
                $byDay[$day] = ['date' => $operation['date']->setTime(0, 0), 'amount' => 0, 'ids' => []];
            }
            $byDay[$day]['amount'] += $operation['amount'];
            if (isset($operation['id'])) {
                $byDay[$day]['ids'][] = $operation['id'];
            }
        }
        ksort($byDay);
        $days = array_values($byDay);
        if (count($days) < 2) {
            return null;
        }

        $analysis = self::tryAnalyze($days, $today);
        if (null !== $analysis) {
            return $analysis;
        }

        // Second essai sur le sous-ensemble dont le montant est à ±15 % de la médiane
        // (abonnement + achats ponctuels chez le même commerçant).
        $median = self::median(array_map(static fn (array $day): int => $day['amount'], $days));
        if ($median <= 0) {
            return null;
        }
        $subset = array_values(array_filter(
            $days,
            static fn (array $day): bool => abs($day['amount'] - $median) <= self::AMOUNT_BAND * $median,
        ));
        if (count($subset) < count($days)) {
            return self::tryAnalyze($subset, $today);
        }

        return null;
    }

    /**
     * @param list<array{date: \DateTimeImmutable, amount: int, ids: list<int>}> $days triés par date, une entrée par jour
     */
    private static function tryAnalyze(array $days, \DateTimeImmutable $today): ?RecurrenceAnalysis
    {
        $gaps = [];
        for ($i = 1, $n = count($days); $i < $n; ++$i) {
            $gaps[] = (int) $days[$i - 1]['date']->diff($days[$i]['date'])->days;
        }
        if ([] === $gaps) {
            return null;
        }

        // Fréquence candidate : celle dont la durée nominale est la plus proche de la médiane des écarts.
        $medianGap = self::median($gaps);
        $frequency = null;
        $bestDiff = \INF;
        foreach (RecurrenceFrequency::cases() as $candidate) {
            $diff = abs($medianGap - $candidate->nominalDays());
            if ($diff < $bestDiff) {
                $bestDiff = $diff;
                $frequency = $candidate;
            }
        }
        \assert(null !== $frequency);
        if ($bestDiff > $frequency->toleranceDays()) {
            return null;
        }

        // Écart régulier : |écart − k × nominal| ≤ tolérance (k = 1, ou k ∈ {1,2,3} pour
        // weekly/monthly/bimonthly : cycles sautés, ex. mensualisation sur 10 mois).
        $allowedK = \in_array($frequency, [RecurrenceFrequency::Weekly, RecurrenceFrequency::Monthly, RecurrenceFrequency::Bimonthly], true)
            ? [1, 2, 3]
            : [1];
        $nominal = $frequency->nominalDays();
        $tolerance = $frequency->toleranceDays();
        $regular = 0;
        $direct = 0;
        foreach ($gaps as $gap) {
            foreach ($allowedK as $k) {
                if (abs($gap - $k * $nominal) <= $tolerance) {
                    ++$regular;
                    if (1 === $k) {
                        ++$direct;
                    }
                    break;
                }
            }
        }
        $gapCount = count($gaps);
        if ($regular < self::REGULAR_RATIO * $gapCount || $direct < self::DIRECT_RATIO * $gapCount) {
            return null;
        }

        // Nombre minimal d'occurrences.
        $minOccurrences = match ($frequency) {
            RecurrenceFrequency::Weekly => 6,
            RecurrenceFrequency::Monthly => 4,
            RecurrenceFrequency::Bimonthly => 3,
            RecurrenceFrequency::Quarterly => 3,
            // 3 occurrences (fenêtre de 36 mois) : 2 achats à un an d'écart ne font pas une récurrence.
            RecurrenceFrequency::Semiannual => 3,
            RecurrenceFrequency::Yearly => 3,
        };
        if (count($days) < $minOccurrences) {
            return null;
        }

        // Récence : la dernière occurrence doit être postérieure à today − (2 × nominal + tolérance).
        $last = $days[count($days) - 1]['date'];
        $recencyLimit = $today->modify('-'.(int) round(2 * $nominal + $tolerance).' days');
        if ($last <= $recencyLimit) {
            return null;
        }

        // Montant attendu : médiane des montants des 6 dernières occurrences.
        $lastAmounts = array_map(static fn (array $day): int => $day['amount'], array_slice($days, -6));
        $expected = (int) round(self::median($lastAmounts));
        $medianDeviation = self::median(array_map(static fn (int $amount): int => abs($amount - $expected), $lastAmounts));
        // Hebdomadaire : montants stables exigés (sinon : courses, boulangerie… fréquentes mais variables).
        if (RecurrenceFrequency::Weekly === $frequency && $expected > 0) {
            $allAmounts = array_map(static fn (array $day): int => $day['amount'], $days);
            $allMedian = self::median($allAmounts);
            $dispersion = self::median(array_map(static fn (int $amount): float => abs($amount - $allMedian), $allAmounts));
            if ($allMedian <= 0 || $dispersion / $allMedian > self::WEEKLY_MAX_DISPERSION) {
                return null;
            }
        }
        $amountTolerance = max(100, (int) round(self::AMOUNT_BAND * $expected), (int) round(2 * $medianDeviation));

        // Mois actifs (weekly/monthly/bimonthly uniquement).
        $activeMonths = null;
        if (\in_array($frequency, [RecurrenceFrequency::Weekly, RecurrenceFrequency::Monthly, RecurrenceFrequency::Bimonthly], true)) {
            $windowStart = $today->modify('first day of this month')->modify('-12 months');
            $currentMonthFirst = $today->modify('first day of this month');
            $months = [];
            foreach ($days as $day) {
                if ($day['date'] >= $windowStart && $day['date'] < $currentMonthFirst) {
                    $month = (int) $day['date']->format('n');
                    $months[$month] = ($months[$month] ?? 0) + 1;
                }
            }
            // Historique d'au moins 12 mois entre la première et la dernière occurrence, et mois
            // inactifs = UN SEUL bloc d'au moins 2 mois consécutifs (cycliquement, ex. juillet-août),
            // sans mois voisin à 2 occurrences (sinon simple décalage de date : 30/10 → 02/12).
            $spanMonths = ((int) $last->format('Y') - (int) $days[0]['date']->format('Y')) * 12
                + ((int) $last->format('n') - (int) $days[0]['date']->format('n'));
            if ($spanMonths >= 12 && count($months) >= 6 && count($months) <= 10
                && self::isSeasonalGap($months)) {
                $activeMonths = array_keys($months);
                sort($activeMonths);
            }
        }

        $linkedIds = [];
        foreach ($days as $day) {
            foreach ($day['ids'] as $id) {
                $linkedIds[] = $id;
            }
        }

        return new RecurrenceAnalysis(
            frequency: $frequency,
            expectedAmount: $expected,
            amountTolerance: $amountTolerance,
            lastOccurrence: $last,
            nextExpected: RecurrenceCalendar::nextAfter($last, $frequency, $activeMonths),
            activeMonths: $activeMonths,
            linkedIds: $linkedIds,
        );
    }

    /**
     * Vrai si les mois sans occurrence forment un seul bloc cyclique d'au moins 2 mois
     * et qu'aucun mois voisin du bloc n'a 2 occurrences ou plus (compensation d'un décalage).
     *
     * @param array<int, int> $months mois (1..12) => nombre d'occurrences
     */
    private static function isSeasonalGap(array $months): bool
    {
        $inactive = array_values(array_diff(range(1, 12), array_keys($months)));
        if (count($inactive) < 2) {
            return false;
        }
        // Début du bloc : mois inactif dont le précédent (cyclique) est actif ; il doit être unique.
        $starts = array_values(array_filter(
            $inactive,
            static fn (int $m): bool => isset($months[1 === $m ? 12 : $m - 1]),
        ));
        if (1 !== count($starts)) {
            return false;
        }
        $before = 1 === $starts[0] ? 12 : $starts[0] - 1;
        $after = (($starts[0] - 1 + count($inactive)) % 12) + 1;

        return ($months[$before] ?? 0) < 2 && ($months[$after] ?? 0) < 2;
    }

    /** @param list<int|float> $values */
    private static function median(array $values): float
    {
        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);

        return 0 === $count % 2
            ? ($values[$middle - 1] + $values[$middle]) / 2
            : (float) $values[$middle];
    }
}
