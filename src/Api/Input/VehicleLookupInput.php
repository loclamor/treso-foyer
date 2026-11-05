<?php

declare(strict_types=1);

namespace App\Api\Input;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Corps de POST /api/vehicles/lookup. vehicleId optionnel : enregistre la réponse
 * brute (lookupData) et lookupAt sur ce véhicule, sans toucher à ses autres champs.
 */
final class VehicleLookupInput
{
    public function __construct(
        #[Assert\NotBlank(message: 'plate est obligatoire.')]
        #[Assert\Length(max: 20, maxMessage: 'plate ne doit pas dépasser 20 caractères.')]
        public readonly ?string $plate = null,
        #[Assert\Positive(message: 'vehicleId doit être un identifiant positif.')]
        public readonly ?int $vehicleId = null,
    ) {
    }
}
