<?php

declare(strict_types=1);

namespace App\Categorization;

/**
 * Options du moteur de catégorisation (voir docs/adr/0006-categorisation.md).
 */
final readonly class CategorizeOptions
{
    public function __construct(
        /** Ne traiter que les opérations sans catégorie (category IS NULL). */
        public bool $onlyUncategorized = true,
        /** Calculer sans rien écrire en base. */
        public bool $dryRun = false,
        /** Seuil de similarité pg_trgm (0..1). */
        public float $similarityThreshold = 0.6,
        /** Retirer la catégorie des opérations précédemment catégorisées (rule/similarity/llm) que plus rien ne correspond. */
        public bool $resetUnmatched = false,
        /** Restreindre à un compte (null = tous les comptes du foyer). */
        public ?int $accountId = null,
    ) {
    }
}
