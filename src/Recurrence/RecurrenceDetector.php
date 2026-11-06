<?php

declare(strict_types=1);

namespace App\Recurrence;

use App\Entity\Household;
use App\Enum\RecurrenceDirection;
use App\Enum\RecurrenceFrequency;
use App\Enum\RecurrenceStatus;
use App\Stats\AmountClassifier;
use App\Stats\StatsPeriod;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Synchronise les séries récurrentes d'un foyer (dépenses ET revenus) à partir des
 * opérations des 36 derniers mois (voir docs/adr/0012-recurrences.md) :
 *
 * - fenêtre [premier jour du mois de today − 36 mois, today], doublons probables non
 *   tranchés exclus, classification des montants via AmountClassifier (virements internes
 *   et catégories « transfer » exclus) ;
 * - groupes (compte, matchKey = coalesce(merchant, normalized_label), direction), analysés
 *   par RecurrencePattern (classe pure) ;
 * - upsert par (compte, matchKey, direction) : création « detected », mise à jour des
 *   « detected », simple recalcul de la prochaine échéance pour les « confirmed » (l'utilisateur
 *   a pu corriger montant/fréquence/mois), « ignored » jamais touchée, « ended » ranimée si le
 *   motif est de nouveau détecté ; les « detected » dont l'échéance attendue est trop ancienne
 *   passent « ended » (les « confirmed » jamais) ;
 * - liaison operation.recurring_series_id (UPDATE groupés) des opérations retenues.
 *
 * Idempotent : relancer deux fois ne change rien la seconde fois (compteurs à 0).
 * Tout passe par la connexion DBAL (hors UnitOfWork, comme VehicleAssigner) : à appeler
 * APRÈS un flush. Aucun libellé ni montant dans les logs ou exceptions.
 */
