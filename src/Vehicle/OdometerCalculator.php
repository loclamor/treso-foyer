<?php

declare(strict_types=1);

namespace App\Vehicle;

use App\Entity\OdometerReading;

/**
 * Calcul pur de la distance parcourue à partir de relevés kilométriques.
 *
 * Règles (contrat docs/api.md, lot 4) :
 * - les relevés sont triés par date croissante (les doublons de date sont dédoublonnés,
 *   premier relevé rencontré conservé) ;
 * - un relevé est **incohérent** si son km est strictement inférieur à celui du dernier
 *   relevé cohérent qui le précède (km décroissant avec la date) : on n'invente rien,
 *   le relevé incohérent est ignoré (et tout relevé ultérieur qui ne rétablit pas la
 *   monotonie l'est aussi) ;
 * - la période demandée est ramenée à son intersection avec [premier relevé, dernier
 *   relevé] : coveredFrom/coveredTo valent au plus cette intersection ;
 * - le km à chaque borne est interpolé linéairement entre les relevés encadrants
 *   (valeur exacte si la borne tombe sur un relevé) ;
 * - null si moins de 2 relevés cohérents ou si l'intersection est vide (from > to).
 */
final class OdometerCalculator
{
    /**
     * @param list<OdometerReading|array{date: \DateTimeImmutable|string, km: int|string}> $readings relevés (entités ou tableaux {date, km})
     * @return ?OdometerDistance null si moins de 2 relevés cohérents ou intersection vide
     */
    public function distance(array $readings, \DateTimeImmutable $from, \DateTimeImmutable $to): ?OdometerDistance
    {
        $points = $this->coherentPoints($readings);
        if (count($points) < 2) {
            return null;
        }

        $first = $points[0];
        $last = $points[count($points) - 1];
        // Intersection de la période demandée avec [premier relevé, dernier relevé].
        $coveredFrom = $from < $first['date'] ? $first['date'] : $from;
        $coveredTo = $to > $last['date'] ? $last['date'] : $to;
        if ($coveredFrom > $coveredTo) {
            return null;
        }

        return new OdometerDistance(
            $this->kmAtPoints($points, $coveredTo) - $this->kmAtPoints($points, $coveredFrom),
            $coveredFrom,
            $coveredTo,
        );
    }

    /**
     * Km à une date, par interpolation linéaire entre les points cohérents encadrants
     * (valeur exacte si la date est celle d'un point). Utilisé pour interpoler le km d'un
     * plein sans kilométrage saisi (ADR 0015).
     *
     * @param list<OdometerReading|array{date: \DateTimeImmutable|string, km: int|string}> $readings relevés (entités ou tableaux {date, km})
     * @return ?float null si moins de 2 points cohérents (sauf un seul point exactement à la date)
     *               ou si la date est hors [premier point, dernier point] (pas d'extrapolation)
     */
    public function kmAt(array $readings, \DateTimeImmutable $date): ?float
    {
        $points = $this->coherentPoints($readings);
        $count = count($points);
        if ($count < 2) {
            // Un seul point cohérent : km connu uniquement si la date est exactement celle du point.
            return 1 === $count && $points[0]['date'] == $date ? (float) $points[0]['km'] : null;
        }

        $first = $points[0];
        $last = $points[$count - 1];
        if ($date < $first['date'] || $date > $last['date']) {
            return null; // hors de l'intervalle couvert : pas d'extrapolation
        }

        return $this->kmAtPoints($points, $date);
    }

