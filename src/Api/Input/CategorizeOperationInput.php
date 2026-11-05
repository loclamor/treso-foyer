<?php

declare(strict_types=1);

namespace App\Api\Input;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Corps de POST /api/operations/{id}/categorize.
 * categoryId null ⇒ retire la catégorie manuelle puis recatégorise automatiquement.
 */
final class CategorizeOperationInput
{
    public function __construct(
        #[Assert\Positive(message: 'categoryId doit être un identifiant positif.')]
        public readonly ?int $categoryId = null,
        #[Assert\NotNull(message: 'scope est obligatoire.')]
        #[Assert\Choice(choices: ['operation', 'merchant'], message: 'scope doit être « operation » ou « merchant ».')]
        public readonly string $scope = 'operation',
    ) {
    }
}
