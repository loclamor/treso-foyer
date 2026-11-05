<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\RecurringSeries;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RecurringSeries>
 */
final class RecurringSeriesRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RecurringSeries::class);
    }
}
