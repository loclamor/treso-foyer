<?php

declare(strict_types=1);

namespace App\Recurrence;

use App\Entity\Household;
use App\Enum\RecurrenceDirection;
use App\Enum\RecurrenceFrequency;
use App\Enum\RecurrenceStatus;
use App\Stats\StatsPeriod;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Prévision des échéances récurrentes du mois en cours (voir docs/adr/0012-recurrences.md) :
 * séries « detected »/« confirmed » des comptes du périmètre, échéances attendues dans le mois
 * (suite de la fréquence à partir de la dernière occurrence antérieure au mois, ou de
 * lastOccurrence), opérations liées du mois = échéances payées, le reste × montant attendu.
 *
 * @return array{expense: array{remaining: int, byRoot: list<array{rootId: ?int, remaining: int}>, items: list<array<string, mixed>>}, income: array{remaining: int, items: list<array<string, mixed>>}}
 */
final class RecurrenceForecaster
{
    private const int WINDOW_MONTHS = 24;
    private const int MAX_STEPS = 1400; // borne de garde (weekly sur 24 mois ≈ 104 cycles)

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /**
     * @param list<int>|null $accountIds périmètre (null = tout le foyer)
     */
    public function forMonth(Household $household, ?array $accountIds, \DateTimeImmutable $today): array
    {
        $connection = $this->entityManager->getConnection();
        $householdId = (int) $household->getId();
        $monthFirst = StatsPeriod::firstOfMonth($today);
        $monthLast = StatsPeriod::lastOfMonth($today);
        $scope = null !== $accountIds ? ' AND a.id IN (:scopeIds)' : '';

        $seriesRows = $connection->fetchAllAssociative(
            "SELECT rs.id, rs.merchant, rs.direction, rs.category_id, rs.frequency,
                    rs.expected_amount, rs.last_occurrence, rs.active_months
             FROM recurring_series rs
             JOIN account a ON a.id = rs.account_id
             WHERE a.household_id = :household::int
               AND rs.status IN ('detected', 'confirmed')".$scope,
            null !== $accountIds
                ? ['household' => $householdId, 'scopeIds' => $accountIds]
                : ['household' => $householdId],
            null !== $accountIds
                ? ['household' => ParameterType::INTEGER, 'scopeIds' => ArrayParameterType::INTEGER]
                : ['household' => ParameterType::INTEGER],
        );
        if ([] === $seriesRows) {
            return self::emptyResult();
        }

        $seriesIds = array_map(static fn (array $row): int => (int) $row['id'], $seriesRows);

        // Opérations liées (fenêtre suffisante pour la dernière occurrence antérieure au mois).
        $operationRows = $connection->fetchAllAssociative(
            'SELECT recurring_series_id, id, date, amount
             FROM operation
             WHERE recurring_series_id IN (:ids)
               AND date >= :windowFrom AND date <= :monthLast
             ORDER BY date ASC, id ASC',
            [
                'ids' => $seriesIds,
                'windowFrom' => $monthFirst->modify('-'.self::WINDOW_MONTHS.' months'),
                'monthLast' => $monthLast,
            ],
            [
                'ids' => ArrayParameterType::INTEGER,
                'windowFrom' => \Doctrine\DBAL\Types\Types::DATE_IMMUTABLE,
                'monthLast' => \Doctrine\DBAL\Types\Types::DATE_IMMUTABLE,
            ],
        );
        /** @var array<int, list<array{date: \DateTimeImmutable, amount: int}>> $operationsBySeries */
        $operationsBySeries = [];
        foreach ($operationRows as $row) {
            $operationsBySeries[(int) $row['recurring_series_id']][] = [
                'date' => new \DateTimeImmutable((string) $row['date']),
                'amount' => abs((int) $row['amount']),
            ];
        }

        // Racine de chaque catégorie du foyer (pour rootId).
        $categoryRows = $connection->fetchAllAssociative(
            'SELECT c.id, c.parent_id FROM category c WHERE c.household_id = :household::int',
            ['household' => $householdId],
            ['household' => ParameterType::INTEGER],
        );
        $parentOf = [];
        foreach ($categoryRows as $row) {
            $parentOf[(int) $row['id']] = null !== $row['parent_id'] ? (int) $row['parent_id'] : null;
        }

        $result = self::emptyResult();
        foreach ($seriesRows as $series) {
            $seriesId = (int) $series['id'];
            $frequency = RecurrenceFrequency::from((string) $series['frequency']);
            $activeMonths = null !== $series['active_months']
                ? array_map('intval', json_decode((string) $series['active_months'], true) ?: [])
                : null;
            $expectedAmount = (int) $series['expected_amount'];
            $categoryId = null !== $series['category_id'] ? (int) $series['category_id'] : null;
            $rootId = null !== $categoryId ? ($parentOf[$categoryId] ?? $categoryId) : null;
            $direction = RecurrenceDirection::from((string) $series['direction']);

            $operations = $operationsBySeries[$seriesId] ?? [];
            // Dernière occurrence antérieure au mois courant, sinon lastOccurrence de la série.
            $base = null !== $series['last_occurrence'] ? new \DateTimeImmutable((string) $series['last_occurrence']) : null;
            foreach ($operations as $operation) {
                if ($operation['date'] < $monthFirst) {
                    $base = $operation['date'];
                }
            }
            if (null === $base) {
                continue;
            }

            // Dates attendues tombant dans le mois (suite calculée depuis l'ancre, jour conservé).
            $expectedDates = [];
            for ($cycles = 1; $cycles <= self::MAX_STEPS; ++$cycles) {
                $candidate = RecurrenceCalendar::addCycles($base, $frequency, $cycles);
                if ($candidate > $monthLast) {
                    break;
                }
                if ($candidate >= $monthFirst) {
                    $expectedDates[] = $candidate;
                }
            }

            // Échéances payées du mois (opérations liées datées dans le mois).
            $paid = [];
            foreach ($operations as $operation) {
                if ($operation['date'] >= $monthFirst && $operation['date'] <= $monthLast) {
                    $paid[] = $operation;
                }
            }
            $paidCount = count($paid);
            $expectedCount = count($expectedDates);

            $items = [];
            foreach ($expectedDates as $index => $date) {
                $isPaid = $index < $paidCount;
                $items[] = [
                    'seriesId' => $seriesId,
                    'merchant' => (string) $series['merchant'],
                    'categoryId' => $categoryId,
                    'rootId' => $rootId,
                    'expectedDate' => $date->format('Y-m-d'),
                    'amount' => $isPaid ? $paid[$index]['amount'] : $expectedAmount,
                    'paid' => $isPaid,
                ];
            }

            $side = RecurrenceDirection::Expense === $direction ? 'expense' : 'income';
            foreach ($items as $item) {
                if (!$item['paid']) {
                    $result[$side]['remaining'] += $item['amount'];
                    if ('expense' === $side) {
                        $result[$side]['byRoot'][$item['rootId'] ?? 'null'] = [
                            'rootId' => $item['rootId'],
                            'remaining' => ($result[$side]['byRoot'][$item['rootId'] ?? 'null']['remaining'] ?? 0) + $item['amount'],
                        ];
                    }
                }
                $result[$side]['items'][] = $item;
            }
        }

        // Tri des items par date (puis id de série) et des byRoot par montant desc.
        foreach (['expense', 'income'] as $side) {
            usort($result[$side]['items'], static fn (array $a, array $b): int => [$a['expectedDate'], $a['seriesId']] <=> [$b['expectedDate'], $b['seriesId']]);
        }
        $byRoot = array_values($result['expense']['byRoot']);
        usort($byRoot, static fn (array $a, array $b): int => [$b['remaining'], $a['rootId'] ?? 0] <=> [$a['remaining'], $b['rootId'] ?? 0]);
        $result['expense']['byRoot'] = $byRoot;

        return $result;
    }

    /** @return array{expense: array{remaining: int, byRoot: list<array{rootId: ?int, remaining: int}>, items: list<array<string, mixed>>}, income: array{remaining: int, items: list<array<string, mixed>>}} */
    private static function emptyResult(): array
    {
        return [
            'expense' => ['remaining' => 0, 'byRoot' => [], 'items' => []],
            'income' => ['remaining' => 0, 'items' => []],
        ];
    }
}
