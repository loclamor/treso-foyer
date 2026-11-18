<?php

declare(strict_types=1);

namespace App\Vehicle;

/**
 * Véhicule par défaut d'une période labellisée (ADR 0018), réduit à ce dont la politique
 * d'affectation a besoin : bornes incluses, véhicule, priorité sur les règles.
 */
final readonly class PeriodVehicle
{
    public function __construct(
        public \DateTimeImmutable $startDate,
        public \DateTimeImmutable $endDate,
        public int $vehicleId,
        public bool $overridesRules,
    ) {
    }

    /** Bornes incluses, comparaison au jour (l'heure éventuelle de $date est ignorée). */
    public function covers(\DateTimeImmutable $date): bool
    {
        $day = $date->format('Y-m-d');

        return $day >= $this->startDate->format('Y-m-d') && $day <= $this->endDate->format('Y-m-d');
    }
}
