<?php

declare(strict_types=1);

namespace App\Import;

/**
 * Repère les relevés mensuels manquants d'un compte à partir des mois (AAAA-MM, mois de
 * la date d'arrêté) de ses relevés déjà importés :
 *  - « gap » : mois absent entre le premier et le dernier relevé ;
 *  - « overdue » : mois postérieur au dernier relevé dont le relevé devrait déjà être
 *    disponible (arrêté vers le 12, considéré disponible à partir du jour AVAILABLE_FROM_DAY).
 * Un compte sans aucun relevé mensuel (ex. alimenté uniquement par des exports CSV)
 * n'est pas évalué.
 */
final class MissingStatementFinder
{
    public const REASON_GAP = 'gap';
    public const REASON_OVERDUE = 'overdue';

    /** Jour du mois à partir duquel le relevé du mois est attendu. */
    public const AVAILABLE_FROM_DAY = 21;

    /**
     * @param list<string> $months mois des relevés importés (AAAA-MM), dans n'importe quel ordre, doublons tolérés
     *
     * @return list<array{month: string, reason: string}> triés par mois croissant
     */
    public function find(array $months, \DateTimeImmutable $today): array
    {
        $present = array_fill_keys($months, true);
        if ([] === $present) {
            return [];
        }
        $sorted = array_keys($present);
        sort($sorted);

        $first = $this->monthStart($sorted[0]);
        $last = $this->monthStart($sorted[count($sorted) - 1]);
        $lastExpected = $this->lastExpectedMonth($today);

        $missing = [];
        for ($month = $first->modify('+1 month'); $month < $last; $month = $month->modify('+1 month')) {
            $key = $month->format('Y-m');
            if (!isset($present[$key])) {
                $missing[] = ['month' => $key, 'reason' => self::REASON_GAP];
            }
        }
        for ($month = $last->modify('+1 month'); $month <= $lastExpected; $month = $month->modify('+1 month')) {
            $missing[] = ['month' => $month->format('Y-m'), 'reason' => self::REASON_OVERDUE];
        }

        return $missing;
    }

    private function lastExpectedMonth(\DateTimeImmutable $today): \DateTimeImmutable
    {
        $month = $today->modify('first day of this month')->setTime(0, 0);

        return (int) $today->format('j') >= self::AVAILABLE_FROM_DAY ? $month : $month->modify('-1 month');
    }

    private function monthStart(string $month): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m', $month);
        if (false === $date) {
            throw new \InvalidArgumentException(sprintf('Mois invalide : « %s » (format attendu AAAA-MM).', $month));
        }

        return $date;
    }
}
