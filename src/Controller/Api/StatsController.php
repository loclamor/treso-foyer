<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Api\BadRequestException;
use App\Api\HouseholdProvider;
use App\Api\Presenter\AccountPresenter;
use App\Api\Presenter\CategoryPresenter;
use App\Api\Scope;
use App\Entity\Account;
use App\Entity\Category;
use App\Entity\Household;
use App\Repository\CategoryRepository;
use App\Stats\StatsPeriod;
use App\Stats\StatsRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Statistiques (contrat docs/api.md, étape 8) : périmètre Scope + période StatsPeriod,
 * agrégats SQL de StatsRepository, présentation CategoryRef/AccountRef.
 */
final class StatsController extends AbstractController
{
    #[Route('/api/stats/overview', name: 'api_stats_overview', methods: ['GET'])]
    public function overview(
        Request $request,
        HouseholdProvider $householdProvider,
        Scope $scope,
        StatsRepository $statsRepository,
    ): JsonResponse {
        $household = $householdProvider->get();
        $accountIds = $scope->accountIds($request, $household);
        $period = StatsPeriod::fromRequest($request);

        $current = $statsRepository->overview($household, $accountIds, $period);
        $previous = $statsRepository->overview($household, $accountIds, $period->previous());
        $average12 = $statsRepository->average12($household, $accountIds, $period->to);

        return new JsonResponse($current + [
            'previous' => [
                'from' => $previous['from'],
                'to' => $previous['to'],
                'income' => $previous['income'],
                'expense' => $previous['expense'],
                'net' => $previous['net'],
            ],
            'average12' => $average12,
        ]);
    }

    #[Route('/api/stats/monthly', name: 'api_stats_monthly', methods: ['GET'])]
    public function monthly(
        Request $request,
        HouseholdProvider $householdProvider,
        Scope $scope,
        StatsRepository $statsRepository,
        CategoryPresenter $categoryPresenter,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $household = $householdProvider->get();
        $accountIds = $scope->accountIds($request, $household);
        $period = StatsPeriod::fromRequest($request);
        $categories = $this->categoryMap($entityManager, $household);

        $data = $statsRepository->monthly($household, $accountIds, $period);

        return new JsonResponse([
            'months' => $data['months'],
            'income' => $data['income'],
            'expense' => $data['expense'],
            'net' => $data['net'],
            'byRoot' => array_map(
                static fn (array $entry): array => [
                    'category' => $categoryPresenter->presentRef($categories[$entry['rootId']]),
                    'values' => $entry['values'],
                ],
                $data['byRoot'],
            ),
            'uncategorized' => $data['uncategorized'],
        ]);
    }

    #[Route('/api/stats/categories', name: 'api_stats_categories', methods: ['GET'])]
    public function categories(
        Request $request,
        HouseholdProvider $householdProvider,
        Scope $scope,
        StatsRepository $statsRepository,
        CategoryPresenter $categoryPresenter,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $household = $householdProvider->get();
        $accountIds = $scope->accountIds($request, $household);
        $period = StatsPeriod::fromRequest($request);
        $direction = $this->directionParam($request);

        $parentId = null;
        $rawParentId = $request->query->get('parentId');
        if (null !== $rawParentId && '' !== $rawParentId) {
            if (!ctype_digit((string) $rawParentId)) {
                throw new BadRequestException('Paramètre parentId invalide.', 'invalid_query');
            }
            $parentId = (int) $rawParentId;
            $exists = $entityManager->getRepository(Category::class)->findOneBy(['id' => $parentId, 'household' => $household]);
            if (!$exists instanceof Category) {
                throw new BadRequestException('Catégorie introuvable.', 'invalid_query');
            }
        }

        $comparePeriod = null;
        $compare = $request->query->get('compare');
        if (null !== $compare && '' !== $compare) {
            $compare = (string) $compare;
            if ('previous' === $compare) {
                $comparePeriod = $period->previous();
            } elseif ('year' === $compare) {
                $comparePeriod = $period->sameLastYear();
            } else {
                throw new BadRequestException('Paramètre compare invalide (previous|year).', 'invalid_query');
            }
        }

        $categories = $this->categoryMap($entityManager, $household);
        $rows = $statsRepository->categories($household, $accountIds, $period, $direction, $parentId, $comparePeriod);

        return new JsonResponse(array_map(
            static fn (array $row): array => [
                'category' => null !== $row['categoryId'] ? $categoryPresenter->presentRef($categories[$row['categoryId']]) : null,
                'total' => $row['total'],
                'count' => $row['count'],
                'share' => $row['share'],
                'compareTotal' => $row['compareTotal'],
            ],
            $rows,
        ));
    }

