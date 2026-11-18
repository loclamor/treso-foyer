<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Household;
use App\Entity\TaggedPeriod;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TaggedPeriod>
 */
final class TaggedPeriodRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TaggedPeriod::class);
    }

    /**
     * Périodes du foyer chevauchant [from, to] (bornes optionnelles) :
     * start_date <= to AND end_date >= from. Tri : startDate puis id.
     *
     * @return list<TaggedPeriod>
     */
    public function overlapping(Household $household, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to): array
    {
        $qb = $this->createQueryBuilder('tp')
            ->where('tp.household = :household')
            ->orderBy('tp.startDate', 'ASC')
            ->addOrderBy('tp.id', 'ASC')
            ->setParameter('household', $household);
        if (null !== $to) {
            $qb->andWhere('tp.startDate <= :to')->setParameter('to', $to);
        }
        if (null !== $from) {
            $qb->andWhere('tp.endDate >= :from')->setParameter('from', $from);
        }

        return $qb->getQuery()->getResult();
    }
}
