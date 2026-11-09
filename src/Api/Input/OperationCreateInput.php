<?php

declare(strict_types=1);

namespace App\Api\Input;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Corps de POST /api/operations : saisie manuelle d'une dépense (voir ADR 0014).
 * Montant en centimes signés (négatif = débit), non nul. `spentAt` = date réelle de la dépense.
 */
final class OperationCreateInput
{
    public function __construct(
        #[Assert\NotNull(message: 'accountId est obligatoire.')]
        #[Assert\Type(type: 'int', message: 'accountId doit être un entier.')]
        public readonly ?int $accountId = null,
        #[Assert\NotBlank(message: 'spentAt est obligatoire.')]
        #[Assert\Type(type: 'string', message: 'spentAt doit être une chaîne.')]
        #[Assert\Date(message: 'spentAt doit être une date AAA-MM-JJ valide.')]
        public readonly ?string $spentAt = null,
        #[Assert\NotNull(message: 'amount est obligatoire.')]
        #[Assert\Type(type: 'int', message: 'amount doit être un entier (centimes signés).')]
        #[Assert\NotEqualTo(value: 0, message: 'amount doit être non nul.')]
        public readonly ?int $amount = null,
        #[Assert\NotBlank(message: 'label est obligatoire.')]
        #[Assert\Type(type: 'string', message: 'label doit être une chaîne.')]
        #[Assert\Length(min: 1, max: 255, minMessage: 'label ne doit pas être vide.', maxMessage: 'label ne doit pas dépasser 255 caractères.')]
        public readonly ?string $label = null,
        #[Assert\Type(type: 'int', message: 'categoryId doit être un entier.')]
        public readonly ?int $categoryId = null,
        #[Assert\Type(type: 'int', message: 'vehicleId doit être un entier.')]
        public readonly ?int $vehicleId = null,
        #[Assert\Type(type: 'string', message: 'note doit être une chaîne.')]
        #[Assert\Length(max: 2000, maxMessage: 'note ne doit pas dépasser 2000 caractères.')]
        public readonly ?string $note = null,
        /** Plein de carburant (ADR 0015) : volume en millilitres, > 0 ; refusé sur un crédit (422). */
        #[Assert\Type(type: 'int', message: 'fuelVolumeMl doit être un entier (millilitres).')]
        #[Assert\Positive(message: 'fuelVolumeMl doit être strictement positif.')]
        public readonly ?int $fuelVolumeMl = null,
        /** Plein complet (défaut true si un volume est fourni) ; plein partiel = plafond de carte. */
        #[Assert\Type(type: 'bool', message: 'fuelFullTank doit être un booléen.')]
        public readonly ?bool $fuelFullTank = null,
        /** Kilométrage au compteur lors du plein ; refusé (422) sans fuelVolumeMl. */
        #[Assert\Type(type: 'int', message: 'odometerKm doit être un entier.')]
        #[Assert\PositiveOrZero(message: 'odometerKm doit être positif ou nul.')]
        public readonly ?int $odometerKm = null,
    ) {
    }
}
