<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\OdometerReading;
use App\Entity\Vehicle;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OdometerReading>
 *
 * Les points de compteur d'un véhicule = relevés `odometer_reading` UNION pleins des
 * opérations (`vehicle_id` non nul, `odometer_km` non nul, date effective
 * COALESCE(spent_at, date), ADR 0015) UNION remplissages d'AdBlue avec kilométrage
 * (ADR 0016). À date égale, le relevé saisi prime (source 0, puis plein 1, puis AdBlue 2) :
 * OdometerCalculator garde le premier point d'une date.
 */
final class OdometerReadingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OdometerReading::class);
    }

    /**
     * Relevé le plus récent par véhicule (une seule requête, DISTINCT ON PostgreSQL).
     * À date égale, le relevé saisi (source 0) est préféré au kilométrage d'un plein.
     *
     * @param list<int> $vehicleIds
     * @param ?int $excludeOperationId opération à exclure (ex. point du plein en cours de saisie)
     * @return array<int, array{date: string, km: int}> indexé par vehicle_id
     */
    public function latestByVehicleIds(array $vehicleIds, ?int $excludeOperationId = null): array
    {
        if ([] === $vehicleIds) {
            return [];
        }
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT DISTINCT ON (p.vehicle_id) p.vehicle_id, p.date, p.km
             FROM ('.$this->pointsUnionSql().') p
             ORDER BY p.vehicle_id, p.date DESC, p.source ASC',
            $this->pointParams($vehicleIds, $excludeOperationId),
            $this->pointParamTypes(),
        );

        $latest = [];
        foreach ($rows as $row) {
            $latest[(int) $row['vehicle_id']] = [
                'date' => (new \DateTimeImmutable((string) $row['date']))->format('Y-m-d'),
                'km' => (int) $row['km'],
            ];
        }

        return $latest;
    }

    /** @return list<OdometerReading> relevés du véhicule, date desc puis id desc (GET /api/vehicles/{id}/odometer) */
    public function findByVehicleDesc(Vehicle $vehicle): array
    {
        return $this->findBy(['vehicle' => $vehicle], ['date' => 'DESC', 'id' => 'DESC']);
    }

    /**
     * @param ?int $excludeOperationId opération à exclure des points (ex. plein en cours de saisie)
     * @return list<array{date: string, km: int}> points {date, km} du véhicule, date asc (relevé avant plein à date égale)
     */
    public function findPointsByVehicle(Vehicle $vehicle, ?int $excludeOperationId = null, ?int $excludeAdblueFillId = null): array
    {
        return $this->pointsByVehicleIds([(int) $vehicle->getId()], $excludeOperationId, $excludeAdblueFillId)[(int) $vehicle->getId()] ?? [];
    }

    /**
     * Points {date, km} de plusieurs véhicules en une requête (relevés UNION pleins des
     * opérations), groupés par véhicule, date asc puis source asc (relevé = 0 avant plein = 1).
     *
     * @param list<int> $vehicleIds
     * @param ?int $excludeOperationId opération à exclure des points (ex. plein en cours de saisie)
     * @param ?int $excludeAdblueFillId remplissage d'AdBlue à exclure (ex. celui en cours de modification)
     * @return array<int, list<array{date: string, km: int}>> indexé par vehicle_id
     */
    public function pointsByVehicleIds(array $vehicleIds, ?int $excludeOperationId = null, ?int $excludeAdblueFillId = null): array
    {
        if ([] === $vehicleIds) {
            return [];
        }
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT p.vehicle_id, p.date, p.km
             FROM ('.$this->pointsUnionSql().') p
             ORDER BY p.date ASC, p.source ASC',
            $this->pointParams($vehicleIds, $excludeOperationId, $excludeAdblueFillId),
            $this->pointParamTypes(),
        );

        $points = [];
        foreach ($rows as $row) {
            $points[(int) $row['vehicle_id']][] = [
                'date' => (new \DateTimeImmutable((string) $row['date']))->format('Y-m-d'),
                'km' => (int) $row['km'],
            ];
        }

        return $points;
    }

    /**
     * UNION ALL des points de compteur : relevés (source 0), pleins des opérations
     * (source 1, date effective COALESCE(spent_at, date), odometer_km non nul), puis
     * remplissages d'AdBlue (source 2, odometer_km non nul, ADR 0016).
     */
    private function pointsUnionSql(): string
    {
        return <<<'SQL'
            SELECT r.vehicle_id, r.date, r.km, 0 AS source
            FROM odometer_reading r
            WHERE r.vehicle_id IN (:ids)
            UNION ALL
            SELECT o.vehicle_id, COALESCE(o.spent_at, o.date) AS date, o.odometer_km AS km, 1 AS source
            FROM operation o
            WHERE o.vehicle_id IN (:ids)
              AND o.odometer_km IS NOT NULL
              AND (CAST(:excludeOperationId AS INTEGER) IS NULL OR o.id <> :excludeOperationId)
            UNION ALL
            SELECT f.vehicle_id, f.date, f.odometer_km AS km, 2 AS source
            FROM adblue_fill f
            WHERE f.vehicle_id IN (:ids)
              AND f.odometer_km IS NOT NULL
              AND (CAST(:excludeAdblueFillId AS INTEGER) IS NULL OR f.id <> :excludeAdblueFillId)
            SQL;
    }

    /** @param list<int> $vehicleIds @return array{ids: list<int>, excludeOperationId: ?int, excludeAdblueFillId: ?int} */
    private function pointParams(array $vehicleIds, ?int $excludeOperationId, ?int $excludeAdblueFillId = null): array
    {
        return ['ids' => array_values($vehicleIds), 'excludeOperationId' => $excludeOperationId, 'excludeAdblueFillId' => $excludeAdblueFillId];
    }

    /** @return array{ids: ArrayParameterType, excludeOperationId: ParameterType, excludeAdblueFillId: ParameterType} */
    private function pointParamTypes(): array
    {
        return ['ids' => ArrayParameterType::INTEGER, 'excludeOperationId' => ParameterType::INTEGER, 'excludeAdblueFillId' => ParameterType::INTEGER];
    }
}
