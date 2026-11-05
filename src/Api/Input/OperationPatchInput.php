<?php

declare(strict_types=1);

namespace App\Api\Input;

use Symfony\Component\Validator\Constraints as Assert;

/** Corps de PATCH /api/operations/{id} : note (null = efface la note). */
final class OperationPatchInput
{
    public function __construct(
        #[Assert\Type(type: 'string', message: 'note doit être une chaîne.')]
        #[Assert\Length(max: 2000, maxMessage: 'note ne doit pas dépasser 2000 caractères.')]
        public readonly ?string $note = null,
    ) {
    }
}
