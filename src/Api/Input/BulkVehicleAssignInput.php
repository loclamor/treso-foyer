<?php

declare(strict_types=1);

namespace App\Api\Input;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Corps de POST /api/operations/bulk-vehicle : affectation manuelle, remise à null si vehicleId null,
 * ou marquage « ignorée » si ignore true (avec vehicleId null).
 */
final class BulkVehicleAssignInput
{
    public function __construct(
        #[Assert\Count(min: 1, minMessage: 'ids ne doit pas être vide.')]
        #[Assert\Type(type: 'array', message: 'ids doit être un tableau.')]
        #[Assert\All([new Assert\Positive(message: 'Chaque id doit être positif.')])]
        public readonly array $ids = [],
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
