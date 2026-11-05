<?php

declare(strict_types=1);

namespace App\Api\Input;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Corps de POST /api/operations/{id}/vehicle.
 * vehicleId null ⇒ retire l'affectation manuelle puis resynchronise l'axe véhicule.
 */
final class VehicleAssignInput
{
    public function __construct(
        #[Assert\Positive(message: 'vehicleId doit être un identifiant positif.')]
        public readonly ?int $vehicleId = null,
    ) {
    }
}