    #[Route('/api/stats/merchants', name: 'api_stats_merchants', methods: ['GET'])]
    public function merchants(
        Request $request,
        HouseholdProvider $householdProvider,
        Scope $scope,
        StatsRepository $statsRepository,
        CategoryPresenter $categoryPresenter,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $household = $householdProvider->get();
        $accountIds = $scope->accountIds($request, $household);
        $period = StatsPeriod::fromRequest($request);
        $direction = $this->directionParam($request);

        $rawLimit = (string) $request->query->get('limit', '10');
        if (!ctype_digit($rawLimit)) {
            throw new BadRequestException('Paramètre limit invalide (entier 1-50 attendu).', 'invalid_query');
        }
        $limit = (int) $rawLimit;
        if ($limit < 1 || $limit > 50) {
            throw new BadRequestException('Paramètre limit invalide (entier 1-50 attendu).', 'invalid_query');
        }

        $categories = $this->categoryMap($entityManager, $household);
        $rows = $statsRepository->merchants($household, $accountIds, $period, $direction, $limit);

        return new JsonResponse(array_map(
            static fn (array $row): array => [
                'key' => $row['key'],
                'merchant' => $row['merchant'],
                'total' => $row['total'],
                'count' => $row['count'],
                'category' => null !== $row['categoryId'] && isset($categories[$row['categoryId']])
                    ? $categoryPresenter->presentRef($categories[$row['categoryId']])
                    : null,
            ],
            $rows,
        ));
    }

    #[Route('/api/stats/balance', name: 'api_stats_balance', methods: ['GET'])]
    public function balance(
        Request $request,
        HouseholdProvider $householdProvider,
        Scope $scope,
        StatsRepository $statsRepository,
        AccountPresenter $accountPresenter,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $household = $householdProvider->get();
        $accountIds = $scope->accountIds($request, $household);
        $period = StatsPeriod::fromRequest($request);

        $granularity = (string) $request->query->get('granularity', 'month');
        if (!in_array($granularity, ['month', 'day'], true)) {
            throw new BadRequestException('Paramètre granularity invalide (month|day).', 'invalid_query');
        }

        $data = $statsRepository->balance($household, $accountIds, $period, $granularity);
        $dates = $data['dates'];

        $accounts = $entityManager->getRepository(Account::class)->findBy(['household' => $household], ['id' => 'ASC']);
        if (null !== $accountIds) {
            $accounts = array_values(array_filter(
                $accounts,
                static fn (Account $account): bool => in_array((int) $account->getId(), $accountIds, true),
            ));
        }

        $total = [];
        foreach ($dates as $i => $date) {
            $sum = 0;
            $any = false;
            foreach ($accounts as $account) {
                $value = $data['accounts'][(int) $account->getId()][$i] ?? null;
                if (null !== $value) {
                    $sum += $value;
                    $any = true;
                }
            }
            $total[] = $any ? $sum : null;
        }

        return new JsonResponse([
            'dates' => $dates,
            'total' => $total,
            'accounts' => array_map(
                static fn (Account $account): array => [
                    'account' => $accountPresenter->presentRef($account),
                    'values' => $data['accounts'][(int) $account->getId()] ?? array_fill(0, count($dates), null),
                ],
                $accounts,
            ),
        ]);
    }

