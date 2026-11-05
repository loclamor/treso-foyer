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

    private static function dateParam(mixed $raw, string $param): ?\DateTimeImmutable
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
