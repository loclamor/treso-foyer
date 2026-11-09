<?php

declare(strict_types=1);

namespace App\Stats;

use App\Api\BadRequestException;
use Symfony\Component\HttpFoundation\Request;

/**
 * Période des statistiques (contrat docs/api.md, étape 8) : from/to inclus.
 * Défaut : 12 derniers mois complets + mois en cours.
 */
final class StatsPeriod
{
    public function __construct(
        public readonly \DateTimeImmutable $from,
        public readonly \DateTimeImmutable $to,
    ) {
    }

    public static function fromRequest(Request $request, ?\DateTimeImmutable $now = null): self
    {
        $now ??= new \DateTimeImmutable();
        $from = self::dateParam($request->query->get('from'), 'from');
        $to = self::dateParam($request->query->get('to'), 'to');

        // Défaut de to : dernier jour du mois en cours ; défaut de from : 12 mois complets
        // avant le mois de to (soit « 12 derniers mois complets + mois en cours »).
        $to ??= $now->modify('last day of this month');
        $from ??= $to->modify('first day of this month')->modify('-12 months');

        if ($from > $to) {
            throw new BadRequestException('Paramètre from postérieur à to.', 'invalid_query');
        }

        return new self($from, $to);
    }

    /** Premier jour du mois de $date. */
    public static function firstOfMonth(\DateTimeImmutable $date): \DateTimeImmutable
    {
        return $date->modify('first day of this month')->setTime(0, 0);
    }

    /** Dernier jour du mois de $date. */
    public static function lastOfMonth(\DateTimeImmutable $date): \DateTimeImmutable
    {
        return $date->modify('last day of this month')->setTime(0, 0);
    }

    /**
     * Période « incomplète » : elle touche au mois en cours (to >= premier jour du mois de $now).
     * Le mois en cours est toujours incomplet (salaires vers le 26-29, relevés importés
     * vers le 8 du mois suivant) : l'inclure fausse le taux d'épargne et les comparaisons.
     */
    public function isIncomplete(\DateTimeImmutable $now): bool
    {
        return $this->to >= self::firstOfMonth($now);
    }

    /**
     * Partie « mois complets » de la période : la période elle-même si elle est complète ;
     * sinon [from, dernier jour du mois précédent] si from déborde avant le mois en cours ;
     * sinon null (période entièrement dans le mois en cours).
     */
    public function completeMonths(\DateTimeImmutable $now): ?self
    {
        if (!$this->isIncomplete($now)) {
            return $this;
        }
        $firstCurrent = self::firstOfMonth($now);
        if ($this->from < $firstCurrent) {
            return new self($this->from, $firstCurrent->modify('-1 day'));
        }

        return null;
    }

    /**
     * Période comparable : completeMonths(now) si non null, sinon « mois à date »
     * [from, min(to, aujourd'hui)].
     */
    public function comparable(\DateTimeImmutable $now): self
    {
        // Comparaison à la date du jour (sans l'heure : les bornes from/to sont des dates).
        $today = $now->setTime(0, 0);

        return $this->completeMonths($now)
            ?? new self($this->from, $this->to < $today ? $this->to : $today);
    }

