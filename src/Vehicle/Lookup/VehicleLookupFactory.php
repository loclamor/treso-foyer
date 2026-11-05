<?php

declare(strict_types=1);

namespace App\Vehicle\Lookup;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Construit le service de recherche par plaque selon VEHICLE_LOOKUP_PROVIDER :
 * « none » (ou toute valeur inconnue) → NullVehicleLookup,
 * « rapidapi » → RapidApiVehicleLookup (désactivé si VEHICLE_LOOKUP_TOKEN est vide).
 */
final class VehicleLookupFactory
{
    public function __construct(
        #[Autowire('%env(VEHICLE_LOOKUP_PROVIDER)%')]
        private readonly string $provider,
        private readonly HttpClientInterface $client,
        #[Autowire('%env(VEHICLE_LOOKUP_TOKEN)%')]
        private readonly string $token,
    ) {
    }

    public function create(): VehicleLookup
    {
        if ('rapidapi' === mb_strtolower(trim($this->provider))) {
            return new RapidApiVehicleLookup($this->client, $this->token);
        }

        return new NullVehicleLookup();
    }
}