    /**
     * Relevés normalisés ({date, km}), triés par date croissante, dédoublonnés par date
     * puis débarrassés des relevés incohérents (km strictement décroissant).
     *
     * @param list<OdometerReading|array{date: \DateTimeImmutable|string, km: int|string}> $readings
     * @return list<array{date: \DateTimeImmutable, km: int}>
     */
    private function coherentPoints(array $readings): array
    {
        $points = [];
        foreach ($readings as $reading) {
            if ($reading instanceof OdometerReading) {
                $points[] = ['date' => $reading->getDate(), 'km' => $reading->getKm()];
            } elseif (\is_array($reading) && isset($reading['date'], $reading['km'])) {
                $date = $reading['date'];
                if (\is_string($date)) {
                    $date = new \DateTimeImmutable($date);
                }
                if (!$date instanceof \DateTimeImmutable) {
                    throw new \InvalidArgumentException('La date d’un relevé doit être une \DateTimeImmutable ou une chaîne AAAA-MM-JJ.');
                }
                $points[] = ['date' => $date, 'km' => (int) $reading['km']];
            } else {
                throw new \InvalidArgumentException('Relevé attendu : OdometerReading ou tableau {date, km}.');
            }
        }

        // Tri stable par date (PHP ≥ 8.0) : en cas d’égalité de date, l’ordre d’origine est conservé.
        usort($points, static fn (array $a, array $b): int => $a['date'] <=> $b['date']);

        // Dédoublonnage par date (premier relevé rencontré conservé).
        $byDate = [];
        foreach ($points as $point) {
            $key = $point['date']->format('Y-m-d');
            $byDate[$key] ??= $point;
        }
        $points = array_values($byDate);

        // Relevés incohérents ignorés : km strictement inférieur au dernier relevé cohérent.
        return $this->longestNonDecreasing($points);
    }

    /**
     * Plus longue suite de relevés à kilométrage croissant (au sens large) : un relevé aberrant
     * isolé (faute de frappe 1 000 000 entre 100 000 et 101 000, y compris en premier) est écarté
     * seul au lieu d'invalider tous les suivants. À longueur égale, on garde à chaque étape le
     * relevé le plus ancien possible (même choix que l'ancien filtrage glouton).
     *
     * @param list<array{date: \DateTimeImmutable, km: int}> $points triés par date
     * @return list<array{date: \DateTimeImmutable, km: int}>
     */
    private function longestNonDecreasing(array $points): array
    {
        $count = count($points);
        if ($count < 2) {
            return $points;
        }

        // $best[$i] = longueur de la plus longue suite croissante commençant au point $i.
        $best = array_fill(0, $count, 1);
        for ($i = $count - 2; $i >= 0; $i--) {
            for ($j = $i + 1; $j < $count; $j++) {
                if ($points[$j]['km'] >= $points[$i]['km'] && $best[$j] + 1 > $best[$i]) {
                    $best[$i] = $best[$j] + 1;
                }
            }
        }

        $kept = [];
        $remaining = max($best);
        $lastKm = \PHP_INT_MIN;
        for ($i = 0; $i < $count && $remaining > 0; $i++) {
            if ($best[$i] === $remaining && $points[$i]['km'] >= $lastKm) {
                $kept[] = $points[$i];
                $lastKm = $points[$i]['km'];
                --$remaining;
            }
        }

        return $kept;
    }

    /**
     * Km à une date, par interpolation linéaire entre les relevés encadrants
     * (exact si la date est celle d’un relevé). $date est supposée dans [premier, dernier].
     *
     * @param list<array{date: \DateTimeImmutable, km: int}> $points triés par date croissante, au moins 2
     */
    private function kmAtPoints(array $points, \DateTimeImmutable $date): float
    {
        $lastIndex = count($points) - 1;
        for ($i = 0; $i <= $lastIndex; $i++) {
            if ($points[$i]['date'] == $date) {
                return (float) $points[$i]['km'];
            }
            if ($i < $lastIndex && $points[$i]['date'] < $date && $date < $points[$i + 1]['date']) {
                $start = $points[$i]['date']->getTimestamp();
                $span = $points[$i + 1]['date']->getTimestamp() - $start;
                $elapsed = $date->getTimestamp() - $start;

                return $points[$i]['km'] + ($points[$i + 1]['km'] - $points[$i]['km']) * ($elapsed / $span);
            }
        }

        // Borne hors des relevés encadrants (ne doit pas arriver : période ramenée à l’intersection).
        return (float) ($date < $points[0]['date'] ? $points[0]['km'] : $points[$lastIndex]['km']);
    }
}
