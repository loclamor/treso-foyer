<?php

declare(strict_types=1);

namespace App\Api\Presenter;

use App\Entity\Vehicle;

/**
 * Présentation des véhicules. `lookupData` (réponse brute d'une API externe, donnée
 * privée, docs/adr/0007) n'est JAMAIS exposé : seul `lookupAt` l'est.
 */
final class VehiclePresenter
{
    public function __construct(
        private readonly MemberPresenter $memberPresenter,
    ) {
    }

    /** @return array{id: int, name: string, type: string, archived: bool} */
    public function presentRef(Vehicle $vehicle): array
    {
        return [
            'id' => (int) $vehicle->getId(),
            'name' => $vehicle->getName(),
            'type' => $vehicle->getType()->value,
            'archived' => $vehicle->isArchived(),
        ];
    }

    /**
     * @param ?array{date: string, km: int} $lastOdometer relevé le plus récent ({date, km}) ou null
     * @return array<string, mixed>
     */
    public function present(Vehicle $vehicle, int $operationsCount = 0, ?array $lastOdometer = null): array
    {
        return $this->presentRef($vehicle) + [
            'plate' => $vehicle->getPlate(),
            'make' => $vehicle->getMake(),
            'model' => $vehicle->getModel(),
            'energy' => $vehicle->getEnergy()?->value,
            'fiscalPower' => $vehicle->getFiscalPower(),
            'firstRegistrationAt' => $vehicle->getFirstRegistrationAt()?->format('Y-m-d'),
            'vin' => $vehicle->getVin(),
            'acquiredAt' => $vehicle->getAcquiredAt()?->format('Y-m-d'),
            'disposedAt' => $vehicle->getDisposedAt()?->format('Y-m-d'),
            'purchasePrice' => $vehicle->getPurchasePrice(),
            'mainDriver' => null !== $vehicle->getMainDriver() ? $this->memberPresenter->present($vehicle->getMainDriver()) : null,
            'lookupAt' => $vehicle->getLookupAt()?->format('c'),
            'operationsCount' => $operationsCount,
            'lastOdometer' => $lastOdometer,
        ];
    }
}
