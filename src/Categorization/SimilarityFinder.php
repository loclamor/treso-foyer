<?php

declare(strict_types=1);

namespace App\Categorization;

use App\Entity\Household;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Recherche par similarité pg_trgm (seuil 0.6 par défaut, voir docs/adr/0006) :
 * opération du foyer catégorisée manuellement, de même signe, la plus similaire —
 * merchant des deux côtés si les deux en ont un, sinon normalized_label.
 * Partagé entre le Categorizer et l'API (suggestions de commerçants).
 */
final class SimilarityFinder
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return int|null category_id si similarité >= seuil, sinon null
     */
    public function findSimilarCategoryId(Household $household, ?string $merchant, string $label, bool $debit, float $threshold): ?int
    {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT o.category_id,
                       similarity(
                           CASE WHEN o.merchant IS NOT NULL AND :merchant::text IS NOT NULL THEN o.merchant ELSE o.normalized_label END,
                           CASE WHEN o.merchant IS NOT NULL AND :merchant::text IS NOT NULL THEN :merchant::text ELSE :label::text END
                       ) AS sim
                FROM operation o
                JOIN account a ON a.id = o.account_id
                WHERE a.household_id = :household::int
                  AND o.categorization_source = 'manual'
                  AND o.category_id IS NOT NULL
                  AND (o.amount < 0) = :debit::boolean
                ORDER BY sim DESC
                LIMIT 1
                SQL,
            [
                'household' => $household->getId(),
                'merchant' => $merchant,
                'label' => $label,
                'debit' => $debit,
            ],
            [
                'household' => ParameterType::INTEGER,
                'merchant' => ParameterType::STRING,
                'label' => ParameterType::STRING,
                'debit' => ParameterType::BOOLEAN,
            ],
        );
        if (!is_array($row) || null === $row['category_id'] || (float) $row['sim'] < $threshold) {
            return null;
        }

        return (int) $row['category_id'];
    }
}
