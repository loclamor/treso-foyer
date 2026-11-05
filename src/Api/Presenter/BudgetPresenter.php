<?php

declare(strict_types=1);

namespace App\Api\Presenter;

use App\Entity\Budget;

final class BudgetPresenter
{
    public function __construct(
        private readonly CategoryPresenter $categoryPresenter,
        private readonly MemberPresenter $memberPresenter,
        private readonly AccountPresenter $accountPresenter,
    ) {
    }

    /** @return array<string, mixed> */
    public function present(Budget $budget): array
    {
        return [
            'id' => (int) $budget->getId(),
            'category' => $this->categoryPresenter->presentRef($budget->getCategory()),
            'monthlyAmount' => $budget->getMonthlyAmount(),
            'scope' => $budget->getScope()->value,
            'member' => null !== $budget->getMember() ? $this->memberPresenter->present($budget->getMember()) : null,
            'account' => null !== $budget->getAccount() ? $this->accountPresenter->presentRef($budget->getAccount()) : null,
            'startMonth' => $budget->getStartMonth()->format('Y-m-d'),
            'endMonth' => $budget->getEndMonth()?->format('Y-m-d'),
        ];
    }
}