final class RecurrenceDetector
{
    private const int WINDOW_MONTHS = 36;
    private const int CHUNK_SIZE = 1000;
    /** Rattachement des groupes satellites (libellé CSV ≠ PDF) : voir sync(), étape 2 ter. */
    private const int SATELLITE_MAX_OPERATIONS = 2;
    private const int SATELLITE_MAX_AGE_DAYS = 92;
    private const float SATELLITE_MIN_SIMILARITY = 0.6;

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function sync(Household $household, \DateTimeImmutable $today, bool $dryRun = false): RecurrenceDetectionReport
    {
        $report = new RecurrenceDetectionReport();
        $connection = $this->entityManager->getConnection();
        $householdId = (int) $household->getId();
        $windowFrom = StatsPeriod::firstOfMonth($today)->modify('-'.self::WINDOW_MONTHS.' months');

        // 1. Lecture des opérations de la fenêtre, classées dépense/revenu (virements internes exclus).
        $rows = $connection->fetchAllAssociative(
            'SELECT o.id, o.date, o.account_id, o.merchant_group, o.merchant, o.normalized_label, o.category_id,
                    '.AmountClassifier::expenseAmountExpr().' AS expense,
                    '.AmountClassifier::incomeAmountExpr().' AS income
             FROM operation o
             JOIN account a ON a.id = o.account_id
             '.AmountClassifier::joins().'
             WHERE a.household_id = :household::int
               AND o.date >= :from AND o.date <= :to
               AND o.duplicate_status <> :probable
               AND NOT o.awaiting_statement
             ORDER BY o.id',
            ['household' => $householdId, 'from' => $windowFrom, 'to' => $today, 'probable' => 'probable'],
            ['household' => ParameterType::INTEGER, 'from' => Types::DATE_IMMUTABLE, 'to' => Types::DATE_IMMUTABLE, 'probable' => ParameterType::STRING],
        );

        /** @var array<string, list<array{id: int, date: \DateTimeImmutable, amount: int, categoryId: ?int}>> $groups */
        $groups = [];
        /** @var array<string, array{0: string, 1: string}> $regrouped clé brute d'une variante => [clé de groupe, matchKey du groupe] */
        $regrouped = [];
        foreach ($rows as $row) {
            $expense = (int) $row['expense'];
            $income = (int) $row['income'];
            if ($expense > 0) {
                $direction = RecurrenceDirection::Expense;
                $amount = $expense;
            } elseif ($income > 0) {
                $direction = RecurrenceDirection::Income;
                $amount = $income;
            } else {
                continue; // virement interne, catégorie « transfer », montant nul…
            }
            // Variantes d'un même commerçant regroupées (App\Merchant\MerchantGrouper).
            $matchKey = (string) ($row['merchant_group'] ?? $row['merchant'] ?? $row['normalized_label']);
            $key = $row['account_id'].'|'.$direction->value.'|'.$matchKey;
            $rawKey = (string) ($row['merchant'] ?? $row['normalized_label']);
            if ($rawKey !== $matchKey) {
                $regrouped[$row['account_id'].'|'.$direction->value.'|'.$rawKey] = [$key, $matchKey];
            }
            $groups[$key][] = [
                'id' => (int) $row['id'],
                'date' => new \DateTimeImmutable((string) $row['date']),
                'amount' => $amount,
                'categoryId' => null !== $row['category_id'] ? (int) $row['category_id'] : null,
            ];
        }

        // 2. Séries existantes du foyer, indexées par (compte, matchKey, direction).
        $seriesRows = $connection->fetchAllAssociative(
            'SELECT rs.id, rs.account_id, rs.match_key, rs.direction, rs.merchant, rs.category_id,
                    rs.frequency, rs.expected_amount, rs.amount_tolerance,
                    rs.last_occurrence, rs.next_expected, rs.active_months, rs.status
             FROM recurring_series rs
             JOIN account a ON a.id = rs.account_id
             WHERE a.household_id = :household::int',
            ['household' => $householdId],
            ['household' => ParameterType::INTEGER],
        );
        /** @var array<string, array<string, mixed>> $seriesByKey */
        $seriesByKey = [];
        /** @var array<int, array<string, mixed>> $seriesById */
        $seriesById = [];
        foreach ($seriesRows as $series) {
            $key = $series['account_id'].'|'.$series['direction'].'|'.$series['match_key'];
            $seriesByKey[$key] = $series;
            $seriesById[(int) $series['id']] = $series;
        }

        // 2 bis. Séries indexées sur une variante de commerçant désormais regroupée (MerchantGrouper) :
        // ré-indexées sur la clé du groupe (statut confirmé/ignoré et corrections conservés) ; si une
        // série existe déjà pour le groupe, une série « detected » en double est supprimée.
        foreach ($seriesByKey as $key => $series) {
            if (isset($groups[$key]) || !isset($regrouped[$key])) {
                continue;
            }
            [$targetKey, $targetMatchKey] = $regrouped[$key];
            $seriesId = (int) $series['id'];
            if (!isset($seriesByKey[$targetKey])) {
                if (!$dryRun) {
                    $connection->executeStatement(
                        'UPDATE recurring_series SET match_key = :matchKey, updated_at = :updatedAt WHERE id = :id',
                        ['matchKey' => $targetMatchKey, 'updatedAt' => $today->format('Y-m-d H:i:s'), 'id' => $seriesId],
                        ['matchKey' => ParameterType::STRING, 'updatedAt' => ParameterType::STRING, 'id' => ParameterType::INTEGER],
                    );
                }
                $series['match_key'] = $targetMatchKey;
                $seriesByKey[$targetKey] = $series;
                $seriesById[$seriesId] = $series;
                ++$report->updated;
            } elseif (RecurrenceStatus::Detected->value === $series['status']) {
                if (!$dryRun) {
                    $connection->executeStatement(
                        'DELETE FROM recurring_series WHERE id = :id',
                        ['id' => $seriesId],
                        ['id' => ParameterType::INTEGER],
                    );
                }
                unset($seriesById[$seriesId]);
                ++$report->removed;
            } else {
                continue; // série confirmée/ignorée en conflit : laissée telle quelle
            }
            unset($seriesByKey[$key]);
        }

        // 2 ter. Analyse de chaque groupe, puis rattachement des groupes « satellites » : une ou
        // deux opérations récentes dont la clé diffère légèrement de celle d'une série (libellé
        // d'un export CSV ≠ libellé du relevé PDF, tant que le PDF n'est pas importé). Critères :
        // même compte et même sens, similarité trigramme ≥ 0.6, postérieures à la dernière
        // occurrence et montant dans la tolérance. Déterministe ⇒ la synchronisation reste idempotente.
        $analyze = static fn (array $operations): ?RecurrenceAnalysis => RecurrencePattern::analyze(
            array_map(
                static fn (array $operation): array => ['date' => $operation['date'], 'amount' => $operation['amount'], 'id' => $operation['id']],
                $operations,
            ),
            $today,
        );
        /** @var array<string, ?RecurrenceAnalysis> $analyses */
        $analyses = [];
        foreach ($groups as $key => $operations) {
            $analyses[$key] = $analyze($operations);
        }
        $satelliteFrom = $today->setTime(0, 0)->modify('-'.self::SATELLITE_MAX_AGE_DAYS.' days');
        foreach ($groups as $satelliteKey => $satelliteOperations) {
            if (null !== $analyses[$satelliteKey] || isset($seriesByKey[$satelliteKey])
                || count($satelliteOperations) > self::SATELLITE_MAX_OPERATIONS) {
                continue;
            }
            [$satelliteAccount, $satelliteDirection, $satelliteMatchKey] = explode('|', (string) $satelliteKey, 3);
            $bestKey = null;
            $bestSimilarity = self::SATELLITE_MIN_SIMILARITY;
            foreach ($analyses as $mainKey => $mainAnalysis) {
                if (null === $mainAnalysis || !isset($groups[$mainKey])) {
                    continue;
                }
                [$mainAccount, $mainDirection, $mainMatchKey] = explode('|', (string) $mainKey, 3);
                if ($mainAccount !== $satelliteAccount || $mainDirection !== $satelliteDirection) {
                    continue;
                }
                foreach ($satelliteOperations as $operation) {
                    if ($operation['date'] < $satelliteFrom || $operation['date'] <= $mainAnalysis->lastOccurrence
                        || abs($operation['amount'] - $mainAnalysis->expectedAmount) > $mainAnalysis->amountTolerance) {
                        continue 2;
                    }
                }
                $similarity = TrigramSimilarity::similarity($satelliteMatchKey, $mainMatchKey);
                if ($similarity >= $bestSimilarity) {
                    $bestSimilarity = $similarity;
                    $bestKey = $mainKey;
                }
            }
            if (null === $bestKey) {
                continue;
            }
            $merged = array_merge($groups[$bestKey], $satelliteOperations);
            $mergedAnalysis = $analyze($merged);
            if (null !== $mergedAnalysis) {
                $groups[$bestKey] = $merged;
                $analyses[$bestKey] = $mergedAnalysis;
                unset($groups[$satelliteKey], $analyses[$satelliteKey]);
            }
        }

        // 3. Upsert de chaque groupe.
        /** @var array<int, list<int>> $retainedBySeries opérations retenues par série (liaison) */
        $retainedBySeries = [];
        /** @var list<int> $ignoredSeriesIds séries « ignored » : toutes leurs opérations sont déliées */
        $ignoredSeriesIds = [];
        /** @var array<int, array<string, mixed>> $effective état après upsert (pour la fin automatique) */
        $effective = [];
        $now = $today->format('Y-m-d H:i:s');

        foreach ($groups as $key => $operations) {
            [$accountId, $directionValue, $matchKey] = explode('|', (string) $key, 3);
            $direction = RecurrenceDirection::from($directionValue);
            $analysis = $analyses[$key];
            $existing = $seriesByKey[$key] ?? null;
            if (null === $existing && null === $analysis) {
                continue;
            }
            if (null !== $existing && RecurrenceStatus::Ignored->value === $existing['status']) {
                $ignoredSeriesIds[] = (int) $existing['id'];
                $effective[(int) $existing['id']] = $existing;
                continue;
            }
            if (null === $analysis) {
                // Motif plus reconnu alors que la série n'est pas simplement échue : une série
                // « detected » (état automatique) est supprimée (opérations déliées par la clé
                // étrangère ON DELETE SET NULL). Une série échue passe « ended » plus bas ; une série
                // « confirmed » ou « ended » garde ses liens et son état.
                if (null !== $existing && RecurrenceStatus::Detected->value === $existing['status']
                    && !self::isPastDeadline($existing, $today)) {
                    if (!$dryRun) {
                        $connection->executeStatement(
                            'DELETE FROM recurring_series WHERE id = :id',
                            ['id' => (int) $existing['id']],
                            ['id' => ParameterType::INTEGER],
                        );
                    }
                    ++$report->removed;
                    continue;
                }
                if (null !== $existing) {
                    $effective[(int) $existing['id']] = $existing;
                }
                continue;
            }

            $merchant = mb_substr($matchKey, 0, 120);
            $categoryId = self::majorityCategory($operations, $analysis->linkedIds);
            $lastOccurrence = $analysis->lastOccurrence->format('Y-m-d');
            $nextExpected = $analysis->nextExpected->format('Y-m-d');
            $activeMonthsJson = null !== $analysis->activeMonths ? json_encode($analysis->activeMonths) : null;

            if (null === $existing) {
                // Création « detected ».
                if (!$dryRun) {
                    $connection->executeStatement(
                        'INSERT INTO recurring_series
                            (account_id, match_key, direction, merchant, category_id, frequency, expected_amount, amount_tolerance, last_occurrence, next_expected, active_months, status, created_at, updated_at)
                         VALUES
                            (:accountId, :matchKey, :direction, :merchant, :categoryId, :frequency, :expectedAmount, :amountTolerance, :lastOccurrence, :nextExpected, :activeMonths::json, :status, :createdAt, :updatedAt)',
                        [
                            'accountId' => (int) $accountId,
                            'matchKey' => $matchKey,
                            'direction' => $direction->value,
                            'merchant' => $merchant,
                            'categoryId' => $categoryId,
                            'frequency' => $analysis->frequency->value,
                            'expectedAmount' => $analysis->expectedAmount,
                            'amountTolerance' => $analysis->amountTolerance,
                            'lastOccurrence' => $lastOccurrence,
                            'nextExpected' => $nextExpected,
                            'activeMonths' => $activeMonthsJson,
                            'status' => RecurrenceStatus::Detected->value,
                            'createdAt' => $now,
                            'updatedAt' => $now,
                        ],
                        [
                            'accountId' => ParameterType::INTEGER,
                            'matchKey' => ParameterType::STRING,
                            'direction' => ParameterType::STRING,
                            'merchant' => ParameterType::STRING,
                            'categoryId' => ParameterType::INTEGER,
                            'frequency' => ParameterType::STRING,
                            'expectedAmount' => ParameterType::INTEGER,
                            'amountTolerance' => ParameterType::INTEGER,
                            'lastOccurrence' => ParameterType::STRING,
                            'nextExpected' => ParameterType::STRING,
                            'activeMonths' => ParameterType::STRING,
                            'status' => ParameterType::STRING,
                            'createdAt' => ParameterType::STRING,
                            'updatedAt' => ParameterType::STRING,
                        ],
                    );
                    $seriesId = (int) $connection->lastInsertId('recurring_series');
                } else {
                    $seriesId = -1 - $report->created;
                }
                ++$report->created;
                $retainedBySeries[$seriesId] = $analysis->linkedIds;
                $effective[$seriesId] = [
                    'id' => $seriesId, 'status' => RecurrenceStatus::Detected->value,
                    'frequency' => $analysis->frequency->value,
                    'next_expected' => $nextExpected, 'last_occurrence' => $lastOccurrence,
                ];
                continue;
            }

            $seriesId = (int) $existing['id'];
            $retainedBySeries[$seriesId] = $analysis->linkedIds;
            $status = (string) $existing['status'];

            if (RecurrenceStatus::Confirmed->value === $status) {
                // Confirmée : seules la dernière occurrence, la prochaine échéance (recalculée
                // avec la fréquence/les mois actifs de la série) et la catégorie sont mis à jour.
                $frequency = RecurrenceFrequency::from((string) $existing['frequency']);
                $existingActiveMonths = self::decodeActiveMonths($existing['active_months']);
                $newNext = RecurrenceCalendar::nextAfter($analysis->lastOccurrence, $frequency, $existingActiveMonths)->format('Y-m-d');
                $newCategoryId = $categoryId;
                $changed = $lastOccurrence !== (string) $existing['last_occurrence']
                    || $newNext !== (string) $existing['next_expected']
                    || $newCategoryId !== ($existing['category_id'] !== null ? (int) $existing['category_id'] : null);
                if ($changed && !$dryRun) {
                    $connection->executeStatement(
                        'UPDATE recurring_series SET last_occurrence = :lastOccurrence, next_expected = :nextExpected, category_id = :categoryId, updated_at = :updatedAt WHERE id = :id',
                        [
                            'lastOccurrence' => $lastOccurrence,
                            'nextExpected' => $newNext,
                            'categoryId' => $newCategoryId,
                            'updatedAt' => $now,
                            'id' => $seriesId,
                        ],
                        [
                            'lastOccurrence' => ParameterType::STRING,
                            'nextExpected' => ParameterType::STRING,
                            'categoryId' => ParameterType::INTEGER,
                            'updatedAt' => ParameterType::STRING,
                            'id' => ParameterType::INTEGER,
                        ],
                    );
                }
                if ($changed) {
                    ++$report->updated;
                }
                $effective[$seriesId] = $existing + ['last_occurrence' => $lastOccurrence, 'next_expected' => $newNext, 'status' => RecurrenceStatus::Confirmed->value];
                continue;
            }

            // detected (mise à jour complète) ou ended (ranimée en detected).
            $newStatus = RecurrenceStatus::Detected->value;
            $changed = $newStatus !== $status
                || $analysis->frequency->value !== (string) $existing['frequency']
                || $analysis->expectedAmount !== (int) $existing['expected_amount']
                || $analysis->amountTolerance !== (int) $existing['amount_tolerance']
                || $lastOccurrence !== (string) $existing['last_occurrence']
                || $nextExpected !== (string) $existing['next_expected']
                || $analysis->activeMonths !== self::decodeActiveMonths($existing['active_months'])
                || $categoryId !== ($existing['category_id'] !== null ? (int) $existing['category_id'] : null)
                || $merchant !== (string) $existing['merchant'];
            if ($changed && !$dryRun) {
                $connection->executeStatement(
                    'UPDATE recurring_series SET
                        frequency = :frequency, expected_amount = :expectedAmount, amount_tolerance = :amountTolerance,
                        last_occurrence = :lastOccurrence, next_expected = :nextExpected, active_months = :activeMonths::json,
                        category_id = :categoryId, merchant = :merchant, status = :status, updated_at = :updatedAt
                     WHERE id = :id',
                    [
                        'frequency' => $analysis->frequency->value,
                        'expectedAmount' => $analysis->expectedAmount,
                        'amountTolerance' => $analysis->amountTolerance,
                        'lastOccurrence' => $lastOccurrence,
                        'nextExpected' => $nextExpected,
                        'activeMonths' => $activeMonthsJson,
                        'categoryId' => $categoryId,
                        'merchant' => $merchant,
                        'status' => $newStatus,
                        'updatedAt' => $now,
                        'id' => $seriesId,
                    ],
                    [
                        'frequency' => ParameterType::STRING,
                        'expectedAmount' => ParameterType::INTEGER,
                        'amountTolerance' => ParameterType::INTEGER,
                        'lastOccurrence' => ParameterType::STRING,
                        'nextExpected' => ParameterType::STRING,
                        'activeMonths' => ParameterType::STRING,
                        'categoryId' => ParameterType::INTEGER,
                        'merchant' => ParameterType::STRING,
                        'status' => ParameterType::STRING,
                        'updatedAt' => ParameterType::STRING,
                        'id' => ParameterType::INTEGER,
                    ],
                );
            }
            if ($changed) {
                ++$report->updated;
            }
            $effective[$seriesId] = [
                'id' => $seriesId, 'status' => $newStatus,
                'frequency' => $analysis->frequency->value,
                'next_expected' => $nextExpected, 'last_occurrence' => $lastOccurrence,
            ];
        }

        // Séries existantes sans groupe détecté : conservées pour la fin automatique.
        foreach ($seriesById as $seriesId => $series) {
            if (!isset($effective[$seriesId])) {
                $effective[$seriesId] = $series;
            }
            if (RecurrenceStatus::Ignored->value === $series['status']) {
                $ignoredSeriesIds[] = $seriesId;
            }
        }

        // 4. Fin automatique : « detected » dont nextExpected + nominal + tolérance < today.
        foreach ($effective as $seriesId => $series) {
            if (RecurrenceStatus::Detected->value !== $series['status']) {
                continue; // les « confirmed » ne passent jamais en « ended » automatiquement
            }
            if (self::isPastDeadline($series, $today)) {
                if (!$dryRun) {
                    $connection->executeStatement(
                        "UPDATE recurring_series SET status = 'ended', updated_at = :updatedAt WHERE id = :id",
                        ['updatedAt' => $now, 'id' => $seriesId],
                        ['updatedAt' => ParameterType::STRING, 'id' => ParameterType::INTEGER],
                    );
                }
                ++$report->ended;
            }
        }

        // 5. Liaison des opérations retenues (et déliaison de celles qui ne le sont plus).
        $seriesIds = array_keys($effective);
        if ([] !== $seriesIds && !$dryRun) {
            $currentLinks = $connection->fetchAllAssociative(
                'SELECT id, recurring_series_id FROM operation WHERE recurring_series_id IN (:ids)',
                ['ids' => $seriesIds],
                ['ids' => ArrayParameterType::INTEGER],
            );
            /** @var array<int, int> $linkOf operationId => seriesId */
            $linkOf = [];
            foreach ($currentLinks as $link) {
                $linkOf[(int) $link['id']] = (int) $link['recurring_series_id'];
            }

            // À poser : opérations retenues dont le lien diffère.
            /** @var array<int, list<int>> $toSet */
            $toSet = [];
            foreach ($retainedBySeries as $seriesId => $operationIds) {
                foreach ($operationIds as $operationId) {
                    if (($linkOf[$operationId] ?? null) !== $seriesId) {
                        $toSet[$seriesId][] = $operationId;
                    }
                }
            }
            // À vider : opérations liées à une série dont le groupe est détecté mais plus retenues,
            // et toutes celles des séries « ignored ».
            /** @var array<int, list<int>> $toClear */
            $toClear = [];
            foreach ($retainedBySeries as $seriesId => $operationIds) {
                $retained = array_flip($operationIds);
                foreach ($linkOf as $operationId => $linkedSeriesId) {
                    if ($linkedSeriesId === $seriesId && !isset($retained[$operationId])) {
                        $toClear[$seriesId][] = $operationId;
                    }
                }
            }
            foreach ($ignoredSeriesIds as $seriesId) {
                foreach ($linkOf as $operationId => $linkedSeriesId) {
                    if ($linkedSeriesId === $seriesId) {
                        $toClear[$seriesId][] = $operationId;
                    }
                }
            }

            foreach ($toSet as $seriesId => $operationIds) {
                foreach (array_chunk($operationIds, self::CHUNK_SIZE) as $chunk) {
                    $report->linked += $connection->executeStatement(
                        'UPDATE operation SET recurring_series_id = :seriesId WHERE id IN (:ids) AND recurring_series_id IS DISTINCT FROM :seriesId',
                        ['seriesId' => $seriesId, 'ids' => $chunk],
                        ['seriesId' => ParameterType::INTEGER, 'ids' => ArrayParameterType::INTEGER],
                    );
                }
            }
            foreach ($toClear as $seriesId => $operationIds) {
                foreach (array_chunk(array_unique($operationIds), self::CHUNK_SIZE) as $chunk) {
                    $report->linked += $connection->executeStatement(
                        'UPDATE operation SET recurring_series_id = NULL WHERE id IN (:ids) AND recurring_series_id IS NOT NULL',
                        ['ids' => $chunk],
                        ['ids' => ArrayParameterType::INTEGER],
                    );
                }
            }
        }

        return $report;
    }

