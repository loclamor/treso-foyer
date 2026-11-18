<?php

declare(strict_types=1);

namespace App\Vehicle;

/**
 * Compteurs de la synchronisation des affectations véhicules (jamais de nom ni de plaque).
 */
final class VehicleAssignmentReport
{
    public function __construct(
        /** Opérations candidates examinées (non manuelles, cf. VehicleAssigner). */
        public int $processed = 0,
        /** Affectations posées depuis une règle appliquée avec véhicule. */
        public int $byRule = 0,
        /** Affectations posées automatiquement (catégorie vehicleRelated + un seul véhicule actif). */
        public int $auto = 0,
        /** Affectations posées par le véhicule par défaut d'une période labellisée. */
        public int $byPeriod = 0,
        /** Affectations rule/auto/period retirées (plus de correspondance). */
        public int $cleared = 0,
        /** Candidates déjà dans l'état attendu (idempotence). */
        public int $unchanged = 0,
    ) {
    }

    /** @return array{processed: int, byRule: int, auto: int, byPeriod: int, cleared: int, unchanged: int} */
    public function toArray(): array
    {
        return [
            'processed' => $this->processed,
            'byRule' => $this->byRule,
            'auto' => $this->auto,
            'byPeriod' => $this->byPeriod,
            'cleared' => $this->cleared,
            'unchanged' => $this->unchanged,
        ];
    }
}
