<?php

declare(strict_types=1);

namespace App\Stats;

use App\Entity\Household;
use App\Entity\Operation;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Requêtes agrégées des statistiques (contrat docs/api.md, étape 8).
 * Chaque endpoint = 1 à 3 requêtes SQL agrégées (DBAL natif, generate_series pour les
 * mois/jours manquants, CTE pour le solde reconstitué) : aucune boucle PHP sur les
 * opérations brutes. La classification des montants est l'expression unique
 * AmountClassifier::kindExpr().
 *
 * Les méthodes renvoient des données brutes (ids de catégories/comptes) ; la présentation
 * (CategoryRef/AccountRef) est faite par le contrôleur.
 */
final class StatsRepository
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /**
     * Totaux de la période : income/expense (positifs), net, savingsRate, compteurs.
     * Le taux d'épargne est calculé sur $ratePeriod (mois complets, hors mois en cours
     * incomplet) quand elle est fournie ; savingsRateFrom/savingsRateTo = plage réellement
     * utilisée pour le taux.
     *
     * @param list<int>|null $accountIds périmètre (null = tout le foyer)
     * @return array{from: string, to: string, income: int, expense: int, net: int, savingsRate: float|null, savingsRateFrom: string, savingsRateTo: string, operationsCount: int, uncategorizedExpense: int, transfersIn: int, transfersOut: int}
     */
    public function overview(Household $household, ?array $accountIds, StatsPeriod $period, ?StatsPeriod $ratePeriod = null): array
    {
        [$cte, $params, $types] = $this->base($household, $accountIds, $period);
        $sql = $cte."\n"
            ."SELECT COUNT(*) AS cnt,\n"
            .'       COALESCE(SUM('.AmountClassifier::incomeAmountExpr().'), 0) AS income,'."\n"
            .'       COALESCE(SUM('.AmountClassifier::expenseAmountExpr().'), 0) AS expense,'."\n"
            .'       COALESCE(SUM('.AmountClassifier::uncategorizedExpenseExpr().'), 0) AS uncat_expense,'."\n"
            .'       COALESCE(SUM('.AmountClassifier::transferInExpr().'), 0) AS transfers_in,'."\n"
            .'       COALESCE(SUM('.AmountClassifier::transferOutExpr().'), 0) AS transfers_out'."\n"
            .$this->fromWhere($accountIds);
        $row = $this->connection()->fetchAssociative($sql, $params, $types);

        $income = (int) $row['income'];
        $expense = (int) $row['expense'];
        $net = $income - $expense;

        // Taux d'épargne sur la plage dédiée (mois complets) si elle diffère de la période.
        $ratePeriod ??= $period;
        $rateIncome = $income;
        $rateExpense = $expense;
        if ($ratePeriod->from != $period->from || $ratePeriod->to != $period->to) {
            [$rCte, $rParams, $rTypes] = $this->base($household, $accountIds, $ratePeriod);
            $rRow = $this->connection()->fetchAssociative(
                $rCte."\n"
                .'SELECT COALESCE(SUM('.AmountClassifier::incomeAmountExpr().'), 0) AS income,'."\n"
                .'       COALESCE(SUM('.AmountClassifier::expenseAmountExpr().'), 0) AS expense'."\n"
                .$this->fromWhere($accountIds),
                $rParams,
                $rTypes,
            );
            $rateIncome = (int) $rRow['income'];
            $rateExpense = (int) $rRow['expense'];
        }
        $rateNet = $rateIncome - $rateExpense;

        return [
            'from' => $period->from->format('Y-m-d'),
            'to' => $period->to->format('Y-m-d'),
            'income' => $income,
            'expense' => $expense,
            'net' => $net,
            'savingsRate' => $rateIncome > 0 ? round($rateNet / $rateIncome, 4) : null,
            'savingsRateFrom' => $ratePeriod->from->format('Y-m-d'),
            'savingsRateTo' => $ratePeriod->to->format('Y-m-d'),
            'operationsCount' => (int) $row['cnt'],
            'uncategorizedExpense' => (int) $row['uncat_expense'],
            'transfersIn' => (int) $row['transfers_in'],
            'transfersOut' => (int) $row['transfers_out'],
        ];
    }

    /**
     * Moyenne mensuelle (centimes arrondis) des 12 mois complets précédant le mois de « to ».
     *
     * @param list<int>|null $accountIds
     * @return array{income: int, expense: int}
     */
    public function average12(Household $household, ?array $accountIds, \DateTimeImmutable $to): array
    {
        $period = (new StatsPeriod($to, $to))->last12FullMonths();
        [$cte, $params, $types] = $this->base($household, $accountIds, $period);
        $sql = $cte."\n"
            .'SELECT COALESCE(SUM('.AmountClassifier::incomeAmountExpr().'), 0) AS income,'."\n"
            .'       COALESCE(SUM('.AmountClassifier::expenseAmountExpr().'), 0) AS expense'."\n"
            .$this->fromWhere($accountIds);
        $row = $this->connection()->fetchAssociative($sql, $params, $types);

        return [
            'income' => (int) round((int) $row['income'] / 12),
            'expense' => (int) round((int) $row['expense'] / 12),
        ];
    }

    /**
     * Série mensuelle complète (mois sans opération = 0) : totaux, par racine de dépense
     * et non catégorisé.
     *
     * @param list<int>|null $accountIds
     * @return array{months: list<string>, income: list<int>, expense: list<int>, net: list<int>, uncategorized: list<int>, byRoot: list<array{rootId: int, values: list<int>}>}
     */
    public function monthly(Household $household, ?array $accountIds, StatsPeriod $period): array
    {
        [$cte, $params, $types] = $this->base($household, $accountIds, $period);
        $kind = AmountClassifier::kindExpr();
        // Mois de la date effective (COALESCE(spent_at, date), ADR 0014).
        $eff = Operation::effectiveDateSql('o');

        // 1re requête : série de mois (generate_series) + totaux agrégés.
        $sql = $cte.",\n"
            ."months AS (\n"
            ."    SELECT (generate_series(date_trunc('month', params.from_d), date_trunc('month', params.to_d), interval '1 month'))::date AS m\n"
            ."    FROM params\n"
            ."),\n"
            ."agg AS (\n"
            ."    SELECT date_trunc('month', $eff)::date AS m,\n"
            .'           SUM('.AmountClassifier::incomeAmountExpr().') AS income,'."\n"
            .'           SUM('.AmountClassifier::expenseAmountExpr().') AS expense,'."\n"
            .'           SUM('.AmountClassifier::uncategorizedExpenseExpr().') AS uncat'."\n"
            .'    '.$this->fromWhere($accountIds)."\n"
            ."    GROUP BY 1\n"
            .")\n"
            ."SELECT months.m::date AS m,\n"
            ."       COALESCE(agg.income, 0) AS income,\n"
            ."       COALESCE(agg.expense, 0) AS expense,\n"
            ."       COALESCE(agg.uncat, 0) AS uncat\n"
            ."FROM months LEFT JOIN agg ON agg.m = months.m\n"
            ."ORDER BY months.m";
        $rows = $this->connection()->fetchAllAssociative($sql, $params, $types);

        // 2e requête : dépense par mois et par racine (catégorisée uniquement).
        $sql = $cte.",\n"
            ."agg AS (\n"
            ."    SELECT date_trunc('month', $eff)::date AS m, COALESCE(r.id, c.id) AS root_id, SUM(-o.amount) AS v\n"
            .'    '.$this->fromWhere($accountIds)."\n"
            ."    AND $kind = '".AmountClassifier::EXPENSE."' AND o.category_id IS NOT NULL\n"
            ."    GROUP BY 1, 2\n"
            .")\n"
            ."SELECT agg.m::date AS m, agg.root_id, agg.v FROM agg ORDER BY agg.m, agg.root_id";
        $byRootRows = $this->connection()->fetchAllAssociative($sql, $params, $types);

        $months = [];
        $income = [];
        $expense = [];
        $uncategorized = [];
        $monthIndex = [];
        foreach ($rows as $i => $row) {
            $month = (new \DateTimeImmutable((string) $row['m']))->format('Y-m');
            $months[] = $month;
            $monthIndex[$month] = $i;
            $income[] = (int) $row['income'];
            $expense[] = (int) $row['expense'];
            $uncategorized[] = (int) $row['uncat'];
        }
        $net = array_map(static fn (int $i, int $e): int => $i - $e, $income, $expense);

        $byRoot = [];
        foreach ($byRootRows as $row) {
            $rootId = (int) $row['root_id'];
            $month = (new \DateTimeImmutable((string) $row['m']))->format('Y-m');
            $byRoot[$rootId]['values'][$monthIndex[$month]] = (int) $row['v'];
        }
        $byRootList = [];
        foreach ($byRoot as $rootId => $entry) {
            $values = array_fill(0, count($months), 0);
            foreach ($entry['values'] as $i => $v) {
                $values[$i] = $v;
            }
            $byRootList[] = ['rootId' => $rootId, 'values' => $values];
        }
        usort($byRootList, static fn (array $a, array $b): int => array_sum($b['values']) <=> array_sum($a['values']));

        return [
            'months' => $months,
            'income' => $income,
            'expense' => $expense,
            'net' => $net,
            'uncategorized' => $uncategorized,
            'byRoot' => $byRootList,
        ];
    }

    /**
     * Classement des catégories pour une direction (racines, ou sous-catégories de
     * parentId + la racine elle-même). comparePeriod = période de comparaison (compareTotal),
     * comparablePeriod = période comparable courante (comparableTotal, renseignée quand
     * compare est demandé : le mois en cours incomplet est exclu des deux).
     *
     * @param list<int>|null $accountIds
     * @return list<array{categoryId: ?int, total: int, count: int, share: float, compareTotal: ?int, comparableTotal: ?int}> triés par total desc (categoryId null = non catégorisé)
     */
    public function categories(Household $household, ?array $accountIds, StatsPeriod $period, string $direction, ?int $parentId, ?StatsPeriod $comparePeriod, ?StatsPeriod $comparablePeriod = null): array
    {
        $sign = AmountClassifier::EXPENSE === $direction ? '-o.amount' : 'o.amount';
        $kind = AmountClassifier::kindExpr();
        // Sans parentId : agrégation par racine (COALESCE(r.id, c.id), null = non catégorisé) ;
        // avec parentId : détail des sous-catégories (c.id, la racine elle-même incluse).
        $groupExpr = null !== $parentId ? 'c.id' : 'COALESCE(r.id, c.id)';

        [$cte, $params, $types] = $this->base($household, $accountIds, $period);
        $params['direction'] = $direction;
        $types['direction'] = ParameterType::STRING;
        $extra = '';
        if (null !== $parentId) {
            $extra = ' AND (c.id = :parentId OR c.parent_id = :parentId)';
            $params['parentId'] = $parentId;
            $types['parentId'] = ParameterType::INTEGER;
        }
        $sql = $cte."\n"
            ."SELECT $groupExpr AS category_id, COUNT(*) AS cnt, COALESCE(SUM($sign), 0) AS total\n"
            .$this->fromWhere($accountIds)."\n"
            ."AND $kind = :direction$extra\n"
            ."GROUP BY 1\n"
            ."ORDER BY total DESC, category_id ASC";
        $rows = $this->connection()->fetchAllAssociative($sql, $params, $types);

        $compareTotals = null !== $comparePeriod
            ? $this->categoryTotals($household, $accountIds, $comparePeriod, $direction, $parentId)
            : [];
        $comparableTotals = null !== $comparablePeriod
            ? $this->categoryTotals($household, $accountIds, $comparablePeriod, $direction, $parentId)
            : [];

        $denominator = array_sum(array_map('intval', array_column($rows, 'total')));

        return array_map(
            static function (array $row) use ($denominator, $compareTotals, $comparableTotals): array {
                $categoryId = null !== $row['category_id'] ? (int) $row['category_id'] : null;
                $key = null === $categoryId ? 'null' : (string) $categoryId;
                $total = (int) $row['total'];

                return [
                    'categoryId' => $categoryId,
                    'total' => $total,
                    'count' => (int) $row['cnt'],
                    'share' => $denominator > 0 ? round($total / $denominator, 4) : 0.0,
                    'compareTotal' => $compareTotals[$key] ?? null,
                    'comparableTotal' => $comparableTotals[$key] ?? null,
                ];
            },
            $rows,
        );
    }

    /**
     * Moyennes mensuelles des dépenses et des revenus des k derniers mois complets précédant le mois de
     * $currentMonthFirst (k ≤ 12, bornés au premier mois ayant au moins une opération dans
     * le périmètre ; même périmètre comptes et même classification que `expense`).
     * `expenseVariable` / `incomeVariable` = mêmes moyennes restreintes aux opérations NON liées
     * à une série récurrente non ignorée (operation.recurring_series_id NULL ou série « ignored »).
     *
     * @param list<int>|null $accountIds
     * @return array{expense: int, income: int, basisMonths: int, expenseVariable: int, incomeVariable: int}|null null si aucune opération avant le mois en cours (k = 0)
     */
    public function monthlyAverages(Household $household, ?array $accountIds, \DateTimeImmutable $currentMonthFirst): ?array
    {
        // Toutes les opérations du périmètre strictement avant le mois en cours.
        $period = new StatsPeriod(new \DateTimeImmutable('1900-01-01'), $currentMonthFirst->modify('-1 day'));
        [$cte, $params, $types] = $this->base($household, $accountIds, $period);
        $notRecurring = "(o.recurring_series_id IS NULL OR rs.status = 'ignored')";
        $eff = Operation::effectiveDateSql('o');
        $sql = $cte."\n"
            ."SELECT date_trunc('month', $eff)::date AS m, COALESCE(SUM(".AmountClassifier::expenseAmountExpr()."), 0) AS e,\n"
            ."COALESCE(SUM(".AmountClassifier::incomeAmountExpr()."), 0) AS i,\n"
            ."COALESCE(SUM(CASE WHEN $notRecurring THEN ".AmountClassifier::expenseAmountExpr()." ELSE 0 END), 0) AS e_var,\n"
            ."COALESCE(SUM(CASE WHEN $notRecurring THEN ".AmountClassifier::incomeAmountExpr()." ELSE 0 END), 0) AS i_var\n"
            .$this->fromWhere($accountIds, true)."\n"
            ."GROUP BY 1\n"
            ."ORDER BY 1";
        $rows = $this->connection()->fetchAllAssociative($sql, $params, $types);
        if ([] === $rows) {
            return null;
        }

        // Fenêtre : les 12 derniers mois complets, sans remonter avant le premier mois ayant une opération.
        $firstOpMonth = new \DateTimeImmutable((string) $rows[0]['m']);
        $bound = $currentMonthFirst->modify('-12 months');
        if ($firstOpMonth > $bound) {
            $bound = $firstOpMonth;
        }
        $k = ((int) $currentMonthFirst->format('Y') - (int) $bound->format('Y')) * 12
            + ((int) $currentMonthFirst->format('n') - (int) $bound->format('n'));

        $expense = 0;
        $income = 0;
        $expenseVariable = 0;
        $incomeVariable = 0;
        foreach ($rows as $row) {
            $month = new \DateTimeImmutable((string) $row['m']);
            if ($month >= $bound) {
                $expense += (int) $row['e'];
                $income += (int) $row['i'];
                $expenseVariable += (int) $row['e_var'];
                $incomeVariable += (int) $row['i_var'];
            }
        }

        return [
            'expense' => (int) round($expense / $k),
            'income' => (int) round($income / $k),
            'basisMonths' => $k,
            'expenseVariable' => (int) round($expenseVariable / $k),
            'incomeVariable' => (int) round($incomeVariable / $k),
        ];
    }

    /**
     * Totaux « non récurrents » (opérations non liées à une série non ignorée) du mois en cours
     * sur [from, to] : dépenses et revenus positifs, même classification que partout.
     *
     * @param list<int>|null $accountIds
     * @return array{expense: int, income: int}
     */
    public function currentMonthVariable(Household $household, ?array $accountIds, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        [$cte, $params, $types] = $this->base($household, $accountIds, new StatsPeriod($from, $to));
        $notRecurring = "(o.recurring_series_id IS NULL OR rs.status = 'ignored')";
        $sql = $cte."\n"
            ."SELECT COALESCE(SUM(CASE WHEN $notRecurring THEN ".AmountClassifier::expenseAmountExpr()." ELSE 0 END), 0) AS e,\n"
            ."COALESCE(SUM(CASE WHEN $notRecurring THEN ".AmountClassifier::incomeAmountExpr()." ELSE 0 END), 0) AS i\n"
            .$this->fromWhere($accountIds, true);
        $row = $this->connection()->fetchAssociative($sql, $params, $types);

        return [
            'expense' => (int) $row['e'],
            'income' => (int) $row['i'],
        ];
    }

    /**
     * Top commerçants pour une direction (clé de groupe coalesce(merchant_group, merchant, normalized_label) :
     * les variantes d'un même commerçant sont regroupées, voir App\Merchant\MerchantGrouper).
     *
     * @param list<int>|null    $accountIds
     * @param list<string>|null $keys       restreint aux clés de groupe données (null = toutes)
     * @return list<array{key: string, merchant: ?string, total: int, count: int, categoryId: ?int}> triés par total desc
     */
    public function merchants(Household $household, ?array $accountIds, StatsPeriod $period, string $direction, int $limit, ?array $keys = null): array
    {
        $sign = AmountClassifier::EXPENSE === $direction ? '-o.amount' : 'o.amount';
        $kind = AmountClassifier::kindExpr();
        [$cte, $params, $types] = $this->base($household, $accountIds, $period);
        $params['direction'] = $direction;
        $params['limit'] = $limit;
        $types['direction'] = ParameterType::STRING;
        $types['limit'] = ParameterType::INTEGER;
        // Restriction optionnelle à des clés de groupe données (comparaison avec une autre période).
        $keysSql = '';
        if (null !== $keys) {
            $keysSql = ' AND COALESCE(o.merchant_group, o.merchant, o.normalized_label) IN (:keys)';
            $params['keys'] = $keys;
            $types['keys'] = ArrayParameterType::STRING;
        }

        // 1re requête : agrégats par groupe, limités.
        $sql = $cte."\n"
            ."SELECT COALESCE(o.merchant_group, o.merchant, o.normalized_label) AS k, CASE WHEN MAX(o.merchant) IS NULL THEN NULL ELSE COALESCE(MAX(o.merchant_group), MAX(o.merchant)) END AS merchant,\n"
            ."       COUNT(*) AS cnt, COALESCE(SUM($sign), 0) AS total\n"
            .$this->fromWhere($accountIds)."\n"
            ."AND $kind = :direction$keysSql\n"
            ."GROUP BY 1\n"
            ."ORDER BY total DESC, k ASC\n"
            ."LIMIT :limit";
        $rows = $this->connection()->fetchAllAssociative($sql, $params, $types);

        // 2e requête : catégorie majoritaire par groupe (agrégat, pas les opérations brutes).
        $catRows = $this->connection()->fetchAllAssociative(
            $cte."\n"
            ."SELECT COALESCE(o.merchant_group, o.merchant, o.normalized_label) AS k, o.category_id AS cid, COUNT(*) AS cnt\n"
            .$this->fromWhere($accountIds)."\n"
            ."AND $kind = :direction AND o.category_id IS NOT NULL$keysSql\n"
            ."GROUP BY 1, 2",
            $params,
            $types,
        );
        $major = [];
        foreach ($catRows as $catRow) {
            $k = (string) $catRow['k'];
            $cnt = (int) $catRow['cnt'];
            if (!isset($major[$k]) || $cnt > $major[$k]['cnt']) {
                $major[$k] = ['cid' => (int) $catRow['cid'], 'cnt' => $cnt];
            }
        }

        return array_map(
            static fn (array $row): array => [
                'key' => (string) $row['k'],
                'merchant' => null !== $row['merchant'] ? (string) $row['merchant'] : null,
                'total' => (int) $row['total'],
                'count' => (int) $row['cnt'],
                'categoryId' => isset($major[(string) $row['k']]) ? $major[(string) $row['k']]['cid'] : null,
            ],
            $rows,
        );
    }

    /**
     * Solde reconstitué par compte, ancré sur les relevés (Statement) + opérations.
     * Granularité month (fins de mois + « to » en dernier point) ou day.
     * null avant la première donnée connue du compte (ouverture du premier relevé,
     * ou premier relevé tout court).
     *
     * @param list<int>|null $accountIds
     * @return array{dates: list<string>, accounts: array<int, list<?int>>} values alignées sur dates
     */
    public function balance(Household $household, ?array $accountIds, StatsPeriod $period, string $granularity): array
    {
        [$cte, $params, $types] = $this->base($household, $accountIds, $period);

        // Fins de mois : generate_series sur les premiers de mois, puis +1 month -1 day
        // (poser l'intervalle dans le SELECT, sinon 28 fév + 1 month = 28 mars).
        $series = 'day' === $granularity
            ? "SELECT (generate_series(params.from_d, params.to_d, interval '1 day'))::date AS p FROM params"
            : "SELECT (generate_series(date_trunc('month', params.from_d), date_trunc('month', params.to_d), interval '1 month')
                     + interval '1 month - 1 day')::date AS p
                 FROM params
               UNION
               SELECT params.to_d FROM params
               WHERE params.to_d < date_trunc('month', params.to_d) + interval '1 month - 1 day'";
        $scopeAccounts = null !== $accountIds ? ' AND a.id IN (:scopeIds)' : '';

        $sql = $cte.",\n"
            ."accounts AS (\n"
            ."    SELECT a.id FROM account a CROSS JOIN params\n"
            ."    WHERE a.household_id = params.household_id$scopeAccounts\n"
            ."),\n"
            ."series AS ($series),\n"
            ."points AS (SELECT ac.id AS account_id, s.p FROM accounts ac CROSS JOIN series s),\n"
            // Pas de borne basse sur from : l'ancre (dernière clôture ou ouverture du premier
            // relevé) peut précéder la période, les opérations intermédiaires comptent.
            ."daily AS (\n"
            ."    SELECT o.account_id, o.date::date AS d, SUM(o.amount) AS s\n"
            ."    FROM operation o\n"
            ."    WHERE o.account_id IN (SELECT id FROM accounts)\n"
            ."      AND o.duplicate_status <> 'probable'\n"
            ."      AND o.date <= (SELECT to_d FROM params)\n"
            ."    GROUP BY 1, 2\n"
            ."),\n"
            ."anchor AS (\n"
            ."    SELECT p.account_id, p.p AS point,\n"
            ."           (SELECT st.closing_balance FROM statement st\n"
            ."             WHERE st.account_id = p.account_id AND st.closing_date <= p.p\n"
            ."             ORDER BY st.closing_date DESC LIMIT 1) AS anchor_bal,\n"
            ."           (SELECT st.closing_date::date FROM statement st\n"
            ."             WHERE st.account_id = p.account_id AND st.closing_date <= p.p\n"
            ."             ORDER BY st.closing_date DESC LIMIT 1) AS anchor_date\n"
            ."    FROM points p\n"
            ."),\n"
            ."first_stmt AS (\n"
            ."    SELECT DISTINCT ON (st.account_id) st.account_id, st.opening_date::date AS od, st.opening_balance\n"
            ."    FROM statement st\n"
            ."    WHERE st.account_id IN (SELECT id FROM accounts)\n"
            ."    ORDER BY st.account_id, st.closing_date ASC\n"
            ."),\n"
            ."post AS (\n"
            ."    SELECT an.account_id, an.point, SUM(d.s) AS s\n"
            ."    FROM anchor an\n"
            ."    JOIN daily d ON d.account_id = an.account_id AND d.d > an.anchor_date AND d.d <= an.point\n"
            ."    GROUP BY 1, 2\n"
            ."),\n"
            ."pre AS (\n"
            ."    SELECT an.account_id, an.point, SUM(d.s) AS s\n"
            ."    FROM anchor an\n"
            ."    JOIN first_stmt f ON f.account_id = an.account_id\n"
            ."    JOIN daily d ON d.account_id = an.account_id AND d.d > f.od AND d.d <= an.point\n"
            ."    WHERE an.anchor_date IS NULL AND f.opening_balance IS NOT NULL AND an.point >= f.od\n"
            ."    GROUP BY 1, 2\n"
            .")\n"
            ."SELECT an.account_id, an.point,\n"
            ."       CASE\n"
            ."           WHEN an.anchor_bal IS NOT NULL THEN an.anchor_bal + COALESCE(po.s, 0)\n"
            ."           WHEN f.opening_balance IS NOT NULL AND an.point >= f.od THEN f.opening_balance + COALESCE(pr.s, 0)\n"
            ."           ELSE NULL\n"
            ."       END AS balance\n"
            ."FROM anchor an\n"
            ."LEFT JOIN post po ON po.account_id = an.account_id AND po.point = an.point\n"
            ."LEFT JOIN pre pr ON pr.account_id = an.account_id AND pr.point = an.point\n"
            ."LEFT JOIN first_stmt f ON f.account_id = an.account_id\n"
            ."ORDER BY an.account_id, an.point";
        $rows = $this->connection()->fetchAllAssociative($sql, $params, $types);

        $dates = [];
        $byAccount = [];
        foreach ($rows as $row) {
            $point = (new \DateTimeImmutable((string) $row['point']))->format('Y-m-d');
            $dates[$point] = true;
            $byAccount[(int) $row['account_id']][$point] = null !== $row['balance'] ? (int) $row['balance'] : null;
        }
        $dates = array_keys($dates);
        sort($dates);

        $accounts = [];
        foreach ($byAccount as $accountId => $values) {
            $accounts[$accountId] = array_map(static fn (string $d): ?int => $values[$d] ?? null, $dates);
        }

        return ['dates' => $dates, 'accounts' => $accounts];
    }

    /**
     * Flux pour le sankey : revenus et dépenses agrégés par racine (null = non catégorisé).
     *
     * @param list<int>|null $accountIds
     * @return array{income: list<array{rootId: ?int, value: int, count: int}>, expense: list<array{rootId: ?int, value: int, count: int}>}
     */
    public function sankey(Household $household, ?array $accountIds, StatsPeriod $period): array
    {
        [$cte, $params, $types] = $this->base($household, $accountIds, $period);
        $kind = AmountClassifier::kindExpr();
        $sql = $cte."\n"
            ."SELECT CASE WHEN $kind = '".AmountClassifier::INCOME."' THEN 'income' ELSE 'expense' END AS side,\n"
            ."       COALESCE(r.id, c.id) AS root_id,\n"
            ."       COUNT(*) AS cnt,\n"
            ."       SUM(CASE WHEN $kind = '".AmountClassifier::INCOME."' THEN o.amount ELSE -o.amount END) AS v\n"
            .$this->fromWhere($accountIds)."\n"
            ."AND $kind IN ('".AmountClassifier::INCOME."', '".AmountClassifier::EXPENSE."')\n"
            ."GROUP BY 1, 2";
        $rows = $this->connection()->fetchAllAssociative($sql, $params, $types);

        $result = ['income' => [], 'expense' => []];
        foreach ($rows as $row) {
            $side = (string) $row['side'];
            $result[$side][] = [
                'rootId' => null !== $row['root_id'] ? (int) $row['root_id'] : null,
                'value' => (int) $row['v'],
                'count' => (int) $row['cnt'],
            ];
        }

        return $result;
    }

    /**
     * Dépenses par jour (jours sans dépense omis).
     *
     * @param list<int>|null $accountIds
     * @return list<array{date: string, expense: int, count: int}>
     */
    public function calendar(Household $household, ?array $accountIds, StatsPeriod $period): array
    {
        [$cte, $params, $types] = $this->base($household, $accountIds, $period);
        $kind = AmountClassifier::kindExpr();
        $eff = Operation::effectiveDateSql('o');
        $sql = $cte."\n"
            ."SELECT $eff::date AS d, COUNT(*) AS cnt, COALESCE(SUM(-o.amount), 0) AS expense\n"
            .$this->fromWhere($accountIds)."\n"
            ."AND $kind = '".AmountClassifier::EXPENSE."'\n"
            ."GROUP BY 1\n"
            ."ORDER BY 1";
        $rows = $this->connection()->fetchAllAssociative($sql, $params, $types);

        return array_map(
            static fn (array $row): array => [
                'date' => (new \DateTimeImmutable((string) $row['d']))->format('Y-m-d'),
                'expense' => (int) $row['expense'],
                'count' => (int) $row['cnt'],
            ],
            $rows,
        );
    }

    /**
     * Tendance mensuelle d'une catégorie (sous-catégories incluses), mois vides à 0.
     *
     * @param list<int>|null $accountIds
     * @param list<int> $categoryIds catégorie et ses sous-catégories
     * @return array{months: list<string>, values: list<int>, counts: list<int>}
     */
    public function categoryTrend(Household $household, ?array $accountIds, StatsPeriod $period, array $categoryIds, string $rootKind): array
    {
        $sign = AmountClassifier::EXPENSE === $rootKind ? '-o.amount' : 'o.amount';
        [$cte, $params, $types] = $this->base($household, $accountIds, $period);
        $params['catIds'] = $categoryIds;
        $types['catIds'] = ArrayParameterType::INTEGER;
        $eff = Operation::effectiveDateSql('o');

        $sql = $cte.",\n"
            ."months AS (\n"
            ."    SELECT (generate_series(date_trunc('month', params.from_d), date_trunc('month', params.to_d), interval '1 month'))::date AS m\n"
            ."    FROM params\n"
            ."),\n"
            ."agg AS (\n"
            ."    SELECT date_trunc('month', $eff)::date AS m, SUM($sign) AS v, COUNT(*) AS cnt\n"
            .'    '.$this->fromWhere($accountIds)."\n"
            ."    AND o.category_id IN (:catIds)\n"
            ."    GROUP BY 1\n"
            .")\n"
            ."SELECT months.m::date AS m, COALESCE(agg.v, 0) AS v, COALESCE(agg.cnt, 0) AS cnt\n"
            ."FROM months LEFT JOIN agg ON agg.m = months.m\n"
            ."ORDER BY months.m";
        $rows = $this->connection()->fetchAllAssociative($sql, $params, $types);

        $months = [];
        $values = [];
        $counts = [];
        foreach ($rows as $row) {
            $months[] = (new \DateTimeImmutable((string) $row['m']))->format('Y-m');
            $values[] = (int) $row['v'];
            $counts[] = (int) $row['cnt'];
        }

        return ['months' => $months, 'values' => $values, 'counts' => $counts];
    }

    /**
     * Dépenses par véhicule sur la période (même convention de signe que `categories` :
     * total positif = somme de -amount, kind « expense » uniquement, virements internes exclus)
     * + non affectées (catégories vehicleRelated sans véhicule). `financingTotal` : part des
     * catégories `financing` (crédit…), exclue du coût d'usage (ADR 0011).
     *
     * @param list<int>|null $accountIds
     * @return array{vehicles: list<array{vehicleId: int, total: int, financingTotal: int, count: int, byCategory: list<array{categoryId: ?int, total: int, count: int}>}>, unassigned: array{total: int, count: int}}
     */
    public function vehicles(Household $household, ?array $accountIds, StatsPeriod $period): array
    {
        [$cte, $params, $types] = $this->base($household, $accountIds, $period);
        $kind = AmountClassifier::kindExpr();
        $expense = "AND $kind = '".AmountClassifier::EXPENSE."'";

        // Totaux par véhicule (tri total desc).
        $vehicleRows = $this->connection()->fetchAllAssociative(
            $cte."\n"
            ."SELECT o.vehicle_id AS vehicle_id, COUNT(*) AS cnt, COALESCE(SUM(-o.amount), 0) AS total,\n"
            ."       COALESCE(SUM(-o.amount) FILTER (WHERE c.financing), 0) AS financing_total\n"
            .$this->fromWhere($accountIds)."\n"
            ."$expense AND o.vehicle_id IS NOT NULL\n"
            ."GROUP BY 1\n"
            ."ORDER BY total DESC, vehicle_id ASC",
            $params,
            $types,
        );

        // Détail par catégorie (catégorie exacte de l'opération, null = non catégorisée).
        $categoryRows = $this->connection()->fetchAllAssociative(
            $cte."\n"
            ."SELECT o.vehicle_id AS vehicle_id, o.category_id AS category_id, COUNT(*) AS cnt, COALESCE(SUM(-o.amount), 0) AS total\n"
            .$this->fromWhere($accountIds)."\n"
            ."$expense AND o.vehicle_id IS NOT NULL\n"
            ."GROUP BY 1, 2",
            $params,
            $types,
        );

        // Non affectées : catégories vehicleRelated sans véhicule, hors opérations « ignorées ».
        $unassignedRow = $this->connection()->fetchAssociative(
            $cte."\n"
            ."SELECT COUNT(*) AS cnt, COALESCE(SUM(-o.amount), 0) AS total\n"
            .$this->fromWhere($accountIds)."\n"
            ."$expense AND o.vehicle_id IS NULL AND c.vehicle_related = true AND o.vehicle_source IS DISTINCT FROM 'ignored'",
            $params,
            $types,
        ) ?: ['cnt' => 0, 'total' => 0];

        $byCategory = [];
        foreach ($categoryRows as $row) {
            $byCategory[(int) $row['vehicle_id']][] = [
                'categoryId' => null !== $row['category_id'] ? (int) $row['category_id'] : null,
                'total' => (int) $row['total'],
                'count' => (int) $row['cnt'],
            ];
        }

        $vehicles = [];
        foreach ($vehicleRows as $row) {
            $vehicleId = (int) $row['vehicle_id'];
            $vehicles[] = [
                'vehicleId' => $vehicleId,
                'total' => (int) $row['total'],
                'financingTotal' => (int) $row['financing_total'],
                'count' => (int) $row['cnt'],
                'byCategory' => $byCategory[$vehicleId] ?? [],
            ];
        }

        return [
            'vehicles' => $vehicles,
            'unassigned' => ['total' => (int) $unassignedRow['total'], 'count' => (int) $unassignedRow['cnt']],
        ];
    }

    /**
     * Dépense d'un véhicule (même convention que vehicles() : total positif = somme de
     * -amount, kind « expense » uniquement, virements internes exclus) restreinte à
     * [from, to], périmètre comptes inclus, séparée en usage / financement (catégories
     * `financing`, ADR 0011). Utilisée pour le coût au km (lot 4).
     *
     * @param list<int>|null $accountIds
     * @return array{usage: int, financing: int}
     */
    public function vehicleExpenseBetween(Household $household, ?array $accountIds, int $vehicleId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        [$cte, $params, $types] = $this->base($household, $accountIds, new StatsPeriod($from, $to));
        $kind = AmountClassifier::kindExpr();
        $params['vehicleId'] = $vehicleId;
        $types['vehicleId'] = ParameterType::INTEGER;

        $row = $this->connection()->fetchAssociative(
            $cte."\n"
            ."SELECT COALESCE(SUM(-o.amount) FILTER (WHERE c.financing IS NOT TRUE), 0) AS usage_total,\n"
            ."       COALESCE(SUM(-o.amount) FILTER (WHERE c.financing), 0) AS financing_total\n"
            .$this->fromWhere($accountIds)."\n"
            ."AND $kind = '".AmountClassifier::EXPENSE."' AND o.vehicle_id = :vehicleId",
            $params,
            $types,
        );

        return ['usage' => (int) $row['usage_total'], 'financing' => (int) $row['financing_total']];
    }

    private function connection(): Connection
    {
        return $this->entityManager->getConnection();
    }

    /**
     * Totaux par catégorie (clé 'null' = non catégorisé) sur une période auxiliaire
     * (comparaison / période comparable), même agrégation que categories().
     *
     * @param list<int>|null $accountIds
     * @return array<string, int>
     */
    private function categoryTotals(Household $household, ?array $accountIds, StatsPeriod $period, string $direction, ?int $parentId): array
    {
        $sign = AmountClassifier::EXPENSE === $direction ? '-o.amount' : 'o.amount';
        $kind = AmountClassifier::kindExpr();
        $groupExpr = null !== $parentId ? 'c.id' : 'COALESCE(r.id, c.id)';
        $extra = '';
        [$cte, $params, $types] = $this->base($household, $accountIds, $period);
        $params['direction'] = $direction;
        $types['direction'] = ParameterType::STRING;
        if (null !== $parentId) {
            $extra = ' AND (c.id = :parentId OR c.parent_id = :parentId)';
            $params['parentId'] = $parentId;
            $types['parentId'] = ParameterType::INTEGER;
        }
        $rows = $this->connection()->fetchAllAssociative(
            $cte."\n"
            ."SELECT $groupExpr AS category_id, COALESCE(SUM($sign), 0) AS total\n"
            .$this->fromWhere($accountIds)."\n"
            ."AND $kind = :direction$extra\n"
            ."GROUP BY 1",
            $params,
            $types,
        );

        $totals = [];
        foreach ($rows as $row) {
            $key = null === $row['category_id'] ? 'null' : (string) (int) $row['category_id'];
            $totals[$key] = (int) $row['total'];
        }

        return $totals;
    }

    /**
     * CTE « params » + paramètres/types de base (foyer, période, périmètre).
     *
     * @param list<int>|null $accountIds
     * @return array{0: string, 1: array<string, mixed>, 2: array<string, mixed>}
     */
    private function base(Household $household, ?array $accountIds, StatsPeriod $period): array
    {
        $params = [
            'household' => $household->getId(),
            'from' => $period->from,
            'to' => $period->to,
        ];
        $types = [
            'household' => ParameterType::INTEGER,
            'from' => Types::DATE_IMMUTABLE,
            'to' => Types::DATE_IMMUTABLE,
        ];
        if (null !== $accountIds) {
            $params['scopeIds'] = $accountIds;
            $types['scopeIds'] = ArrayParameterType::INTEGER;
        }

        return [
            'WITH params AS (SELECT :household::int AS household_id, :from::date AS from_d, :to::date AS to_d)',
            $params,
            $types,
        ];
    }

    /**
     * FROM/WHERE commun : opération o, compte a, catégorie c + racine r, période params.
     * La période porte sur la date effective COALESCE(spent_at, date) (ADR 0014) — sauf
     * balance(), qui garde o.date (la saisie compte à sa date réelle pour le solde reconstitué).
     * Avec $withSeries, joint aussi recurring_series rs (pour exclure les opérations liées
     * à une série non ignorée).
     *
     * @param list<int>|null $accountIds
     */
    private function fromWhere(?array $accountIds, bool $withSeries = false): string
    {
        $scope = null !== $accountIds ? ' AND a.id IN (:scopeIds)' : '';
        $series = $withSeries ? "\n                LEFT JOIN recurring_series rs ON rs.id = o.recurring_series_id" : '';
        $eff = Operation::effectiveDateSql('o');

        return 'FROM operation o
                JOIN account a ON a.id = o.account_id
                '.AmountClassifier::joins().$series.'
                CROSS JOIN params
                WHERE a.household_id = params.household_id'.$scope.'
                  AND o.duplicate_status <> \'probable\'
                  AND '.$eff.' >= params.from_d
                  AND '.$eff.' <= params.to_d';
    }
}
