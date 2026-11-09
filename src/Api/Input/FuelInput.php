<?php

declare(strict_types=1);

namespace App\Api\Input;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Corps de PUT /api/operations/{id}/fuel : enregistre (remplace) le plein d'une
 * opération (ADR 0015). Volume en millilitres, plein complet par défaut, kilométrage
 * au compteur optionnel. Un plein n'est possible que sur un débit (400 sinon).
 */
final class FuelInput
{
    public function __construct(
        #[Assert\NotNull(message: 'volumeMl est obligatoire.')]
        #[Assert\Type(type: 'int', message: 'volumeMl doit être un entier (millilitres).')]
        #[Assert\Positive(message: 'volumeMl doit être strictement positif.')]
        public readonly ?int $volumeMl = null,
        #[Assert\Type(type: 'bool', message: 'fullTank doit être un booléen.')]
        public readonly ?bool $fullTank = true,
        #[Assert\Type(type: 'int', message: 'odometerKm doit être un entier.')]
        #[Assert\PositiveOrZero(message: 'odometerKm doit être positif ou nul.')]
        public readonly ?int $odometerKm = null,
    ) {
    }
}
