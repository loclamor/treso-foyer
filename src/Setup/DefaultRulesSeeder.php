<?php

declare(strict_types=1);

namespace App\Setup;

use App\Entity\CategorizationRule;
use App\Entity\Household;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Insère les règles par défaut (DefaultRules) pour un foyer.
 * Idempotent : ne recrée pas une règle de même (catégorie, champ, type, motif).
 */
final class DefaultRulesSeeder
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CategoryResolver $categoryResolver,
    ) {
    }

    /**
     * @return int nombre de règles créées
     */
    public function seed(Household $household): int
    {
        $repository = $this->entityManager->getRepository(CategorizationRule::class);
        $created = 0;
        foreach (DefaultRules::all() as [$categoryName, $matchField, $matchType, $pattern, $direction, $priority]) {
            $category = $this->categoryResolver->require($household, $categoryName);
            $existing = $repository->findOneBy([
                'household' => $household,
                'category' => $category,
                'matchField' => $matchField,
                'matchType' => $matchType,
                'pattern' => $pattern,
            ]);
            if ($existing instanceof CategorizationRule) {
                continue;
            }
            $rule = new CategorizationRule($household, $category, $matchField, $matchType, $pattern);
            $rule->setDirection($direction);
            $rule->setPriority($priority);
            $this->entityManager->persist($rule);
            ++$created;
        }
        $this->entityManager->flush();

        return $created;
    }
}
