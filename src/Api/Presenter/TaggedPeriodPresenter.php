<?php

declare(strict_types=1);

namespace App\Api\Presenter;

use App\Entity\TaggedPeriod;

final class TaggedPeriodPresenter
{
    public function __construct(private readonly VehiclePresenter $vehiclePresenter)
    {
    }

    /** @return array<string, mixed> */
    public function present(TaggedPeriod $period): array
    {
        return [
            'id' => (int) $period->getId(),
            'type' => $period->getType()->value,
            'label' => $period->getLabel(),
            'startDate' => $period->getStartDate()->format('Y-m-d'),
            'endDate' => $period->getEndDate()->format('Y-m-d'),
            'vehicle' => null !== $period->getVehicle() ? $this->vehiclePresenter->presentRef($period->getVehicle()) : null,
            'vehicleOverridesRules' => $period->isVehicleOverridesRules(),
        ];
    }
}
