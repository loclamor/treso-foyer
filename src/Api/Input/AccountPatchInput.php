<?php

declare(strict_types=1);

namespace App\Api\Input;

use Symfony\Component\Validator\Constraints as Assert;

/** Corps de PATCH /api/accounts/{id}. */
final class AccountPatchInput
{
    public function __construct(
        #[Assert\Length(min: 1, max: 50, minMessage: 'alias ne doit pas être vide.', maxMessage: 'alias ne doit pas dépasser 50 caractères.')]
        public readonly ?string $alias = null,
        #[Assert\Length(min: 1, max: 100, minMessage: 'label ne doit pas être vide.', maxMessage: 'label ne doit pas dépasser 100 caractères.')]
        public readonly ?string $label = null,
        #[Assert\Choice(choices: ['personal', 'joint', 'savings'], message: 'type doit être « personal », « joint » ou « savings ».')]
        public readonly ?string $type = null,
        #[Assert\Type(type: 'array', message: 'holderIds doit être un tableau.')]
        #[Assert\All([new Assert\Positive(message: 'Chaque holderId doit être positif.')])]
        public readonly ?array $holderIds = null,
    ) {
    }
}
