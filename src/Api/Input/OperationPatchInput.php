<?php

declare(strict_types=1);

namespace App\Api\Input;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Corps de PATCH /api/operations/{id} : note (null = efface la note), plus — pour une saisie
 * manuelle EN ATTENTE uniquement — accountId, spentAt (modifie aussi la date), amount, label.
 * La présence de ces champs est détectée sur le corps brut (absent = inchangé).
 */
final class OperationPatchInput
{
    public function __construct(
        #[Assert\Type(type: 'string', message: 'note doit être une chaîne.')]
        #[Assert\Length(max: 2000, maxMessage: 'note ne doit pas dépasser 2000 caractères.')]
        public readonly ?string $note = null,
        #[Assert\Type(type: 'int', message: 'accountId doit être un entier.')]
        public readonly ?int $accountId = null,
        #[Assert\Type(type: 'string', message: 'spentAt doit être une chaîne.')]
        #[Assert\Date(message: 'spentAt doit être une date AAA-MM-JJ valide.')]
        public readonly ?string $spentAt = null,
        #[Assert\Type(type: 'int', message: 'amount doit être un entier (centimes signés).')]
        #[Assert\NotEqualTo(value: 0, message: 'amount doit être non nul.')]
        public readonly ?int $amount = null,
        #[Assert\Type(type: 'string', message: 'label doit être une chaîne.')]
        #[Assert\Length(min: 1, max: 255, minMessage: 'label ne doit pas être vide.', maxMessage: 'label ne doit pas dépasser 255 caractères.')]
        public readonly ?string $label = null,
    ) {
    }
}
