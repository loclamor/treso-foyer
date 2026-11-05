<?php

declare(strict_types=1);

namespace App\Api\Input;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Corps de PATCH /api/vehicles/{id}. Champ absent (null) = inchangé ;
 * chaîne vide = remise à null pour les champs date et texte.
 * Exception : mainDriverId null explicite (clé présente) retire le conducteur principal.
 */
final class VehiclePatchInput
{
    public function __construct(
        #[Assert\Length(min: 1, max: 100, minMessage: 'name ne doit pas être vide.', maxMessage: 'name ne doit pas dépasser 100 caractères.')]
        public readonly ?string $name = null,
        #[Assert\Choice(choices: ['car', 'motorcycle', 'bike', 'other'], message: 'type doit être « car », « motorcycle », « bike » ou « other ».')]
        public readonly ?string $type = null,
        #[Assert\Length(max: 20, maxMessage: 'plate ne doit pas dépasser 20 caractères.')]
        public readonly ?string $plate = null,
        #[Assert\Length(max: 60, maxMessage: 'make ne doit pas dépasser 60 caractères.')]
        public readonly ?string $make = null,
        #[Assert\Length(max: 100, maxMessage: 'model ne doit pas dépasser 100 caractères.')]
        public readonly ?string $model = null,
        #[Assert\Choice(choices: ['petrol', 'diesel', 'electric', 'hybrid', 'plugin_hybrid', 'lpg', 'other'], message: 'energy invalide.')]
        public readonly ?string $energy = null,
        #[Assert\PositiveOrZero(message: 'fiscalPower doit être positif ou nul (CV).')]
        public readonly ?int $fiscalPower = null,
        #[Assert\Date(message: 'firstRegistrationAt doit être une date au format AAAA-MM-JJ.')]
        public readonly ?string $firstRegistrationAt = null,
        #[Assert\Length(max: 17, maxMessage: 'vin ne doit pas dépasser 17 caractères.')]
        public readonly ?string $vin = null,
        #[Assert\Date(message: 'acquiredAt doit être une date au format AAAA-MM-JJ.')]
        public readonly ?string $acquiredAt = null,
        #[Assert\Date(message: 'disposedAt doit être une date au format AAAA-MM-JJ.')]
        public readonly ?string $disposedAt = null,
        #[Assert\PositiveOrZero(message: 'purchasePrice doit être positif ou nul (centimes).')]
        public readonly ?int $purchasePrice = null,
        #[Assert\Positive(message: 'mainDriverId doit être un identifiant positif.')]
        public readonly ?int $mainDriverId = null,
        #[Assert\Type(type: 'bool', message: 'archived doit être un booléen.')]
        public readonly ?bool $archived = null,
    ) {
    }
}
