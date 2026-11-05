<?php

declare(strict_types=1);

namespace App\Api\Input;

use Symfony\Component\Validator\Constraints as Assert;

/** Corps de POST /api/budgets. Les dates sont au format AAA-MM-JJ. */
final class BudgetCreateInput
{
    public function __construct(
        #[Assert\NotNull(message: 'categoryId est obligatoire.')]
        #[Assert\Positive(message: 'categoryId doit être un identifiant positif.')]
        public readonly ?int $categoryId = null,
        #[Assert\NotNull(message: 'monthlyAmount est obligatoire.')]
        #[Assert\Positive(message: 'monthlyAmount doit être strictement positif (centimes).')]
        public readonly ?int $monthlyAmount = null,
        #[Assert\NotNull(message: 'scope est obligatoire.')]
        #[Assert\Choice(choices: ['household', 'member', 'account'], message: 'scope doit être « household », « member » ou « account ».')]
        public readonly ?string $scope = null,
        #[Assert\Positive(message: 'memberId doit être un identifiant positif.')]
        public readonly ?int $memberId = null,
        #[Assert\Positive(message: 'accountId doit être un identifiant positif.')]
        public readonly ?int $accountId = null,
        #[Assert\NotBlank(message: 'startMonth est obligatoire.')]
        #[Assert\Date(message: 'startMonth doit être une date AAA-MM-JJ.')]
        public readonly ?string $startMonth = null,
        #[Assert\Date(message: 'endMonth doit être une date AAA-MM-JJ.')]
        public readonly ?string $endMonth = null,
    ) {
    }
}
