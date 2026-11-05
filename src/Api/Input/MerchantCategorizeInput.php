<?php

declare(strict_types=1);

namespace App\Api\Input;

use Symfony\Component\Validator\Constraints as Assert;

/** Corps de POST /api/merchants/categorize. */
final class MerchantCategorizeInput
{
    public function __construct(
        #[Assert\NotBlank(message: 'key est obligatoire.')]
        public readonly ?string $key = null,
        #[Assert\NotNull(message: 'direction est obligatoire.')]
        #[Assert\Choice(choices: ['debit', 'credit'], message: 'direction doit être « debit » ou « credit ».')]
        public readonly ?string $direction = null,
        #[Assert\NotNull(message: 'categoryId est obligatoire.')]
        #[Assert\Positive(message: 'categoryId doit être un identifiant positif.')]
        public readonly ?int $categoryId = null,
        #[Assert\Type(type: 'bool', message: 'createRule doit être un booléen.')]
        public readonly bool $createRule = true,
    ) {
    }
}
