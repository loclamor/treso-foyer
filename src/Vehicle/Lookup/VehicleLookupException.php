<?php

declare(strict_types=1);

namespace App\Vehicle\Lookup;

/**
 * Erreur de recherche de véhicule par plaque. Le message est toujours en français
 * et ne contient JAMAIS la plaque (donnée privée, voir docs/adr/0007).
 */
final class VehicleLookupException extends \RuntimeException
{
    public const REASON_NOT_FOUND = 'not_found';
    public const REASON_QUOTA = 'quota';
    public const REASON_UNAVAILABLE = 'unavailable';
    public const REASON_INVALID_RESPONSE = 'invalid_response';

    /**
     * @param string $reason une des constantes REASON_* (not_found, quota, unavailable, invalid_response)
     */
    public function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }
}
