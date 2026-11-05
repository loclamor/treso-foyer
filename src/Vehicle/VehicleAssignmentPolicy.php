<?php

declare(strict_types=1);

namespace App\Vehicle;

use App\Entity\Vehicle;
use App\Enum\VehicleSource;

/**
 * Politique d'affectation d'une opération à un véhicule (axe indépendant de la catégorie,
 * voir docs/adr/0006-categorisation.md) :
 * 1. la règle appliquée à l'opération désigne un véhicule ⇒ ce véhicule, source `rule` ;
 * 2. sinon, catégorie `vehicleRelated` et exactement UN véhicule du foyer actif à la date
 *    de l'opération (`Vehicle::isActiveOn`) ⇒ ce véhicule, source `auto` ;
 * 3. sinon ⇒ aucune affectation.
 *
 * Classe pure (aucun accès Doctrine) : testée unitairement.
 */
final class VehicleAssignmentPolicy
{
    /**
     * @param list<Vehicle> $vehicles véhicules du foyer (l'ordre n'a pas d'importance)
     *
     * @return ?array{vehicleId: int, source: VehicleSource} null = aucune affectation
     */
    public function decide(?int $ruleVehicleId, bool $categoryVehicleRelated, \DateTimeImmutable $date, array $vehicles): ?array
    {
        // 1. Règle appliquée désignant un véhicule : prioritaire sur tout le reste.
        if (null !== $ruleVehicleId) {
            return ['vehicleId' => $ruleVehicleId, 'source' => VehicleSource::Rule];
        }

        // 2. Un seul véhicule actif à la date de l'opération.
        if ($categoryVehicleRelated) {
            $active = array_values(
                array_filter($vehicles, static fn (Vehicle $vehicle): bool => $vehicle->isActiveOn($date)),
            );
            if (1 === count($active) && null !== $active[0]->getId()) {
                return ['vehicleId' => $active[0]->getId(), 'source' => VehicleSource::Auto];
            }
        }

        // 3. Rien.
        return null;
    }
}
