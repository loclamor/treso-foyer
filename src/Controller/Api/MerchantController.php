<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Api\BadRequestException;
use App\Api\HouseholdProvider;
use App\Api\Input\MerchantCategorizeInput;
use App\Api\Pagination;
use App\Api\Presenter\CategoryPresenter;
use App\Api\Scope;
use App\Api\ValidationException;
use App\Categorization\ManualCategorizer;
use App\Categorization\SimilarityFinder;
use App\Entity\Category;
use App\Entity\CategorizationRule;
use App\Entity\Household;
use App\Entity\Operation;
use App\Enum\CategorizationSource;
use App\Enum\RuleDirection;
use App\Enum\RuleMatchField;
use App\Enum\RuleMatchType;
use App\Util\Text;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Écran « à catégoriser » : groupes de commerçants (contrat docs/api.md).
 */
final class MerchantController extends AbstractController
{
    private const float SIMILARITY_THRESHOLD = 0.6;

    #[Route('/api/merchants', name: 'api_merchants', methods: ['GET'])]
    public function list(
        Request $request,
        HouseholdProvider $householdProvider,
        Scope $scope,
        EntityManagerInterface $entityManager,
        SimilarityFinder $similarityFinder,
        CategoryPresenter $categoryPresenter,
    ): JsonResponse {
        $household = $householdProvider->get();
        $accountIds = $scope->accountIds($request, $household);
        [$whereSql, $params, $types] = $this->whereSql($request, $accountIds);
        $params['household'] = $household->getId();
        $types['household'] = ParameterType::INTEGER;

        $sort = (string) $request->query->get('sort', 'count');
        $order = strtolower((string) $request->query->get('order', 'desc'));
        if (!in_array($sort, ['count', 'total'], true) || !in_array($order, ['asc', 'desc'], true)) {
            throw new BadRequestException('Paramètres sort/order invalides.', 'invalid_query');
        }
        $orderSql = 'count' === $sort ? 'cnt' : 'total';

        $pagination = Pagination::fromRequest($request);
        $connection = $entityManager->getConnection();

        // first_date / last_date sur la date effective COALESCE(spent_at, date) (ADR 0014).
        $eff = Operation::effectiveDateSql('o');
        $groupSql = "SELECT COALESCE(o.merchant, o.normalized_label) AS grp,
                            MAX(o.merchant) AS merchant,
                            (o.amount < 0) AS debit,
                            COUNT(*) AS cnt,
                            SUM(o.amount) AS total,
                            MIN(o.amount) AS min_amount,
                            MAX(o.amount) AS max_amount,
                            MIN($eff) AS first_date,
                            MAX($eff) AS last_date,
                            MIN(o.raw_label) AS sample_label
                     FROM operation o
                     JOIN account a ON a.id = o.account_id
                     WHERE $whereSql
                     GROUP BY grp, (o.amount < 0)";

        $total = (int) $connection->fetchOne(
            "SELECT COUNT(*) FROM ($groupSql) g",
            $params,
            $types,
        );

        $rows = $connection->fetchAllAssociative(
            "$groupSql ORDER BY $orderSql ".strtoupper($order).", grp ASC LIMIT :limit OFFSET :offset",
            $params + ['limit' => $pagination->perPage, 'offset' => $pagination->offset()],
            $types + ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );

        // Répartition par catégorie sur les groupes de la page (mêmes filtres).
        $categoriesByGroup = [];
        if ([] !== $rows) {
            $repartition = $connection->fetchAllAssociative(
                "SELECT COALESCE(o.merchant, o.normalized_label) AS grp, (o.amount < 0) AS debit, o.category_id, COUNT(*) AS cnt
                 FROM operation o
                 JOIN account a ON a.id = o.account_id
                 WHERE $whereSql
                 GROUP BY grp, (o.amount < 0), o.category_id",
                $params,
                $types,
            );
            foreach ($repartition as $row) {
                $key = (string) $row['grp'].'|'.(self::toBool($row['debit']) ? '1' : '0');
                $categoriesByGroup[$key][] = ['categoryId' => null !== $row['category_id'] ? (int) $row['category_id'] : null, 'count' => (int) $row['cnt']];
            }
        }

        // Catégories du foyer pour les références et suggestions.
        $categories = [];
        foreach ($entityManager->getRepository(Category::class)->findBy(['household' => $household]) as $category) {
            $categories[(int) $category->getId()] = $category;
        }

        $items = [];
        foreach ($rows as $row) {
            $key = (string) $row['grp'];
            $debit = self::toBool($row['debit']);
            $merchant = null !== $row['merchant'] ? (string) $row['merchant'] : null;
            $groupKey = $key.'|'.($debit ? '1' : '0');

            $suggestion = null;
            $suggestedCategoryId = $similarityFinder->findSimilarCategoryId($household, $merchant, $key, $debit, self::SIMILARITY_THRESHOLD);
            if (null !== $suggestedCategoryId && isset($categories[$suggestedCategoryId])) {
                $suggestion = ['category' => $categoryPresenter->presentRef($categories[$suggestedCategoryId]), 'source' => 'similarity'];
            }

            $items[] = [
                'key' => $key,
                'merchant' => $merchant,
                'direction' => $debit ? 'debit' : 'credit',
                'count' => (int) $row['cnt'],
                'total' => (int) $row['total'],
                'minAmount' => (int) $row['min_amount'],
                'maxAmount' => (int) $row['max_amount'],
                'firstDate' => (new \DateTimeImmutable((string) $row['first_date']))->format('Y-m-d'),
                'lastDate' => (new \DateTimeImmutable((string) $row['last_date']))->format('Y-m-d'),
                'categories' => array_map(
                    static fn (array $entry): array => [
                        'category' => null !== $entry['categoryId'] && isset($categories[$entry['categoryId']])
                            ? $categoryPresenter->presentRef($categories[$entry['categoryId']])
                            : null,
                        'count' => $entry['count'],
                    ],
                    $categoriesByGroup[$groupKey] ?? [],
                ),
                'suggestion' => $suggestion,
                'sampleLabel' => (string) $row['sample_label'],
            ];
        }

        return new JsonResponse($pagination->envelope($items, $total));
    }

