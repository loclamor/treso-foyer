<?php

declare(strict_types=1);

namespace App\Api\Input;

use Symfony\Component\Validator\Constraints as Assert;

/** Corps de POST /api/categorization/run. */
final class CategorizationRunInput
{
    public function __construct(
        #[Assert\Type(type: 'bool', message: 'all doit être un booléen.')]
        public readonly bool $all = false,
    ) {
    }
}
