<?php

declare(strict_types=1);

namespace App\Categorization;

use App\Entity\CategorizationRule;
use App\Entity\Category;
use App\Entity\Operation;
use App\Enum\CategorizationSource;
use App\Enum\RuleDirection;
use App\Enum\RuleMatchField;
use App\Enum\RuleMatchType;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Catégorisation manuelle d'une opération : pose la catégorie (source manual),
 * peut apprendre une règle (merchant exact, priorité 100) et appliquer cette règle
 * (ou à défaut la même catégorie en source similarity) aux autres opérations
 * non manuelles du foyer ayant exactement le même commerçant et le même signe.
 */
final class ManualCategorizer
{
    /** Priorité des règles apprises par catégorisation manuelle (avant les règles par défaut). */
    public const LEARNED_RULE_PRIORITY = 100;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Categorizer $categorizer,
    ) {
    }

    public function assign(Operation $op, Category $cat, bool $createRule = false, bool $applyToSimilar = true): ManualCategorizationResult
    {
        $op->categorize($cat, CategorizationSource::Manual);

        $rule = null;
        $merchant = $op->getMerchant();
        if ($createRule && null !== $merchant && '' !== $merchant) {
            $rule = $this->findOrCreateRule($op, $cat, $merchant);
        }

        $updated = 0;
        if ($applyToSimilar && null !== $merchant && '' !== $merchant) {
            $others = $this->entityManager->getConnection()->fetchAllAssociative(
                <<<'SQL'
                    SELECT o.id
                    FROM operation o
                    JOIN account a ON a.id = o.account_id
                    WHERE a.household_id = :household::int
                      AND o.id <> :id::int
                      AND o.merchant = :merchant::text
                      AND (o.amount < 0) = :debit::boolean
                      AND o.categorization_source IS DISTINCT FROM 'manual'
                    SQL,
                [
                    'household' => $op->getAccount()->getHousehold()->getId(),
                    'id' => $op->getId(),
                    'merchant' => $merchant,
                    'debit' => $op->isDebit(),
                ],
                [
                    'household' => ParameterType::INTEGER,
                    'id' => ParameterType::INTEGER,
                    'merchant' => ParameterType::STRING,
                    'debit' => ParameterType::BOOLEAN,
                ],
            );
            foreach ($others as $row) {
                $other = $this->entityManager->find(Operation::class, (int) $row['id']);
                if (!$other instanceof Operation) {
                    continue;
                }
                if (null !== $rule) {
                    $other->categorize($rule->getCategory(), CategorizationSource::Rule, $rule);
                } else {
                    $other->categorize($cat, CategorizationSource::Similarity);
                }
                ++$updated;
            }
        }

        $this->entityManager->flush();

        return new ManualCategorizationResult($updated, $rule);
    }

    /**
     * Retire la catégorie manuelle de l'opération, puis la re-catégorise automatiquement
     * (règles puis similarité) comme une opération non catégorisée.
     */
    public function clear(Operation $op): CategorizationReport
    {
        $op->uncategorize();
        $this->entityManager->flush();

        return $this->categorizer->categorize([$op], new CategorizeOptions(onlyUncategorized: true));
    }

    private function findOrCreateRule(Operation $op, Category $cat, string $merchant): CategorizationRule
    {
        $repository = $this->entityManager->getRepository(CategorizationRule::class);
        $existing = $repository->findOneBy([
            'household' => $op->getAccount()->getHousehold(),
            'category' => $cat,
            'matchField' => RuleMatchField::Merchant,
            'matchType' => RuleMatchType::Exact,
            'pattern' => $merchant,
        ]);
        if ($existing instanceof CategorizationRule) {
            return $existing;
        }

        $rule = new CategorizationRule($op->getAccount()->getHousehold(), $cat, RuleMatchField::Merchant, RuleMatchType::Exact, $merchant);
        $rule->setDirection(match (true) {
            $op->isDebit() => RuleDirection::Debit,
            $op->isCredit() => RuleDirection::Credit,
            default => RuleDirection::Any,
        });
        $rule->setPriority(self::LEARNED_RULE_PRIORITY);
        $this->entityManager->persist($rule);
        $this->entityManager->flush();

        return $rule;
    }
}
