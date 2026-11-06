<?php

declare(strict_types=1);

namespace App\Api\Query;

use App\Entity\Household;
use App\Entity\Operation;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Compteurs agrégés (GROUP BY) utilisés par les listes de l'API : aucun N+1.
 */
final class StatsQuery
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /** @return array<int, int> applied_rule_id => nombre d'opérations */
    public function ruleMatchCounts(Household $household): array
    {
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT o.applied_rule_id, COUNT(*) AS cnt FROM operation o
             JOIN account a ON a.id = o.account_id
             WHERE a.household_id = :household AND o.applied_rule_id IS NOT NULL
             GROUP BY o.applied_rule_id',
            ['household' => $household->getId()],
            ['household' => ParameterType::INTEGER],
        );

        return $this->intMap($rows, 'applied_rule_id', 'cnt');
    }

    /** @return array<int, int> category_id => nombre d'opérations */
    public function categoryOperationCounts(Household $household): array
    {
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT o.category_id, COUNT(*) AS cnt FROM operation o
             JOIN account a ON a.id = o.account_id
             WHERE a.household_id = :household AND o.category_id IS NOT NULL
             GROUP BY o.category_id',
            ['household' => $household->getId()],
            ['household' => ParameterType::INTEGER],
        );

        return $this->intMap($rows, 'category_id', 'cnt');
    }

    /** @return array<int, int> vehicle_id => nombre d'opérations affectées */
    public function vehicleOperationCounts(Household $household): array
    {
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT o.vehicle_id, COUNT(*) AS cnt FROM operation o
             JOIN account a ON a.id = o.account_id
             WHERE a.household_id = :household AND o.vehicle_id IS NOT NULL
             GROUP BY o.vehicle_id',
            ['household' => $household->getId()],
            ['household' => ParameterType::INTEGER],
        );

        return $this->intMap($rows, 'vehicle_id', 'cnt');
    }

    /**
     * Statistiques par compte : nombre d'opérations, première/dernière date et solde
     * (dernier relevé + somme des opérations postérieures à sa date de clôture).
     *
     * @param list<int>|null $accountIds périmètre (null = tous les comptes du foyer)
     * @return array<int, array{operationsCount: int, firstDate: ?string, lastDate: ?string, balance: ?array{amount: int, date: string}}>
     */
    public function accountStats(Household $household, ?array $accountIds = null): array
    {
        $connection = $this->entityManager->getConnection();
        $params = ['household' => $household->getId()];
        $types = ['household' => ParameterType::INTEGER];
        $scopeSql = '';
        if (null !== $accountIds) {
            $scopeSql = ' AND a.id IN (:scopeIds)';
            $params['scopeIds'] = $accountIds;
            $types['scopeIds'] = ArrayParameterType::INTEGER;
        }

        // firstDate / lastDate sur la date effective (COALESCE(spent_at, date), ADR 0014) ;
        // le solde reconstitué (postSum ci-dessous) garde o.date : la saisie compte à sa date réelle.
        $eff = Operation::effectiveDateSql('o');
        $stats = [];
        foreach ($connection->fetchAllAssociative(
            "SELECT a.id, COUNT(o.id) AS cnt, MIN($eff) AS first_date, MAX($eff) AS last_date
             FROM account a LEFT JOIN operation o ON o.account_id = a.id
             WHERE a.household_id = :household$scopeSql
             GROUP BY a.id",
            $params,
            $types,
        ) as $row) {
            $stats[(int) $row['id']] = [
                'operationsCount' => (int) $row['cnt'],
                'firstDate' => null !== $row['first_date'] ? (new \DateTimeImmutable((string) $row['first_date']))->format('Y-m-d') : null,
                'lastDate' => null !== $row['last_date'] ? (new \DateTimeImmutable((string) $row['last_date']))->format('Y-m-d') : null,
                'balance' => null,
            ];
        }

        // Dernier relevé (clôture) par compte.
        $statements = $connection->fetchAllAssociative(
            "SELECT s.account_id, s.closing_date, s.closing_balance
             FROM statement s
             JOIN account a ON a.id = s.account_id
             WHERE a.household_id = :household$scopeSql
               AND s.closing_date = (SELECT MAX(s2.closing_date) FROM statement s2 WHERE s2.account_id = s.account_id)",
            $params,
            $types,
        );
        foreach ($statements as $statement) {
            $accountId = (int) $statement['account_id'];
            if (!isset($stats[$accountId])) {
                continue;
            }
            $closingDate = new \DateTimeImmutable((string) $statement['closing_date']);
            $postSum = (int) $connection->fetchOne(
                'SELECT COALESCE(SUM(o.amount), 0) FROM operation o
                 WHERE o.account_id = :account AND o.date > :closing',
                ['account' => $accountId, 'closing' => $closingDate],
                ['account' => ParameterType::INTEGER, 'closing' => \Doctrine\DBAL\Types\Types::DATE_IMMUTABLE],
            );
            $stats[$accountId]['balance'] = [
                'amount' => (int) $statement['closing_balance'] + $postSum,
                'date' => $closingDate->format('Y-m-d'),
            ];
        }

        return $stats;
    }

    /** @return array<int, int> */
    private function intMap(array $rows, string $keyColumn, string $valueColumn): array
    {
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row[$keyColumn]] = (int) $row[$valueColumn];
        }

        return $map;
    }
}