    /**
     * Période de comparaison alignée sur comparable(now) :
     * - comparable = mois calendaires complets (from au 1er, to en fin de mois, N mois)
     *   ⇒ les N mois calendaires précédents ;
     * - comparable = « mois à date » (jours j1..j2 du mois en cours)
     *   ⇒ mêmes jours du mois précédent, bornés au dernier jour de ce mois ;
     * - sinon (période personnalisée non alignée) ⇒ comparable->previous() (même durée).
     */
    public function previousAligned(\DateTimeImmutable $now): self
    {
        $comparable = $this->comparable($now);

        if (self::isFullCalendarMonths($comparable)) {
            $n = count($comparable->months());

            return new self(
                $comparable->from->modify(sprintf('-%d months', $n)),
                $comparable->from->modify('-1 day'),
            );
        }

        $firstCurrent = self::firstOfMonth($now);
        if ($comparable->from >= $firstCurrent && $comparable->to >= $firstCurrent) {
            $prevMonth = $firstCurrent->modify('-1 month');
            $lastDayPrev = (int) self::lastOfMonth($prevMonth)->format('t');
            $fromDay = min((int) $comparable->from->format('j'), $lastDayPrev);
            $toDay = min((int) $comparable->to->format('j'), $lastDayPrev);

            return new self(
                new \DateTimeImmutable(sprintf('%04d-%02d-%02d', (int) $prevMonth->format('Y'), (int) $prevMonth->format('n'), $fromDay)),
                new \DateTimeImmutable(sprintf('%04d-%02d-%02d', (int) $prevMonth->format('Y'), (int) $prevMonth->format('n'), $toDay)),
            );
        }

        return $comparable->previous();
    }

    /** Période de même durée qui précède immédiatement celle-ci. */
    public function previous(): self
    {
        $days = (int) $this->from->diff($this->to)->days + 1;

        return new self(
            $this->from->modify(sprintf('-%d days', $days)),
            $this->from->modify('-1 day'),
        );
    }

    /** Même période un an plus tôt (29 février → 28 février si l'année cible n'est pas bissextile). */
    public function sameLastYear(): self
    {
        return new self(
            self::minusOneYear($this->from),
            self::minusOneYear($this->to),
        );
    }

    /** Période des 12 mois complets précédant le mois de « to ». */
    public function last12FullMonths(): self
    {
        $firstOfToMonth = $this->to->modify('first day of this month');

        return new self(
            $firstOfToMonth->modify('-12 months'),
            $firstOfToMonth->modify('-1 day'),
        );
    }

    /** @return list<string> mois « YYYY-MM » de from à to, mois manquants inclus */
    public function months(): array
    {
        $months = [];
        $cursor = $this->from->modify('first day of this month');
        $end = $this->to->modify('first day of this month');
        while ($cursor <= $end) {
            $months[] = $cursor->format('Y-m');
            $cursor = $cursor->modify('+1 month');
        }

        return $months;
    }

    /** @return array{from: string, to: string} */
    public function toArray(): array
    {
        return [
            'from' => $this->from->format('Y-m-d'),
            'to' => $this->to->format('Y-m-d'),
        ];
    }

    /** Vrai si la période couvre des mois calendaires entiers (from au 1er, to en fin de mois). */
    private static function isFullCalendarMonths(self $period): bool
    {
        return $period->from->format('Y-m-d') === self::firstOfMonth($period->from)->format('Y-m-d')
            && $period->to->format('Y-m-d') === self::lastOfMonth($period->to)->format('Y-m-d');
    }

    private static function minusOneYear(\DateTimeImmutable $date): \DateTimeImmutable
    {
        $year = (int) $date->format('Y') - 1;
        $month = (int) $date->format('n');
        $day = (int) $date->format('j');
        if (!checkdate($month, $day, $year)) {
            // 29 février vers une année non bissextile : dernier jour du mois.
            $day = (int) (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->format('t');
        }

        return new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
    }

    /**
     * Parse un paramètre de date AAAA-MM-JJ (null/absent ⇒ null) ; invalide ⇒ 400
     * `invalid_query`. Public : réutilisé hors stats (ex. GET /api/vehicles/{id}/fuel).
     */
    public static function dateParam(mixed $raw, string $param): ?\DateTimeImmutable
    {
        if (null === $raw || '' === $raw) {
            return null;
        }
        $raw = (string) $raw;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            throw new BadRequestException(sprintf('Paramètre %s invalide (format AAA-MM-JJ attendu).', $param), 'invalid_query');
        }
        [$y, $m, $d] = array_map('intval', explode('-', $raw));
        if (!checkdate($m, $d, $y)) {
            throw new BadRequestException(sprintf('Paramètre %s invalide (date inexistante).', $param), 'invalid_query');
        }

        return new \DateTimeImmutable($raw);
    }
}
