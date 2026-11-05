<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Api\HouseholdProvider;
use App\Api\Presenter\AccountPresenter;
use App\Api\Query\StatsQuery;
use App\Api\Scope;
use App\Entity\Account;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class SummaryController extends AbstractController
{
    #[Route('/api/summary', name: 'api_summary', methods: ['GET'])]
    public function summary(
        Request $request,
        HouseholdProvider $householdProvider,
        Scope $scope,
        EntityManagerInterface $entityManager,
        StatsQuery $statsQuery,
        AccountPresenter $accountPresenter,
    ): JsonResponse {
        $household = $householdProvider->get();
        $accountIds = $scope->accountIds($request, $household);

        $params = ['household' => $household->getId()];
        $types = ['household' => ParameterType::INTEGER];
        $scopeSql = '';
        if (null !== $accountIds) {
            $scopeSql = ' AND a.id IN (:scopeIds)';
            $params['scopeIds'] = $accountIds;
            $types['scopeIds'] = ArrayParameterType::INTEGER;
        }

        $connection = $entityManager->getConnection();
        $row = $connection->fetchAssociative(
            "SELECT COUNT(*) AS total,
                    COUNT(o.category_id) AS categorized,
                    COALESCE(SUM(CASE WHEN o.duplicate_status = 'probable' THEN 1 ELSE 0 END), 0) AS probable,
                    MIN(o.date) AS first_date,
                    MAX(o.date) AS last_date
             FROM operation o
             JOIN account a ON a.id = o.account_id
             WHERE a.household_id = :household$scopeSql",
            $params,
            $types,
        ) ?: ['total' => 0, 'categorized' => 0, 'probable' => 0, 'first_date' => null, 'last_date' => null];

        $pendingTransfers = (int) $connection->fetchOne(
            "SELECT COUNT(DISTINCT t.id) FROM internal_transfer t
             JOIN operation o ON o.internal_transfer_id = t.id
             JOIN account a ON a.id = o.account_id
             WHERE a.household_id = :household AND t.status = 'auto'$scopeSql",
            $params,
            $types,
        );

        $lastImportAt = $connection->fetchOne(
            "SELECT MAX(b.imported_at) FROM import_batch b
             JOIN account a ON a.id = b.account_id
             WHERE a.household_id = :household AND b.status = 'completed'$scopeSql",
            $params,
            $types,
        );

        $accounts = $entityManager->getRepository(Account::class)->findBy(['household' => $household], ['id' => 'ASC']);
        $stats = $statsQuery->accountStats($household, $accountIds);

        $total = (int) $row['total'];
        $categorized = (int) $row['categorized'];

        return new JsonResponse([
            'operationsCount' => $total,
            'categorizedCount' => $categorized,
            'uncategorizedCount' => $total - $categorized,
            'probableDuplicatesCount' => (int) $row['probable'],
            'pendingTransfersCount' => $pendingTransfers,
            'lastImportAt' => null !== $lastImportAt ? (new \DateTimeImmutable((string) $lastImportAt))->format('c') : null,
            'firstDate' => null !== $row['first_date'] ? (new \DateTimeImmutable((string) $row['first_date']))->format('Y-m-d') : null,
            'lastDate' => null !== $row['last_date'] ? (new \DateTimeImmutable((string) $row['last_date']))->format('Y-m-d') : null,
            'accounts' => array_map(
                static fn (Account $account): array => $accountPresenter->present($account, $stats[(int) $account->getId()] ?? []),
                null === $accountIds ? $accounts : array_values(array_filter(
                    $accounts,
                    static fn (Account $account): bool => in_array((int) $account->getId(), $accountIds, true),
                )),
            ),
        ]);
    }
}
