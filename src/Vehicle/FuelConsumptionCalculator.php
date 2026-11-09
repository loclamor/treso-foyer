<?php

declare(strict_types=1);

namespace App\Vehicle;

/**
 * Calcul pur de la consommation de carburant, méthode du plein complet (ADR 0015).
 *
 * Règles :
 * - les pleins sont triés par date effective puis id ;
 * - pour chaque plein complet `Pj` précédé d'un plein complet `Pi`, le segment = volume
 *   en litres des pleins `i+1..j` (partiels compris) ÷ (km(`Pj`) − km(`Pi`)) × 100 (L/100 km) ;
 * - le km d'un plein = son `odometerKm` saisi, sinon interpolé sur les points de compteur
 *   (`OdometerCalculator::kmAt`, null hors de l'intervalle couvert) ;
 * - segment ignoré si une borne n'a pas de km ou si la distance est ≤ 0 ;
 * - plein complet à moins de MIN_SEGMENT_KM du précédent (remise à niveau, km interpolé sur une
 *   courte durée) : pas de segment, son volume est reporté sur le segment suivant (sinon une
 *   consommation aberrante, ex. 3 L sur 2 km = 150 L/100) ;
 * - les pleins antérieurs au premier plein complet ne comptent dans aucun segment ;
 * - la synthèse de la période somme les segments **se terminant** dans la période
 *   (le plein complet précédent peut être antérieur à la période).
 *
 * Sans base de données : entrées = tableaux.
 */
final class FuelConsumptionCalculator
{
    public const MIN_SEGMENT_KM = 50;

    public function __construct(
        private readonly OdometerCalculator $odometerCalculator = new OdometerCalculator(),
    ) {
    }

    /**
     * @param list<array{id: int|string, date: \DateTimeImmutable|string, volumeMl: int, fullTank: bool, odometerKm: ?int, amount: int}> $fills pleins du véhicule (triés ou non ; amount en centimes, négatif = débit)
     * @param list<array{date: \DateTimeImmutable|string, km: int|string}> $odometerPoints points de compteur du véhicule ({date, km})
     * @param ?\DateTimeImmutable $from borne début de la période (null = depuis le premier plein)
     * @param ?\DateTimeImmutable $to borne fin de la période (null = jusqu'au dernier plein)
     * @return array{
     *     fills: list<array{id: int|string, consumption: ?float, segmentKm: ?float}>,
     *     summary: array{count: int, volumeMl: int, amount: int, avgPricePerLiter: ?float, consumption: ?float, distanceKm: ?int}
     * } `fills` = pleins de la période (date asc), avec la consommation du segment qui se termine
     *   sur ce plein (2 décimales, null si pas de segment) et la distance du segment ; `summary` =
     *   synthèse de la période (count/volumeMl/amount des pleins de la période, prix moyen au litre
     *   en euros 3 décimales, consommation globale 2 décimales, distance totale arrondie à l'entier).
     */
    public function compute(array $fills, array $odometerPoints, ?\DateTimeImmutable $from = null, ?\DateTimeImmutable $to = null): array
    {
        $normalized = [];
        foreach ($fills as $fill) {
            $date = $fill['date'] instanceof \DateTimeImmutable ? $fill['date'] : new \DateTimeImmutable((string) $fill['date']);
            $normalized[] = [
                'id' => $fill['id'],
                'date' => $date->setTime(0, 0),
                'volumeMl' => (int) $fill['volumeMl'],
                'fullTank' => (bool) $fill['fullTank'],
                'odometerKm' => null !== $fill['odometerKm'] ? (int) $fill['odometerKm'] : null,
                'amount' => (int) $fill['amount'],
            ];
        }
        usort($normalized, static fn (array $a, array $b): int => ($a['date'] <=> $b['date']) ?: ($a['id'] <=> $b['id']));

        // Km de chaque plein : saisi, sinon interpolé sur les points de compteur.
        $kmOf = [];
        foreach ($normalized as $index => $fill) {
            $kmOf[$index] = $fill['odometerKm'] ?? $this->odometerCalculator->kmAt($odometerPoints, $fill['date']);
        }

        // Segments entre pleins complets consécutifs : j => {volumeMl, km}.
        /** @var array<int, array{volumeMl: int, km: float}> $segments */
        $segments = [];
        $previousFull = null;
        foreach ($normalized as $index => $fill) {
            if (!$fill['fullTank']) {
                continue;
            }
            if (null !== $previousFull) {
                $volumeMl = 0;
                for ($k = $previousFull + 1; $k <= $index; ++$k) {
                    $volumeMl += $normalized[$k]['volumeMl'];
                }
                $kmStart = $kmOf[$previousFull];
                $kmEnd = $kmOf[$index];
                if (null !== $kmStart && null !== $kmEnd && ($kmEnd - $kmStart) > 0 && ($kmEnd - $kmStart) < self::MIN_SEGMENT_KM) {
                    continue; // segment trop court : fusionné avec le suivant (previousFull inchangé)
                }
                if (null !== $kmStart && null !== $kmEnd && ($kmEnd - $kmStart) > 0) {
                    $segments[$index] = ['volumeMl' => $volumeMl, 'km' => $kmEnd - $kmStart];
                }
            }
            $previousFull = $index;
        }

        // Sortie : pleins de la période + synthèse de la période (segments s'y terminant).
        $from = $from?->setTime(0, 0);
        $to = $to?->setTime(0, 0);
        $outFills = [];
        $count = 0;
        $volumeMlSum = 0;
        $absAmountSum = 0;
        $amountSum = 0;
        $segmentVolumeSum = 0;
        $segmentKmSum = 0.0;
        $hasSegment = false;
        foreach ($normalized as $index => $fill) {
            if (null !== $from && $fill['date'] < $from) {
                continue;
            }
            if (null !== $to && $fill['date'] > $to) {
                continue;
            }
            ++$count;
            $volumeMlSum += $fill['volumeMl'];
            $absAmountSum += abs($fill['amount']);
            $amountSum += $fill['amount'];
            $segment = $segments[$index] ?? null;
            $outFills[] = [
                'id' => $fill['id'],
                // L/100 km = (volumeMl / 1000) L ÷ km × 100.
                'consumption' => null !== $segment ? round(($segment['volumeMl'] / 1000) / $segment['km'] * 100, 2) : null,
                'segmentKm' => $segment['km'] ?? null,
            ];
            if (null !== $segment) {
                $hasSegment = true;
                $segmentVolumeSum += $segment['volumeMl'];
                $segmentKmSum += $segment['km'];
            }
        }

        return [
            'fills' => $outFills,
            'summary' => [
                'count' => $count,
                'volumeMl' => $volumeMlSum,
                'amount' => $amountSum,
                // Prix moyen au litre en euros : Σ|amount| (centimes) × 10 / ΣvolumeMl (mL).
                'avgPricePerLiter' => $volumeMlSum > 0 ? round($absAmountSum * 10 / $volumeMlSum, 3) : null,
                'consumption' => $hasSegment && $segmentKmSum > 0 ? round(($segmentVolumeSum / 1000) / $segmentKmSum * 100, 2) : null,
                'distanceKm' => $hasSegment ? (int) round($segmentKmSum) : null,
            ],
        ];
    }
}
