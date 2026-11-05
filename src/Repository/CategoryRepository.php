<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Category;
use App\Entity\Household;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Category>
 */
final class CategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Category::class);
    }

    /**
     * Catégorie du foyer et toutes ses sous-catégories (arbre à 2 niveaux).
     *
     * @return list<int>
     */
    public function withDescendants(Household $household, int $categoryId): array
    {
        $ids = [$categoryId];
        $current = [$categoryId];
        // Arbre à 2 niveaux : deux itérations suffisent, boucle de garde par profondeur.
        for ($depth = 0; $depth < 5 && [] !== $current; ++$depth) {
            $children = $this->createQueryBuilder('c')
                ->select('c.id')
                ->where('c.household = :household')
                ->andWhere('c.parent IN (:parents)')
                ->setParameter('household', $household)
                ->setParameter('parents', $current)
                ->getQuery()->getSingleColumnResult();
            $current = array_map('intval', $children);
            $ids = array_merge($ids, $current);
        }

        return array_values(array_unique($ids));
    }
}
