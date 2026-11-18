<?php

declare(strict_types=1);

namespace App\Vehicle;

use App\Entity\Vehicle;
use App\Enum\VehicleSource;

/**
 * Politique d'affectation d'une opération à un véhicule (axe indépendant de la catégorie,
 * voir docs/adr/0006-categorisation.md, 0011 et 0018). Une « dépense de transport » est une
 * opération de catégorie `vehicleRelated` hors `financing` (une mensualité de crédit reste
 * au véhicule financé). Ordre de décision :
 * 1. période labellisée avec véhicule **prioritaire sur les règles** couvrant la date d'une
 *    dépense de transport ⇒ ce véhicule, source `period` ;
 * 2. la règle appliquée à l'opération désigne un véhicule ⇒ ce véhicule, source `rule` ;
 * 3. période labellisée avec véhicule (non prioritaire) couvrant la date d'une dépense de
 *    transport ⇒ ce véhicule, source `period` ;
 * 4. catégorie `vehicleRelated` et exactement UN véhicule du foyer actif à la date
 *    (`Vehicle::isActiveOn`) ⇒ ce véhicule, source `auto` ;
 * 5. sinon ⇒ aucune affectation.
 *
 * Aux étapes 1 et 3, plusieurs périodes couvrant la date avec des véhicules différents
 * sont ambiguës : l'étape est ignorée. Le véhicule d'une période s'applique même s'il
 * n'est pas actif à la date (choix explicite de l'utilisateur).
 *
 * Classe pure (aucun accès Doctrine) : testée unitairement.
 */
final class VehicleAssignmentPolicy
{
    /**
     * @param list<Vehicle>       $vehicles véhicules du foyer (l'ordre n'a pas d'importance)
     * @param list<PeriodVehicle> $periods  périodes labellisées du foyer ayant un véhicule
     *
     * @return ?array{vehicleId: int, source: VehicleSource} null = aucune affectation
     */
    public function decide(
        ?int $ruleVehicleId,
        bool $categoryVehicleRelated,
        \DateTimeImmutable $date,
        array $vehicles,
        bool $categoryFinancing = false,
        array $periods = [],
    ): ?array {
        $transportExpense = $categoryVehicleRelated && !$categoryFinancing;

        // 1. Période prioritaire sur les règles.
        if ($transportExpense && null !== $vehicleId = $this->periodVehicle($periods, $date, true)) {
            return ['vehicleId' => $vehicleId, 'source' => VehicleSource::Period];
        }

        // 2. Règle appliquée désignant un véhicule.
        if (null !== $ruleVehicleId) {
            return ['vehicleId' => $ruleVehicleId, 'source' => VehicleSource::Rule];
        }

        // 3. Période (non prioritaire).
        if ($transportExpense && null !== $vehicleId = $this->periodVehicle($periods, $date, false)) {
            return ['vehicleId' => $vehicleId, 'source' => VehicleSource::Period];
        }

        // 4. Un seul véhicule actif à la date de l'opération.
        if ($categoryVehicleRelated) {
            $active = array_values(
                array_filter($vehicles, static fn (Vehicle $vehicle): bool => $vehicle->isActiveOn($date)),
            );
            if (1 === count($active) && null !== $active[0]->getId()) {
                return ['vehicleId' => $active[0]->getId(), 'source' => VehicleSource::Auto];
            }
        }

        // 5. Rien.
        return null;
    }

    /**
     * Véhicule unique désigné par les périodes de ce niveau de priorité couvrant la date ;
     * null si aucune période ne couvre la date ou si elles désignent des véhicules différents.
     *
     * @param list<PeriodVehicle> $periods
     */
    private function periodVehicle(array $periods, \DateTimeImmutable $date, bool $overridesRules): ?int
    {
        $ids = [];
        foreach ($periods as $period) {
            if ($period->overridesRules === $overridesRules && $period->covers($date)) {
                $ids[$period->vehicleId] = true;
            }
        }

        return 1 === count($ids) ? (int) array_key_first($ids) : null;
    }
}
