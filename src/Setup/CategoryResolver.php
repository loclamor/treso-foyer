<?php

declare(strict_types=1);

namespace App\Setup;

use App\Entity\Category;
use App\Entity\Household;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Résout une catégorie du foyer à partir de son nom complet « Parent › Enfant »
 * (ou du seul nom pour une racine), tel qu'affiché par Category::getFullName().
 */
final class CategoryResolver
{
    public const SEPARATOR = ' › ';

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function find(Household $household, string $fullName): ?Category
    {
        $parts = explode(self::SEPARATOR, $fullName);
        $repository = $this->entityManager->getRepository(Category::class);
        if (1 === count($parts)) {
            return $repository->findOneBy(['household' => $household, 'parent' => null, 'name' => $parts[0]]);
        }
        $parent = $repository->findOneBy(['household' => $household, 'parent' => null, 'name' => $parts[0]]);
        if (null === $parent) {
            return null;
        }

        return $repository->findOneBy(['household' => $household, 'parent' => $parent, 'name' => $parts[1]]);
    }

    /**
     * @throws \InvalidArgumentException si la catégorie est introuvable
     */
    public function require(Household $household, string $fullName): Category
    {
        $category = $this->find($household, $fullName);
        if (null === $category) {
            throw new \InvalidArgumentException(sprintf('Catégorie introuvable dans l’arbre du foyer : « %s » (vérifier app:setup).', $fullName));
        }

        return $category;
    }
}
