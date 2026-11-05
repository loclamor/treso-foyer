<?php

declare(strict_types=1);

namespace App\Api\Presenter;

use App\Entity\Account;

final class AccountPresenter
{
    public function __construct(private readonly MemberPresenter $memberPresenter)
    {
    }

    /** @return array{id: int, alias: string, label: string, numberMasked: string} */
    public function presentRef(Account $account): array
    {
        return [
            'id' => (int) $account->getId(),
            'alias' => $account->getAlias(),
            'label' => $account->getLabel(),
            'numberMasked' => $account->getNumberMasked(),
        ];
    }

    /**
     * @param array{operationsCount?: int, firstDate?: ?string, lastDate?: ?string, balance?: ?array{amount: int, date: string}} $stats
     * @return array<string, mixed>
     */
    public function present(Account $account, array $stats = []): array
    {
        $balance = $stats['balance'] ?? null;

        return $this->presentRef($account) + [
            'type' => $account->getType()->value,
            'bank' => $account->getBank(),
            'closedAt' => $account->getClosedAt()?->format('Y-m-d'),
            'holders' => array_map(
                fn ($holder): array => $this->memberPresenter->present($holder),
                $account->getHolders()->toArray(),
            ),
            'operationsCount' => $stats['operationsCount'] ?? 0,
            'firstDate' => $stats['firstDate'] ?? null,
            'lastDate' => $stats['lastDate'] ?? null,
            'balance' => null !== $balance ? ['amount' => $balance['amount'], 'date' => $balance['date']] : null,
        ];
    }
}
