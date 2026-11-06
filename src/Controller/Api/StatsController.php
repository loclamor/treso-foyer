<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Api\BadRequestException;
use App\Api\HouseholdProvider;
use App\Api\Presenter\AccountPresenter;
use App\Api\Presenter\CategoryPresenter;
use App\Api\Presenter\VehiclePresenter;
use App\Api\Scope;
use App\Entity\Account;
use App\Entity\Category;
use App\Entity\Household;
use App\Entity\Vehicle;
use App\Recurrence\RecurrenceForecaster;
use App\Repository\CategoryRepository;
use App\Repository\OdometerReadingRepository;
use App\Stats\StatsPeriod;
use App\Stats\StatsRepository;
use App\Vehicle\OdometerCalculator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
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
        ClockInterface $clock,
    ): JsonResponse {
        $household = $householdProvider->get();
        $accountIds = $scope->accountIds($request, $household);
        $now = $clock->now();
        $period = StatsPeriod::fromRequest($request, $now);

        // Taux d'épargne sur les mois complets (hors mois en cours incomplet) ; si la période
        // demandée est entièrement dans le mois en cours, repli sur le dernier mois complet.
        $firstCurrentMonth = StatsPeriod::firstOfMonth($now);
        $ratePeriod = $period->completeMonths($now)
            ?? new StatsPeriod($firstCurrentMonth->modify('-1 month'), $firstCurrentMonth->modify('-1 day'));

        $current = $statsRepository->overview($household, $accountIds, $period, $ratePeriod);
        // « previous » conservé pour compatibilité (ancienne sémantique, déprécié) : le front
        // utilise « comparison » (comparable vs previousAligned, mois en cours exclu).
        $previous = $statsRepository->overview($household, $accountIds, $period->previous());
        $average12 = $statsRepository->average12($household, $accountIds, $period->to);

        $comparable = $period->comparable($now);
        $previousAligned = $period->previousAligned($now);
        $comparisonCurrent = $statsRepository->overview($household, $accountIds, $comparable);
        $comparisonPrevious = $statsRepository->overview($household, $accountIds, $previousAligned);
        $pick = static fn (array $o): array => [
            'from' => $o['from'],
            'to' => $o['to'],
            'income' => $o['income'],
            'expense' => $o['expense'],
            'net' => $o['net'],
        ];

        return new JsonResponse($current + [
            'previous' => $pick($previous),
            'comparison' => [
                'current' => $pick($comparisonCurrent),
                'previous' => $pick($comparisonPrevious),
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
        RecurrenceForecaster $recurrenceForecaster,
        CategoryPresenter $categoryPresenter,
        EntityManagerInterface $entityManager,
        ClockInterface $clock,
    ): JsonResponse {
        $household = $householdProvider->get();
        $accountIds = $scope->accountIds($request, $household);
        $now = $clock->now();
        $period = StatsPeriod::fromRequest($request, $now);
        $categories = $this->categoryMap($entityManager, $household);

        $data = $statsRepository->monthly($household, $accountIds, $period);

        // Estimation du mois en cours (présent seulement s'il fait partie de la série) :
        // spent / received = dépense / revenu à date, average / incomeAverage = moyennes des
        // k derniers mois complets (k ≤ 12), recurring = échéances récurrentes restantes du mois
        // (RecurrenceForecaster), variable = partie NON récurrente (moyenne des k mois complets,
        // montant à date, reste = max(0, moyenne − à date)) ; expense / income = à date + récurrent
        // restant + variable restant. null si aucune opération avant le mois en cours.
        $estimate = null;
        $currentMonth = $now->format('Y-m');
        $currentIndex = array_search($currentMonth, $data['months'], true);
        if (false !== $currentIndex) {
            $averages = $statsRepository->monthlyAverages($household, $accountIds, StatsPeriod::firstOfMonth($now));
            if (null !== $averages) {
                $spent = $data['expense'][$currentIndex];
                $received = $data['income'][$currentIndex];
                $recurring = $recurrenceForecaster->forMonth($household, $accountIds, $now);
                $monthTo = min($period->to, StatsPeriod::lastOfMonth($now));
                $variable = $statsRepository->currentMonthVariable($household, $accountIds, StatsPeriod::firstOfMonth($now), $monthTo);
                $expenseRemaining = max(0, $averages['expenseVariable'] - $variable['expense']);
                $incomeRemaining = max(0, $averages['incomeVariable'] - $variable['income']);
                $estimate = [
                    'month' => $currentMonth,
                    'basisMonths' => $averages['basisMonths'],
                    'spent' => $spent,
                    'received' => $received,
                    'average' => $averages['expense'],
                    'incomeAverage' => $averages['income'],
                    'recurring' => $recurring,
                    'variable' => [
                        'expenseAverage' => $averages['expenseVariable'],
                        'expenseSpent' => $variable['expense'],
                        'expenseRemaining' => $expenseRemaining,
                        'incomeAverage' => $averages['incomeVariable'],
                        'incomeReceived' => $variable['income'],
                        'incomeRemaining' => $incomeRemaining,
                    ],
                    'expense' => $spent + $recurring['expense']['remaining'] + $expenseRemaining,
                    'income' => $received + $recurring['income']['remaining'] + $incomeRemaining,
                ];
            }
        }

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
            'estimate' => $estimate,
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
        ClockInterface $clock,
    ): JsonResponse {
        $household = $householdProvider->get();
        $accountIds = $scope->accountIds($request, $household);
        $now = $clock->now();
        $period = StatsPeriod::fromRequest($request, $now);
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

        // Quand compare est fourni, le mois en cours incomplet est exclu des deux côtés :
        // comparableTotal = total sur comparable(now), compareTotal = total sur
        // previousAligned(now) (« previous ») ou comparable(now)->sameLastYear() (« year »).
        // Les plages réellement utilisées sont exposées dans les en-têtes X-Comparable-*/X-Compare-*.
        $comparePeriod = null;
        $comparablePeriod = null;
        $compare = $request->query->get('compare');
        if (null !== $compare && '' !== $compare) {
            $compare = (string) $compare;
            $comparablePeriod = $period->comparable($now);
            if ('previous' === $compare) {
                $comparePeriod = $period->previousAligned($now);
            } elseif ('year' === $compare) {
                $comparePeriod = $comparablePeriod->sameLastYear();
            } else {
                throw new BadRequestException('Paramètre compare invalide (previous|year).', 'invalid_query');
            }
        }

        $categories = $this->categoryMap($entityManager, $household);
        $rows = $statsRepository->categories($household, $accountIds, $period, $direction, $parentId, $comparePeriod, $comparablePeriod);

        $response = new JsonResponse(array_map(
            static fn (array $row): array => [
                'category' => null !== $row['categoryId'] ? $categoryPresenter->presentRef($categories[$row['categoryId']]) : null,
                'total' => $row['total'],
                'count' => $row['count'],
                'share' => $row['share'],
                'compareTotal' => $row['compareTotal'],
                'comparableTotal' => $row['comparableTotal'],
            ],
            $rows,
        ));
        if (null !== $comparablePeriod && null !== $comparePeriod) {
            $response->headers->set('X-Comparable-From', $comparablePeriod->from->format('Y-m-d'));
            $response->headers->set('X-Comparable-To', $comparablePeriod->to->format('Y-m-d'));
            $response->headers->set('X-Compare-From', $comparePeriod->from->format('Y-m-d'));
            $response->headers->set('X-Compare-To', $comparePeriod->to->format('Y-m-d'));
        }

        return $response;
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

        // keys[] : restreint aux groupes donnés (au plus 50), ex. totaux d'une période précédente
        // pour les commerçants du top courant.
        $keys = null;
        if ($request->query->has('keys')) {
            $rawKeys = $request->query->all()['keys'];
            if (!is_array($rawKeys) || [] === $rawKeys || count($rawKeys) > 50) {
                throw new BadRequestException('Paramètre keys invalide (liste de 1 à 50 clés attendue).', 'invalid_query');
            }
            $keys = array_values(array_map('strval', $rawKeys));
        }

        $categories = $this->categoryMap($entityManager, $household);
        $rows = $statsRepository->merchants($household, $accountIds, $period, $direction, $limit, $keys);

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

    #[Route('/api/stats/vehicles', name: 'api_stats_vehicles', methods: ['GET'])]
    public function vehicles(
        Request $request,
        HouseholdProvider $householdProvider,
        Scope $scope,
        StatsRepository $statsRepository,
        VehiclePresenter $vehiclePresenter,
        CategoryPresenter $categoryPresenter,
        OdometerReadingRepository $odometerReadingRepository,
        OdometerCalculator $odometerCalculator,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $household = $householdProvider->get();
        $accountIds = $scope->accountIds($request, $household);
        $period = StatsPeriod::fromRequest($request);

        $data = $statsRepository->vehicles($household, $accountIds, $period);

        $vehicleMap = [];
        foreach ($entityManager->getRepository(Vehicle::class)->findBy(['household' => $household]) as $vehicle) {
            $vehicleMap[(int) $vehicle->getId()] = $vehicle;
        }
        $categories = $this->categoryMap($entityManager, $household);
        $monthCount = count($period->months());
        // Lot 4 : relevés kilométriques des véhicules listés (points {date, km} par véhicule).
        $pointsByVehicle = $odometerReadingRepository->pointsByVehicleIds(array_map(
            static fn (array $row): int => (int) $row['vehicleId'],
            $data['vehicles'],
        ));

        return new JsonResponse([
            'vehicles' => array_map(
                static function (array $row) use ($vehicleMap, $categories, $categoryPresenter, $vehiclePresenter, $monthCount, $pointsByVehicle, $odometerCalculator, $statsRepository, $household, $accountIds, $period): array {
                    $vehicle = $vehicleMap[$row['vehicleId']] ?? null;

                    // Distance parcourue sur la période (intersection avec [premier, dernier relevé],
                    // km aux bornes interpolés) et coût au km sur [coveredFrom, coveredTo].
                    $distance = $odometerCalculator->distance($pointsByVehicle[$row['vehicleId']] ?? [], $period->from, $period->to);
                    $costPerKm = null;
                    if (null !== $distance && $distance->km > 0.0) {
                        $expense = $statsRepository->vehicleExpenseBetween($household, $accountIds, (int) $row['vehicleId'], $distance->coveredFrom, $distance->coveredTo);
                        $costPerKm = round($expense / $distance->km, 1);
                    }

                    return [
                        'vehicle' => null !== $vehicle ? $vehiclePresenter->presentRef($vehicle) : null,
                        'total' => $row['total'],
                        'count' => $row['count'],
                        'monthlyAverage' => $monthCount > 0 ? (int) round($row['total'] / $monthCount) : 0,
                        'byCategory' => array_map(
                            static fn (array $entry): array => [
                                'category' => null !== $entry['categoryId'] && isset($categories[$entry['categoryId']])
                                    ? $categoryPresenter->presentRef($categories[$entry['categoryId']])
                                    : null,
                                'total' => $entry['total'],
                            ],
                            $row['byCategory'],
                        ),
                        'distance' => null !== $distance ? $distance->toArray() : null,
                        'costPerKm' => $costPerKm,
                    ];
                },
                $data['vehicles'],
            ),
            'unassigned' => $data['unassigned'],
        ]);
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
