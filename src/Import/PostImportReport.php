<?php

declare(strict_types=1);

namespace App\Import;

use App\Categorization\CategorizationReport;

/**
 * Résultat du post-traitement d'un import : appariement des virements internes
 * puis catégorisation automatique (compteurs uniquement, jamais de libellé).
 */
final readonly class PostImportReport
{
    public function __construct(
        public int $transfersMatched,
        public int $transfersAmbiguous,
        public CategorizationReport $categorization,
    ) {
    }

    /** Opérations ayant reçu une catégorie (règle + similarité). */
    public function categorized(): int
    {
        return $this->categorization->byRule + $this->categorization->bySimilarity;
    }
}
