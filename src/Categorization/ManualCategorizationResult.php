<?php

declare(strict_types=1);

namespace App\Categorization;

use App\Entity\CategorizationRule;

/**
 * Résultat d'une catégorisation manuelle (voir ManualCategorizer::assign).
 */
final readonly class ManualCategorizationResult
{
    public function __construct(
        /** Nombre d'autres opérations du foyer mises à jour (règle appliquée ou similarité). */
        public int $updatedCount,
        /** Règle apprise (créée ou retrouvée), si demandé et si l'opération a un commerçant. */
        public ?CategorizationRule $rule,
    ) {
    }
}
