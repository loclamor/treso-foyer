<?php

declare(strict_types=1);

namespace App\Api\Presenter;

use App\Entity\AdblueFill;

/** Présentation d'un remplissage d'AdBlue (ADR 0016, contrat docs/api.md). */
final class AdblueFillPresenter
{
    /** @return array<string, mixed> */
    public function present(AdblueFill $fill): array
    {
        $operation = $fill->getOperation();
        $cost = $fill->getCost();

        return [
            'id' => (int) $fill->getId(),
            'date' => $fill->getDate()->format('Y-m-d'),
            'volumeMl' => $fill->getVolumeMl(),
            'odometerKm' => $fill->getOdometerKm(),
            'source' => $fill->getSource()->value,
            'operation' => null !== $operation ? [
                'id' => (int) $operation->getId(),
                'date' => $operation->getEffectiveDate()->format('Y-m-d'),
                'amount' => $operation->getAmount(),
                'label' => $operation->getRawLabel(),
                'awaitingStatement' => $operation->isAwaitingStatement(),
            ] : null,
            'canPrice' => $fill->getCanPrice(),
            'canVolumeMl' => $fill->getCanVolumeMl(),
            'cost' => $cost,
            'costEstimated' => $fill->isCostEstimated(),
            // €/L, 3 décimales : coût (centimes) × 10 / volume (mL).
            'pricePerLiter' => null !== $cost ? round($cost * 10 / $fill->getVolumeMl(), 3) : null,
            'note' => $fill->getNote(),
        ];
    }
}
