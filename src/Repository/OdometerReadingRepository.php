<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\OdometerReading;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OdometerReading>
 */
final class OdometerReadingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OdometerReading::class);
    }

    /**
     * Relevé le plus récent par véhicule (une seule requête, DISTINCT ON PostgreSQL).
     *
     * @param list<int> $vehicleIds
     * @return array<int, array{date: string, km: int}> indexé par vehicle_id
     */
    public function latestByVehicleIds(array $vehicleIds): array
    {
        if ([] === $vehicleIds) {
            return [];
        }
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT DISTINCT ON (vehicle_id) vehicle_id, date, km
             FROM odometer_reading
             WHERE vehicle_id IN (:ids)
             ORDER BY vehicle_id, date DESC',
            ['ids' => array_values($vehicleIds)],
            ['ids' => ArrayParameterType::INTEGER],
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
}
