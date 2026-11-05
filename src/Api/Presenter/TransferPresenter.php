<?php

declare(strict_types=1);

namespace App\Api\Presenter;

use App\Entity\InternalTransfer;

final class TransferPresenter
{
    public function __construct(private readonly OperationPresenter $operationPresenter)
    {
    }

    /** @return array{id: int, status: string, operations: list<array<string, mixed>>} */
    public function present(InternalTransfer $transfer): array
    {
        return [
            'id' => (int) $transfer->getId(),
            'status' => $transfer->getStatus()->value,
            'operations' => array_map(
                fn ($operation): array => $this->operationPresenter->present($operation),
                $transfer->getOperations()->toArray(),
            ),
        ];
    }
}
