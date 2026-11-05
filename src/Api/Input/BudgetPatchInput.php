<?php

declare(strict_types=1);

namespace App\Api\Input;

use Symfony\Component\Validator\Constraints as Assert;

/** Corps de PATCH /api/budgets/{id}. Les champs absents (null) ne sont pas modifiés. */
final class BudgetPatchInput
{
    public function __construct(
        #[Assert\Positive(message: 'categoryId doit être un identifiant positif.')]
        public readonly ?int $categoryId = null,
        #[Assert\Positive(message: 'monthlyAmount doit être strictement positif (centimes).')]
        public readonly ?int $monthlyAmount = null,
        #[Assert\Choice(choices: ['household', 'member', 'account'], message: 'scope doit être « household », « member » ou « account ».')]
        public readonly ?string $scope = null,
        #[Assert\Positive(message: 'memberId doit être un identifiant positif.')]
        public readonly ?int $memberId = null,
        #[Assert\Positive(message: 'accountId doit être un identifiant positif.')]
        public readonly ?int $accountId = null,
        #[Assert\Date(message: 'startMonth doit être une date AAA-MM-JJ.')]
        public readonly ?string $startMonth = null,
        #[Assert\Date(message: 'endMonth doit être une date AAA-MM-JJ.')]
        public readonly ?string $endMonth = null,
    ) {
    }
}
