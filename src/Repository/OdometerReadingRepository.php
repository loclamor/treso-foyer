<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\OdometerReading;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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
}