    #[Route('/api/merchants/categorize', name: 'api_merchants_categorize', methods: ['POST'])]
    public function categorize(
        #[MapRequestPayload] MerchantCategorizeInput $input,
        HouseholdProvider $householdProvider,
        EntityManagerInterface $entityManager,
        ManualCategorizer $manualCategorizer,
        \App\Api\Presenter\RulePresenter $rulePresenter,
    ): JsonResponse {
        $household = $householdProvider->get();
        $category = $entityManager->getRepository(Category::class)->findOneBy(['id' => $input->categoryId, 'household' => $household]);
        if (!$category instanceof Category) {
            throw new ValidationException([['field' => 'categoryId', 'message' => 'Catégorie introuvable dans le foyer.']]);
        }
        $debit = 'debit' === $input->direction;

        // Opérations non manuelles du groupe (clé + sens), périmètre ignoré.
        $operations = $entityManager->getRepository(Operation::class)->createQueryBuilder('o')
            ->join('o.account', 'a')
            ->where('a.household = :household')
            ->andWhere('COALESCE(o.merchant, o.normalizedLabel) = :key')
            ->andWhere($debit ? 'o.amount < 0' : 'o.amount > 0')
            ->andWhere('o.categorizationSource IS NULL OR o.categorizationSource != :manual')
            ->setParameter('household', $household)
            ->setParameter('key', (string) $input->key)
            ->setParameter('manual', CategorizationSource::Manual->value)
            ->getQuery()->getResult();
        \assert([] === array_filter($operations, static fn ($o): bool => !$o instanceof Operation));

        $rule = null;
        $updatedCount = 0;
        $merchant = null;
        foreach ($operations as $operation) {
            if ($operation instanceof Operation && null === $merchant && null !== $operation->getMerchant()) {
                $merchant = $operation->getMerchant();
            }
        }

        if (null !== $merchant && [] !== $operations) {
            // Groupe avec commerçant : on réutilise ManualCategorizer (règle merchant exact + propagation).
            $representative = null;
            foreach ($operations as $operation) {
                if ($operation instanceof Operation) {
                    $representative = $operation;
                    break;
                }
            }
            \assert($representative instanceof Operation);
            $result = $manualCategorizer->assign($representative, $category, createRule: $input->createRule, applyToSimilar: true);
            $rule = $result->rule;
            $updatedCount = $result->updatedCount + 1; // l'opération représentative est incluse
        } else {
            // Groupe sans commerçant (clé = libellé normalisé) : catégorie manuelle + règle sur le libellé exact.
            foreach ($operations as $operation) {
                if ($operation instanceof Operation) {
                    $operation->categorize($category, CategorizationSource::Manual);
                    ++$updatedCount;
                }
            }
            if ($input->createRule) {
                $rule = new CategorizationRule($household, $category, RuleMatchField::Label, RuleMatchType::Exact, (string) $input->key);
                $rule->setDirection($debit ? RuleDirection::Debit : RuleDirection::Credit);
                $rule->setPriority(ManualCategorizer::LEARNED_RULE_PRIORITY);
                $entityManager->persist($rule);
            }
            $entityManager->flush();
        }

        return new JsonResponse([
            'updatedCount' => $updatedCount,
            'rule' => null !== $rule ? $rulePresenter->present($rule) : null,
        ]);
    }

