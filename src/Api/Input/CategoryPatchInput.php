<?php

declare(strict_types=1);

namespace App\Api\Input;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Corps de PATCH /api/categories/{id}. Les champs absents (null) ne sont pas modifiés.
 * Note : parentId null ne permet pas de remonter la catégorie à la racine (null = non fourni).
 */
final class CategoryPatchInput
{
    public function __construct(
        #[Assert\Length(min: 1, max: 80, minMessage: 'name ne doit pas être vide.', maxMessage: 'name ne doit pas dépasser 80 caractères.')]
        public readonly ?string $name = null,
        #[Assert\Positive(message: 'parentId doit être positif.')]
        public readonly ?int $parentId = null,
        #[Assert\Choice(choices: ['expense', 'income', 'transfer'], message: 'kind doit être « expense », « income » ou « transfer ».')]
        public readonly ?string $kind = null,
        #[Assert\Regex(pattern: '/^#[0-9a-fA-F]{6}$/', message: 'color doit être un hexadécimal #RRGGBB.')]
        public readonly ?string $color = null,
        #[Assert\Length(max: 50, maxMessage: 'icon ne doit pas dépasser 50 caractères.')]
        public readonly ?string $icon = null,
        #[Assert\Type(type: 'int', message: 'position doit être un entier.')]
        public readonly ?int $position = null,
        #[Assert\Type(type: 'bool', message: 'archived doit être un booléen.')]
        public readonly ?bool $archived = null,
        #[Assert\Type(type: 'bool', message: 'vehicleRelated doit être un booléen.')]
        public readonly ?bool $vehicleRelated = null,
        #[Assert\Type(type: 'bool', message: 'fuel doit être un booléen.')]
        public readonly ?bool $fuel = null,
        #[Assert\Type(type: 'bool', message: 'gas doit être un booléen.')]
        public readonly ?bool $gas = null,
        #[Assert\Type(type: 'bool', message: 'financing doit être un booléen.')]
        public readonly ?bool $financing = null,
        // null explicite = retire l'emplacement (la présence de la clé est détectée
        // sur le corps brut dans CategoryController, absent du corps = non modifié).
        #[Assert\Range(min: 1, max: 8, notInRangeMessage: 'chartSlot doit être compris entre 1 et 8.')]
        public readonly ?int $chartSlot = null,
    ) {
    }
}