    #[Route('/api/stats/sankey', name: 'api_stats_sankey', methods: ['GET'])]
    public function sankey(
        Request $request,
        HouseholdProvider $householdProvider,
        Scope $scope,
        StatsRepository $statsRepository,
        CategoryPresenter $categoryPresenter,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $household = $householdProvider->get();
        $accountIds = $scope->accountIds($request, $household);
        $period = StatsPeriod::fromRequest($request);
        $categories = $this->categoryMap($entityManager, $household);

        $data = $statsRepository->sankey($household, $accountIds, $period);

        $nodes = [];
        $links = [];
        $nodeId = static function (string $side, ?int $rootId): string {
            return $side.'-'.(null !== $rootId ? (string) $rootId : 'null');
        };

        $totalIncome = 0;
        foreach ($data['income'] as $entry) {
            $id = $nodeId('income', $entry['rootId']);
            $nodes[$id] = [
                'id' => $id,
                'name' => null !== $entry['rootId'] ? $categories[$entry['rootId']]->getFullName() : 'Revenus non catégorisés',
                'kind' => 'income',
                'category' => null !== $entry['rootId'] ? $categoryPresenter->presentRef($categories[$entry['rootId']]) : null,
            ];
            $links[] = ['source' => $id, 'target' => 'hub', 'value' => $entry['value']];
            $totalIncome += $entry['value'];
        }

        $nodes['hub'] = ['id' => 'hub', 'name' => 'Budget', 'kind' => 'hub', 'category' => null];

        $totalExpense = 0;
        foreach ($data['expense'] as $entry) {
            $id = $nodeId('expense', $entry['rootId']);
            $nodes[$id] = [
                'id' => $id,
                'name' => null !== $entry['rootId'] ? $categories[$entry['rootId']]->getFullName() : 'Dépenses non catégorisées',
                'kind' => 'expense',
                'category' => null !== $entry['rootId'] ? $categoryPresenter->presentRef($categories[$entry['rootId']]) : null,
            ];
            $links[] = ['source' => 'hub', 'target' => $id, 'value' => $entry['value']];
            $totalExpense += $entry['value'];
        }

        $net = $totalIncome - $totalExpense;
        if ($net > 0) {
            $nodes['savings'] = ['id' => 'savings', 'name' => 'Épargne / excédent', 'kind' => 'savings', 'category' => null];
            $links[] = ['source' => 'hub', 'target' => 'savings', 'value' => $net];
        } elseif ($net < 0) {
            $nodes['deficit'] = ['id' => 'deficit', 'name' => 'Déficit', 'kind' => 'deficit', 'category' => null];
            $links[] = ['source' => 'deficit', 'target' => 'hub', 'value' => -$net];
        }

        return new JsonResponse([
            'nodes' => array_values($nodes),
            'links' => $links,
        ]);
    }

    #[Route('/api/stats/calendar', name: 'api_stats_calendar', methods: ['GET'])]
    public function calendar(
        Request $request,
        HouseholdProvider $householdProvider,
        Scope $scope,
        StatsRepository $statsRepository,
    ): JsonResponse {
        $household = $householdProvider->get();
        $accountIds = $scope->accountIds($request, $household);
        $period = StatsPeriod::fromRequest($request);

        return new JsonResponse($statsRepository->calendar($household, $accountIds, $period));
    }

    #[Route('/api/stats/category-trend', name: 'api_stats_category_trend', methods: ['GET'])]
    public function categoryTrend(
        Request $request,
        HouseholdProvider $householdProvider,
        Scope $scope,
        StatsRepository $statsRepository,
        CategoryPresenter $categoryPresenter,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $household = $householdProvider->get();
        $accountIds = $scope->accountIds($request, $household);
        $period = StatsPeriod::fromRequest($request);

        $rawCategoryId = $request->query->get('categoryId');
        if (null === $rawCategoryId || '' === $rawCategoryId || !ctype_digit((string) $rawCategoryId)) {
            throw new BadRequestException('Paramètre categoryId invalide.', 'invalid_query');
        }
        $categoryId = (int) $rawCategoryId;
        /** @var CategoryRepository $categoryRepository */
        $categoryRepository = $entityManager->getRepository(Category::class);
        $category = $categoryRepository->findOneBy(['id' => $categoryId, 'household' => $household]);
        if (!$category instanceof Category) {
            throw new BadRequestException('Catégorie introuvable.', 'invalid_query');
        }

        $categoryIds = $categoryRepository->withDescendants($household, $categoryId);
        // Le kind d'une sous-catégorie est celui de sa racine (COALESCE(root.kind, cat.kind)).
        $root = null !== $category->getParent() ? $category->getParent() : $category;
        $data = $statsRepository->categoryTrend($household, $accountIds, $period, $categoryIds, $root->getKind()->value);

        return new JsonResponse([
            'category' => $categoryPresenter->presentRef($category),
            'months' => $data['months'],
            'values' => $data['values'],
            'counts' => $data['counts'],
        ]);
    }

    private function directionParam(Request $request): string
    {
        $direction = (string) $request->query->get('direction', 'expense');
        if (!in_array($direction, ['expense', 'income'], true)) {
            throw new BadRequestException('Paramètre direction invalide (expense|income).', 'invalid_query');
        }

        return $direction;
    }

    /** @return array<int, Category> catégories du foyer indexées par id (1 requête) */
    private function categoryMap(EntityManagerInterface $entityManager, Household $household): array
    {
        $map = [];
        foreach ($entityManager->getRepository(Category::class)->findBy(['household' => $household]) as $category) {
            $map[(int) $category->getId()] = $category;
        }

        return $map;
    }
}