    /**
     * Catégorie la plus fréquente parmi les opérations retenues (null si aucune catégorisée).
     *
     * @param list<array{id: int, date: \DateTimeImmutable, amount: int, categoryId: ?int}> $operations
     * @param list<int> $linkedIds
     */
    private static function majorityCategory(array $operations, array $linkedIds): ?int
    {
        $retained = array_flip($linkedIds);
        $counts = [];
        foreach ($operations as $operation) {
            if (isset($retained[$operation['id']], $operation['categoryId'])) {
                $categoryId = $operation['categoryId'];
                $counts[$categoryId] = ($counts[$categoryId] ?? 0) + 1;
            }
        }
        if ([] === $counts) {
            return null;
        }
        arsort($counts);

        return (int) array_key_first($counts);
    }

    /** @return list<int>|null */
    private static function decodeActiveMonths(mixed $raw): ?array
    {
        if (null === $raw || '' === $raw) {
            return null;
        }
        $decoded = json_decode((string) $raw, true);

        return \is_array($decoded) ? array_map('intval', $decoded) : null;
    }

    /**
     * Échéance dépassée : nextExpected (ou lastOccurrence) + nominal + tolérance < today.
     *
     * @param array<string, mixed> $series ligne recurring_series
     */
    private static function isPastDeadline(array $series, \DateTimeImmutable $today): bool
    {
        $frequency = RecurrenceFrequency::from((string) $series['frequency']);
        $base = null !== $series['next_expected'] ? (string) $series['next_expected'] : (string) $series['last_occurrence'];
        $deadline = (new \DateTimeImmutable($base))->modify('+'.(int) ceil($frequency->nominalDays() + $frequency->toleranceDays()).' days');

        return $deadline < $today->setTime(0, 0);
    }
}
