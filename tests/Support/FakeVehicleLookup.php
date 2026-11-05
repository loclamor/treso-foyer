<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Vehicle\Lookup\VehicleLookup;
use App\Vehicle\Lookup\VehicleLookupException;
use App\Vehicle\Lookup\VehicleLookupResult;

/**
 * Faux service de recherche par plaque pour les tests fonctionnels, lié à
 * App\Vehicle\Lookup\VehicleLookup dans config/services_test.yaml.
 * État STATIQUE : le noyau est reboooté entre les requêtes du client de test,
 * l'instance du service est recréée à chaque boot.
 */
final class FakeVehicleLookup implements VehicleLookup
{
    public static bool $enabled = false;
    public static ?VehicleLookupResult $result = null;
    public static ?VehicleLookupException $exception = null;
    public static int $calls = 0;

    public static function reset(): void
    {
        self::$enabled = false;
        self::$result = null;
        self::$exception = null;
        self::$calls = 0;
    }

    public function isEnabled(): bool
    {
        return self::$enabled;
    }

    public function lookup(string $normalizedPlate): VehicleLookupResult
    {
        ++self::$calls;
        if (null !== self::$exception) {
            throw self::$exception;
        }
        if (null !== self::$result) {
            return self::$result;
        }

        throw new VehicleLookupException('Faux service non configuré.', VehicleLookupException::REASON_UNAVAILABLE);
    }
}
