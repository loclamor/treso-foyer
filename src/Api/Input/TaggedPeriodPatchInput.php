<?php

declare(strict_types=1);

namespace App\Api\Input;

use Symfony\Component\Validator\Constraints as Assert;

/** Corps de PATCH /api/tagged-periods/{id}. Les champs absents (null) ne sont pas modifiés. */
final class TaggedPeriodPatchInput
{
    public function __construct(
        #[Assert\Choice(choices: ['vacation', 'weekend', 'outing', 'other'], message: 'type doit être « vacation », « weekend », « outing » ou « other ».')]
        public readonly ?string $type = null,
        #[Assert\NotBlank(message: 'label ne peut pas être vide.', allowNull: true)]
        #[Assert\Length(max: 100, maxMessage: 'Le libellé ne doit pas dépasser {{ limit }} caractères.')]
        public readonly ?string $label = null,
        #[Assert\Date(message: 'startDate doit être une date AAA-MM-JJ.')]
        public readonly ?string $startDate = null,
        #[Assert\Date(message: 'endDate doit être une date AAA-MM-JJ.')]
        public readonly ?string $endDate = null,
        #[Assert\Positive(message: 'vehicleId doit être un identifiant positif.')]
        public readonly ?int $vehicleId = null,
        public readonly ?bool $vehicleOverridesRules = null,
        /** true ⇒ retire le véhicule par défaut (vehicleId doit alors être absent). */
        public readonly bool $clearVehicle = false,
    ) {
    }

    #[Assert\IsTrue(message: 'clearVehicle ne peut pas être combiné avec un vehicleId.')]
    public function isVehicleConsistent(): bool
    {
        return !$this->clearVehicle || null === $this->vehicleId;
    }
}
