<?php

declare(strict_types=1);

namespace App\Vehicle;

use App\Entity\Household;
use App\Entity\Vehicle;
use App\Enum\VehicleSource;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Synchronise l'axe véhicule de toutes les opérations non manuelles d'un foyer
 * (voir docs/adr/0006-categorisation.md, « Axe véhicule ») :
 *
 * - candidats = opérations dont `vehicleSource` ≠ `manual` ayant une catégorie
 *   `vehicleRelated`, OU une règle appliquée désignant un véhicule, OU un véhicule
 *   déjà affecté (pour pouvoir retirer une affectation devenue caduque) ;
 * - les affectations `manual` ne sont JAMAIS modifiées ;
 * - traitement idempotent.
 *
 * Efficace sur ~10 000 opérations : une seule lecture scalaire (DBAL), puis des
 * UPDATE groupés par (véhicule, source) et un UPDATE de remise à null.
 * Ne dépend pas de l'état de l'UnitOfWork (le Categorizer fait des `clear()`) :
 * tout passe par la connexion DBAL — à appeler APRÈS un flush.
 */
final class VehicleAssigner
{
    private const int CHUNK_SIZE = 1000;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly VehicleAssignmentPolicy $policy,
    ) {
    }

    public function sync(Household $household, bool $dryRun = false): VehicleAssignmentReport
    {
        $report = new VehicleAssignmentReport();
        $connection = $this->entityManager->getConnection();
        $householdId = (int) $household->getId();

        /** @var list<Vehicle> $vehicles */
        $vehicles = $this->entityManager->getRepository(Vehicle::class)->findBy(['household' => $householdId]);

        // Lecture en une requête scalaire de toutes les opérations candidates du foyer.
        $rows = $connection->fetchAllAssociative(
            <<<'SQL'
                SELECT o.id,
                       o.date,
                       o.vehicle_id,
                       o.vehicle_source,
                       r.vehicle_id AS rule_vehicle_id,
                       CASE WHEN c.vehicle_related THEN 1 ELSE 0 END AS category_vehicle_related
                FROM operation o
                JOIN account a ON a.id = o.account_id
                LEFT JOIN categorization_rule r ON r.id = o.applied_rule_id
                LEFT JOIN category c ON c.id = o.category_id
                WHERE a.household_id = :household::int
                  AND (o.vehicle_source IS NULL OR o.vehicle_source <> 'manual')
                  AND (c.vehicle_related = true OR r.vehicle_id IS NOT NULL OR o.vehicle_id IS NOT NULL)
                ORDER BY o.id
                SQL,
            ['household' => $householdId],
            ['household' => ParameterType::INTEGER],
        );

        /** @var array<string, list<int>> $toAssign clés « vehicleId|source » => ids d'opérations */
        $toAssign = [];
        /** @var list<int> $toClear */
        $toClear = [];

        foreach ($rows as $row) {
            ++$report->processed;
            $decision = $this->policy->decide(
                null !== $row['rule_vehicle_id'] ? (int) $row['rule_vehicle_id'] : null,
                1 === (int) $row['category_vehicle_related'],
                new \DateTimeImmutable((string) $row['date']),
                $vehicles,
            );
            $currentVehicleId = null !== $row['vehicle_id'] ? (int) $row['vehicle_id'] : null;
            $currentSource = null !== $row['vehicle_source'] ? VehicleSource::from((string) $row['vehicle_source']) : null;

            if (null === $decision) {
                if (null !== $currentVehicleId) {
                    $toClear[] = (int) $row['id'];
                } else {
                    ++$report->unchanged;
                }
                continue;
            }
            if ($currentVehicleId === $decision['vehicleId'] && $currentSource === $decision['source']) {
                ++$report->unchanged;
                continue;
            }
            $key = $decision['vehicleId'].'|'.$decision['source']->value;
            $toAssign[$key][] = (int) $row['id'];
            if (VehicleSource::Rule === $decision['source']) {
                ++$report->byRule;
            } else {
                ++$report->auto;
            }
        }
        $report->cleared = count($toClear);

        if ($dryRun) {
            return $report;
        }

        // Mises à jour groupées : un UPDATE par (véhicule, source), par paquets d'id.
        foreach ($toAssign as $key => $ids) {
            [$vehicleId, $source] = explode('|', $key, 2);
            foreach (array_chunk($ids, self::CHUNK_SIZE) as $chunk) {
                $connection->executeStatement(
                    'UPDATE operation SET vehicle_id = :vehicleId, vehicle_source = :source WHERE id IN (:ids)',
                    ['vehicleId' => (int) $vehicleId, 'source' => $source, 'ids' => $chunk],
                    ['vehicleId' => ParameterType::INTEGER, 'source' => ParameterType::STRING, 'ids' => ArrayParameterType::INTEGER],
                );
            }
        }
        // Remise à null des affectations devenues caduques.
        foreach (array_chunk($toClear, self::CHUNK_SIZE) as $chunk) {
            $connection->executeStatement(
                'UPDATE operation SET vehicle_id = NULL, vehicle_source = NULL WHERE id IN (:ids)',
                ['ids' => $chunk],
                ['ids' => ArrayParameterType::INTEGER],
            );
        }

        return $report;
    }
}
