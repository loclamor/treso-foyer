<?php

declare(strict_types=1);

namespace App\Api\Input;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Corps de PUT /api/operations/{id}/gas : enregistre (remplace) l'achat de gaz d'une
 * opération (bonbonne, ADR 0017). Masse en grammes, strictement positive.
 * Un achat de gaz n'est possible que sur un débit (400 sinon).
 */
final class GasInput
{
    public function __construct(
        #[Assert\NotNull(message: 'massG est obligatoire.')]
        #[Assert\Type(type: 'int', message: 'massG doit être un entier (grammes).')]
        #[Assert\Positive(message: 'massG doit être strictement positif.')]
        public readonly ?int $massG = null,
    ) {
    }
}
