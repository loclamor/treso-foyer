<?php

declare(strict_types=1);

namespace App\Vehicle;

/**
 * Consommation d'AdBlue (ADR 0016), calcul pur. Les remplissages au bidon sont partiels :
 * pas de méthode du plein complet. On rapporte les volumes versés à la distance parcourue
 * sur la fenêtre couverte par les points de compteur ([coveredFrom, coveredTo], comme le
 * coût au km) : Σ volumes des remplissages datés dans ]coveredFrom, coveredTo] ÷ km.
 * Un remplissage le jour de coveredFrom compense ce qui a été consommé avant : exclu.
 * Approximation fiable sur une période longue (plusieurs remplissages).
 */
final class AdblueConsumptionCalculator
{
    public function __construct(private readonly OdometerCalculator $odometerCalculator)
    {
    }

    /**
     * @param list<array{date: \DateTimeImmutable, volumeMl: int}> $fills remplissages du véhicule
     * @param list<array{date: string, km: int}> $points points de compteur du véhicule, date asc
     * @return array{consumption: ?float, distanceKm: ?int} consumption en L/1000 km (2 déc.)
     */
    public function compute(array $fills, array $points, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $distance = $this->odometerCalculator->distance($points, $from, $to);
        if (null === $distance || $distance->km <= 0.0) {
            return ['consumption' => null, 'distanceKm' => null];
        }
        $coveredFrom = new \DateTimeImmutable($distance->coveredFrom->format('Y-m-d'));
        $coveredTo = new \DateTimeImmutable($distance->coveredTo->format('Y-m-d'));

        $volumeMl = 0;
        $count = 0;
        foreach ($fills as $fill) {
            if ($fill['date'] > $coveredFrom && $fill['date'] <= $coveredTo) {
                $volumeMl += $fill['volumeMl'];
                ++$count;
            }
        }
        if (0 === $count) {
            return ['consumption' => null, 'distanceKm' => (int) round($distance->km)];
        }

        // L/1000 km = (mL / 1000) / km × 1000 = mL / km.
        return ['consumption' => round($volumeMl / $distance->km, 2), 'distanceKm' => (int) round($distance->km)];
    }
}
