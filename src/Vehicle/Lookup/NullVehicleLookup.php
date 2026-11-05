<?php

declare(strict_types=1);

namespace App\Vehicle\Lookup;

/**
 * Implémentation par défaut quand aucun fournisseur n'est configuré
 * (VEHICLE_LOOKUP_PROVIDER=none).
 */
final class NullVehicleLookup implements VehicleLookup
{
    public function isEnabled(): bool
    {
        return false;
    }

    public function lookup(string $normalizedPlate): VehicleLookupResult
    {
        throw new VehicleLookupException(
            'Aucun service de recherche par plaque n’est configuré.',
            VehicleLookupException::REASON_UNAVAILABLE,
        );
    }
}