    /** PDO pgsql renvoie les booléens tantôt en bool, tantôt en « t »/« f ». */
    private static function toBool(mixed $value): bool
    {
        return true === $value || 't' === $value || '1' === $value || 1 === $value;
    }

    /**
     * Clause WHERE commune aux requêtes de groupes (périmètre, uncategorized, q, from/to).
     *
     * @return array{0: string, 1: array<string, mixed>, 2: array<string, mixed>}
     */
    private function whereSql(Request $request, ?array $accountIds): array
    {
        $conditions = ['a.household_id = :household'];
        $params = [];
        $types = [];

        if (null !== $accountIds) {
            $conditions[] = 'a.id IN (:scopeAccountIds)';
            $params['scopeAccountIds'] = $accountIds;
            $types['scopeAccountIds'] = ArrayParameterType::INTEGER;
        }
        if (in_array((string) $request->query->get('uncategorized', '0'), ['1', 'true'], true)) {
            $conditions[] = 'o.category_id IS NULL';
        }
        // from/to portent sur la date effective (COALESCE(spent_at, date), ADR 0014).
        $eff = Operation::effectiveDateSql('o');
        $from = $request->query->get('from');
        if (null !== $from && '' !== $from) {
            $conditions[] = $eff.' >= :from';
            $params['from'] = new \DateTimeImmutable((string) $from);
            $types['from'] = Types::DATE_IMMUTABLE;
        }
        $to = $request->query->get('to');
        if (null !== $to && '' !== $to) {
            $conditions[] = $eff.' <= :to';
            $params['to'] = new \DateTimeImmutable((string) $to);
            $types['to'] = Types::DATE_IMMUTABLE;
        }
        $q = $request->query->get('q');
        if (null !== $q && '' !== trim((string) $q)) {
            $q = trim((string) $q);
            $conditions[] = '(UPPER(o.normalized_label) LIKE :qFolded OR UPPER(o.merchant) LIKE :qFolded OR o.raw_label ILIKE :qRaw)';
            $folded = Text::fold($q);
            $params['qFolded'] = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $folded).'%';
            $params['qRaw'] = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($q)).'%';
        }

        return [implode(' AND ', $conditions), $params, $types];
    }
}
