<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\InternalTransfer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<InternalTransfer>
 */
final class InternalTransferRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InternalTransfer::class);
    }
}
