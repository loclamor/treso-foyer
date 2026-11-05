<?php

declare(strict_types=1);

namespace App\Vehicle\Lookup;

/**
 * Caractéristiques d'un véhicule trouvées par plaque. La réponse brute du
 * fournisseur est toujours conservée dans $raw. Aucune plaque ni VIN n'est
 * journalisé en dehors de ce DTO (le VIN est une donnée privée, docs/adr/0007).
 */
final readonly class VehicleLookupResult
{
    /**
     * @param string|null $energy une des valeurs petrol|diesel|electric|hybrid|plugin_hybrid|lpg|other
     * @param array       $raw    réponse JSON brute du fournisseur (décodée)
     */
    public function __construct(
        public ?string $make,
        public ?string $model,
        public ?string $version,
        public ?string $energy,
        public ?int $fiscalPower,
        public ?\DateTimeImmutable $firstRegistrationAt,
        public ?string $vin,
        public array $raw,
    ) {
    }

    /** @return array{make: ?string, model: ?string, version: ?string, energy: ?string, fiscalPower: ?int, firstRegistrationAt: ?string, vin: ?string, raw: array} */
    public function toArray(): array
    {
        return [
            'make' => $this->make,
            'model' => $this->model,
            'version' => $this->version,
            'energy' => $this->energy,
            'fiscalPower' => $this->fiscalPower,
            'firstRegistrationAt' => $this->firstRegistrationAt?->format('Y-m-d'),
            'vin' => $this->vin,
            'raw' => $this->raw,
        ];
    }
}
