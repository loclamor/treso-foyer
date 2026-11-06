<?php

declare(strict_types=1);

namespace App\Import;

use App\Categorization\CategorizationReport;
use App\Recurrence\RecurrenceDetectionReport;
use App\Reconciliation\ReconciliationReport;
use App\Vehicle\VehicleAssignmentReport;

/**
 * Résultat du post-traitement d'un import : rapprochement des saisies manuelles,
 * appariement des virements internes, catégorisation automatique, affectation des
 * véhicules puis détection des récurrences (compteurs uniquement, jamais de libellé).
 */
final readonly class PostImportReport
{
    public function __construct(
        public ReconciliationReport $reconciliation,
        public int $transfersMatched,
        public int $transfersAmbiguous,
        public CategorizationReport $categorization,
        public VehicleAssignmentReport $vehicles,
        public RecurrenceDetectionReport $recurrences,
    ) {
    }

    /** Opérations ayant reçu une catégorie (règle + similarité). */
    public function categorized(): int
    {
        return $this->categorization->byRule + $this->categorization->bySimilarity;
    }

    /** Opérations ayant reçu un véhicule (règle + auto). */
    public function vehiclesAssigned(): int
    {
        return $this->vehicles->byRule + $this->vehicles->auto;
    }
}
