<?php

declare(strict_types=1);

namespace App\Api\Input;

use Symfony\Component\Validator\Constraints as Assert;

/** Corps de POST /api/operations/{id}/duplicate. */
final class DuplicateInput
{
    public function __construct(
        #[Assert\NotNull(message: 'action est obligatoire.')]
        #[Assert\Choice(choices: ['merge', 'distinct'], message: 'action doit être « merge » ou « distinct ».')]
        public readonly ?string $action = null,
    ) {
    }
}
