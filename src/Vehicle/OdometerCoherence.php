<?php

declare(strict_types=1);

namespace App\Vehicle;

/**
 * Avertissement d'incohérence d'un point de compteur (relevé saisi ou kilométrage d'un plein,
 * ADR 0015) avec ses points voisins : km strictement inférieur au point précédent, ou
 * strictement supérieur au point suivant (comparaison sur les dates strictes). Le point reste
 * stocké ; le calcul de distance (OdometerCalculator) garde la plus longue suite croissante,
 * donc écarte ce point ou son voisin aberrant.
 */
final class OdometerCoherence
{
    /**
     * Message d'avertissement si le point (date, km) est incohérent avec les points voisins,
     * null sinon.
     *
     * @param list<array{date: string, km: int}> $points points {date, km} du véhicule, date asc
     */
    public function warning(array $points, \DateTimeImmutable $date, int $km): ?string
    {
        $previous = null;
        $next = null;
        foreach ($points as $point) {
            $pointDate = new \DateTimeImmutable($point['date']);
            if ($pointDate < $date) {
                $previous = $point;
            } elseif ($pointDate > $date && null === $next) {
                $next = $point;
            }
        }
        $incoherent = (null !== $previous && $km < $previous['km'])
            || (null !== $next && $km > $next['km']);

        return $incoherent
            ? 'Kilométrage incohérent avec les points voisins (décroissant avec la date) : ce point ou son voisin aberrant sera ignoré par le calcul de distance.'
            : null;
    }
}
