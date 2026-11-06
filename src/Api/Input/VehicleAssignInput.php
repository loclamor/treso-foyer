<?php

declare(strict_types=1);

namespace App\Api\Input;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Corps de POST /api/operations/{id}/vehicle.
 * vehicleId null ⇒ retire l'affectation manuelle (ou le marquage « ignorée ») puis resynchronise l'axe véhicule.
 * ignore true (avec vehicleId null) ⇒ marque l'opération « ignorée » (vehicleSource « ignored »).
 */
final class VehicleAssignInput
{
    public function __construct(
        #[Assert\Positive(message: 'vehicleId doit être un identifiant positif.')]
        public readonly ?int $vehicleId = null,
        public readonly bool $ignore = false,
    ) {
    }

    #[Assert\IsTrue(message: 'ignore ne peut pas être combiné avec un vehicleId.')]
    public function isIgnoreConsistent(): bool
    {
        return !$this->ignore || null === $this->vehicleId;
    }
}
