<?php

declare(strict_types=1);

namespace App\Api\Input;

use Symfony\Component\Validator\Constraints as Assert;

/** Corps de POST /api/operations/{id}/reconcile : id de la ligne de relevé cible. */
final class ReconcileInput
{
    public function __construct(
        #[Assert\NotNull(message: 'operationId est obligatoire.')]
        #[Assert\Type(type: 'int', message: 'operationId doit être un entier.')]
        public readonly ?int $operationId = null,
    ) {
    }
}
