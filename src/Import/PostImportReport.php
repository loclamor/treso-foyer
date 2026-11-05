<?php

declare(strict_types=1);

namespace App\Import;

use App\Categorization\CategorizationReport;
use App\Vehicle\VehicleAssignmentReport;

/**
 * Résultat du post-traitement d'un import : appariement des virements internes,
 * catégorisation automatique puis affectation des véhicules (compteurs uniquement,
 * jamais de libellé).
 */
final readonly class PostImportReport
{
    public function __construct(
        public int $transfersMatched,
        public int $transfersAmbiguous,
        public CategorizationReport $categorization,
        public VehicleAssignmentReport $vehicles,
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
