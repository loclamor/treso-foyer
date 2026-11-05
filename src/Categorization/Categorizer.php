<?php

declare(strict_types=1);

namespace App\Categorization;

use App\Entity\CategorizationRule;
use App\Entity\Category;
use App\Entity\Household;
use App\Entity\Operation;
use App\Enum\CategorizationSource;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Moteur de catégorisation automatique (voir docs/adr/0006-categorisation.md) :
 * 1. règles activées du foyer (priority DESC, id ASC) ;
 * 2. sinon similarité pg_trgm avec les opérations catégorisées manuellement de même signe ;
 * 3. sinon rien (ou reset si demandé).
 * Les opérations catégorisées manuellement et les virements internes liés ne sont jamais modifiées.
 */
final class Categorizer
{
    private const BATCH_SIZE = 500;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SimilarityFinder $similarityFinder,
        private readonly int $batchSize = self::BATCH_SIZE,
    ) {
    }

    /**
     * @param iterable<Operation> $operations
     */
    public function categorize(iterable $operations, CategorizeOptions $options): CategorizationReport
    {
        $report = new CategorizationReport();
        $household = null;
        /** @var list<CategorizationRule> $rules */
        $rules = [];
        /** @var array<int, Category> $categoriesById */
        $categoriesById = [];
        $connection = $this->entityManager->getConnection();
        $batch = 0;

        foreach ($operations as $operation) {
            if (!$operation instanceof Operation) {
                continue;
            }
            if (null === $household) {
                $household = $operation->getAccount()->getHousehold();
                $rules = $this->loadRules($household);
                foreach ($this->entityManager->getRepository(Category::class)->findBy(['household' => $household]) as $category) {
                    if (null !== $category->getId()) {
                        $categoriesById[$category->getId()] = $category;
                    }
                }
            }
            if (null !== $options->accountId && $operation->getAccount()->getId() !== $options->accountId) {
                continue;
            }
            // Jamais les opérations catégorisées manuellement ni les virements internes liés.
            if (CategorizationSource::Manual === $operation->getCategorizationSource()) {
                continue;
            }
            if (null !== $operation->getInternalTransfer()) {
                continue;
            }
            if ($options->onlyUncategorized && null !== $operation->getCategory()) {
                continue;
            }

            ++$report->processed;

            $matchedRule = null;
            foreach ($rules as $rule) {
                if ($rule->matches($operation)) {
                    $matchedRule = $rule;
                    break;
                }
            }
            if (null !== $matchedRule) {
                $operation->categorize($matchedRule->getCategory(), CategorizationSource::Rule, $matchedRule);
                ++$report->byRule;
            } else {
                $similarCategoryId = $this->similarityFinder->findSimilarCategoryId($household, $operation->getMerchant(), $operation->getNormalizedLabel(), $operation->isDebit(), $options->similarityThreshold);
                if (null !== $similarCategoryId && isset($categoriesById[$similarCategoryId])) {
                    $operation->categorize($categoriesById[$similarCategoryId], CategorizationSource::Similarity);
                    ++$report->bySimilarity;
                } elseif ($options->resetUnmatched
                    && null !== $operation->getCategory()
                    && null !== $operation->getCategorizationSource()
                    && in_array($operation->getCategorizationSource(), [CategorizationSource::Rule, CategorizationSource::Similarity, CategorizationSource::Llm], true)
                ) {
                    $operation->uncategorize();
                    ++$report->stillUncategorized;
                } elseif (null === $operation->getCategory()) {
                    ++$report->stillUncategorized;
                } else {
                    ++$report->unchanged;
                }
            }

            if (++$batch >= $this->batchSize) {
                $batch = 0;
                if (!$options->dryRun) {
                    $this->entityManager->flush();
                    $this->entityManager->clear();
                    // Après clear(), règles et catégories sont détachées : on les recharge au prochain tour.
                    $household = null;
                    $rules = [];
                    $categoriesById = [];
                }
            }
        }

        if (!$options->dryRun && $batch > 0) {
            $this->entityManager->flush();
        }

        return $report;
    }

    /**
     * @return list<CategorizationRule> règles activées, priority DESC puis id ASC
     */
    private function loadRules(Household $household): array
    {
        $rules = $this->entityManager->getRepository(CategorizationRule::class)->findBy(
            ['household' => $household, 'enabled' => true],
        );
        usort(
            $rules,
            static fn (CategorizationRule $a, CategorizationRule $b): int => $b->getPriority() <=> $a->getPriority()
                ?: ($a->getId() ?? 0) <=> ($b->getId() ?? 0),
        );

        return $rules;
    }
}
