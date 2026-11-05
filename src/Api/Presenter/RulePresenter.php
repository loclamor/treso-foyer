<?php

declare(strict_types=1);

namespace App\Api\Presenter;

use App\Entity\CategorizationRule;

final class RulePresenter
{
    public function __construct(
        private readonly CategoryPresenter $categoryPresenter,
        private readonly AccountPresenter $accountPresenter,
    ) {
    }

    /** @return array<string, mixed> */
    public function present(CategorizationRule $rule, int $matchCount = 0): array
    {
        return [
            'id' => (int) $rule->getId(),
            'category' => $this->categoryPresenter->presentRef($rule->getCategory()),
            'matchField' => $rule->getMatchField()->value,
            'matchType' => $rule->getMatchType()->value,
            'pattern' => $rule->getPattern(),
            'direction' => $rule->getDirection()->value,
            'amountMin' => $rule->getAmountMin(),
            'amountMax' => $rule->getAmountMax(),
            'account' => null !== $rule->getAccount() ? $this->accountPresenter->presentRef($rule->getAccount()) : null,
            'priority' => $rule->getPriority(),
            'enabled' => $rule->isEnabled(),
            'matchCount' => $matchCount,
        ];
    }
}
