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
            $this->kmAt($points, $coveredTo) - $this->kmAt($points, $coveredFrom),
            $coveredFrom,
            $coveredTo,
        );
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
        $kept = [];
        foreach ($points as $point) {
            if ([] === $kept || $point['km'] >= $kept[count($kept) - 1]['km']) {
                $kept[] = $point;
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
    private function kmAt(array $points, \DateTimeImmutable $date): float
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
