<?php

declare(strict_types=1);

namespace App\Categorization;

/**
 * Compteurs du moteur de catégorisation (jamais de libellé d'opération affiché).
 */
final class CategorizationReport
{
    public function __construct(
        /** Opérations examinées (après filtres manual / virement interne / onlyUncategorized). */
        public int $processed = 0,
        public int $byRule = 0,
        public int $bySimilarity = 0,
        /** Examinées, aucune correspondance, laissées telles quelles. */
        public int $unchanged = 0,
        /** Sans catégorie à l'issue du traitement. */
        public int $stillUncategorized = 0,
    ) {
    }

    public function add(self $other): self
    {
        return new self(
            processed: $this->processed + $other->processed,
            byRule: $this->byRule + $other->byRule,
            bySimilarity: $this->bySimilarity + $other->bySimilarity,
            unchanged: $this->unchanged + $other->unchanged,
            stillUncategorized: $this->stillUncategorized + $other->stillUncategorized,
        );
    }
}
