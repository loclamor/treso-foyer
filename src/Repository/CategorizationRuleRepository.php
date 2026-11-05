<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CategorizationRule;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CategorizationRule>
 */
final class CategorizationRuleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CategorizationRule::class);
    }
}
