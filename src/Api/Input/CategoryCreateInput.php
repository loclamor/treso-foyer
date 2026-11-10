<?php

declare(strict_types=1);

namespace App\Api\Input;

use Symfony\Component\Validator\Constraints as Assert;

/** Corps de POST /api/categories. */
final class CategoryCreateInput
{
    public function __construct(
        #[Assert\NotBlank(message: 'name est obligatoire.')]
        #[Assert\Length(max: 80, maxMessage: 'name ne doit pas dépasser 80 caractères.')]
        public readonly ?string $name = null,
        #[Assert\Positive(message: 'parentId doit être positif.')]
        public readonly ?int $parentId = null,
        #[Assert\NotNull(message: 'kind est obligatoire.')]
        #[Assert\Choice(choices: ['expense', 'income', 'transfer'], message: 'kind doit être « expense », « income » ou « transfer ».')]
        public readonly ?string $kind = null,
        #[Assert\Regex(pattern: '/^#[0-9a-fA-F]{6}$/', message: 'color doit être un hexadécimal #RRGGBB.')]
        public readonly ?string $color = null,
        #[Assert\Length(max: 50, maxMessage: 'icon ne doit pas dépasser 50 caractères.')]
        public readonly ?string $icon = null,
        /** Poste lié à un véhicule (carburant, entretien, assurance auto, péages & parking). */
        #[Assert\Type(type: 'bool', message: 'vehicleRelated doit être un booléen.')]
        public readonly ?bool $vehicleRelated = null,
        /** Poste carburant : affiche les champs du plein (ADR 0015). */
        #[Assert\Type(type: 'bool', message: 'fuel doit être un booléen.')]
        public readonly ?bool $fuel = null,
        /** Poste gaz : affiche les champs de l'achat de gaz (masse, prix au kg, ADR 0017). */
        #[Assert\Type(type: 'bool', message: 'gas doit être un booléen.')]
        public readonly ?bool $gas = null,
        /** Poste de financement d'un véhicule : exclu du coût d'usage (ADR 0011). */
        #[Assert\Type(type: 'bool', message: 'financing doit être un booléen.')]
        public readonly ?bool $financing = null,
    ) {
    }
}
