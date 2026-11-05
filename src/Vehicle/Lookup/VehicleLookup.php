<?php

declare(strict_types=1);

namespace App\Vehicle\Lookup;

/**
 * Service de recherche des caractéristiques d'un véhicule à partir de sa plaque.
 * La plaque transmise est toujours normalisée (sans espaces, en majuscules) ;
 * elle ne doit jamais apparaître dans les logs ni les exceptions.
 */
interface VehicleLookup
{
    /** true si un fournisseur réel est configuré (clé API renseignée). */
    public function isEnabled(): bool;

    /**
     * @param string $normalizedPlate plaque normalisée (ex. AB-123-CD)
     *
     * @throws VehicleLookupException si véhicule introuvable, quota atteint,
     *                                service indisponible ou réponse illisible
     */
    public function lookup(string $normalizedPlate): VehicleLookupResult;
}
