<?php

declare(strict_types=1);

namespace App\Api\Input;

/**
 * Corps de PATCH /api/recurrences/{id}. Champ absent = inchangé ;
 * `activeMonths` à null explicite (clé présente) = tous les mois.
 * Les valeurs sont validées dans le contrôleur (422 avec violations).
 */
final class RecurrencePatchInput
{
    public function __construct(
        public readonly ?string $status = null,
        public readonly ?int $expectedAmount = null,
        public readonly ?int $amountTolerance = null,
        public readonly ?string $frequency = null,
        public readonly ?array $activeMonths = null,
    ) {
    }
}
