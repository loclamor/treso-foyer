<?php

declare(strict_types=1);

namespace App\Api\Presenter;

use App\Entity\Operation;

final class OperationPresenter
{
    public function __construct(
        private readonly AccountPresenter $accountPresenter,
        private readonly CategoryPresenter $categoryPresenter,
        private readonly VehiclePresenter $vehiclePresenter,
    ) {
    }

    /** @return array<string, mixed> */
    public function present(Operation $operation): array
    {
        return [
            'id' => (int) $operation->getId(),
            'date' => $operation->getDate()->format('Y-m-d'),
            'amount' => $operation->getAmount(),
            'rawLabel' => $operation->getRawLabel(),
            'normalizedLabel' => $operation->getNormalizedLabel(),
            'merchant' => $operation->getMerchant(),
            'account' => $this->accountPresenter->presentRef($operation->getAccount()),
            'category' => null !== $operation->getCategory() ? $this->categoryPresenter->presentRef($operation->getCategory()) : null,
            'categorizationSource' => $operation->getCategorizationSource()?->value,
            'appliedRuleId' => null !== $operation->getAppliedRule() ? (int) $operation->getAppliedRule()->getId() : null,
            'duplicateStatus' => $operation->getDuplicateStatus()->value,
            'duplicateOfId' => null !== $operation->getDuplicateOf() ? (int) $operation->getDuplicateOf()->getId() : null,
            'internalTransferId' => null !== $operation->getInternalTransfer() ? (int) $operation->getInternalTransfer()->getId() : null,
            'vehicle' => null !== $operation->getVehicle() ? $this->vehiclePresenter->presentRef($operation->getVehicle()) : null,
            'vehicleSource' => $operation->getVehicleSource()?->value,
            'note' => $operation->getNote(),
            'labelSource' => $operation->getLabelSource()?->value,
            'enrichedAt' => $operation->getEnrichedAt()?->format('c'),
        ];
    }
}
