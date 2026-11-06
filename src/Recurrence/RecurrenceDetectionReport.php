<?php

declare(strict_types=1);

namespace App\Recurrence;

/**
 * Compteurs de la détection des séries récurrentes (jamais de libellé ni de montant).
 */
final class RecurrenceDetectionReport
{
    public function __construct(
        /** Séries créées. */
        public int $created = 0,
        /** Séries mises à jour (une valeur a réellement changé). */
        public int $updated = 0,
        /** Séries passées à « terminée » (échéance attendue trop ancienne). */
        public int $ended = 0,
        /** Séries « détectées » supprimées : le motif n'est plus reconnu (critères ou historique). */
        public int $removed = 0,
        /** Lignes d'opérations réellement reliées (ou déliées). */
        public int $linked = 0,
    ) {
    }

    /** @return array{created: int, updated: int, ended: int, removed: int, linked: int} */
    public function toArray(): array
    {
        return [
            'created' => $this->created,
            'updated' => $this->updated,
            'ended' => $this->ended,
            'removed' => $this->removed,
            'linked' => $this->linked,
        ];
    }
}
