<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import Card from 'primevue/card';
import Skeleton from 'primevue/skeleton';
import ProgressBar from 'primevue/progressbar';
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import Button from 'primevue/button';
import Tag from 'primevue/tag';
import { useToast } from 'primevue/usetoast';
import { useScopeStore } from '@/stores/scope';
import { usePeriodStore } from '@/stores/period';
import { useCategoriesStore } from '@/stores/categories';
import { getSummary } from '@/api/summary';
import { getBalance, getCategoriesStats, getMonthly, getOverview } from '@/api/stats';
import { apiMessage } from '@/api/http';
import { formatDate, formatDateTime, formatMoney, formatMonth, formatPercent, monthBounds } from '@/utils/format';
import { chartColors, useChartTheme, baseTooltip, baseLegend, baseGrid, valueAxis, categoryAxis, categoryAxisY, barItemStyle } from '@/charts/theme';
import { colorForCategory, foldToOthers, slotColor } from '@/charts/palette';
import PeriodFilter from '@/components/PeriodFilter.vue';
import KpiTile from '@/components/KpiTile.vue';
import ChartCard from '@/components/ChartCard.vue';
import VChart from '@/components/VChart.vue';
import MoneyAmount from '@/components/MoneyAmount.vue';

const router = useRouter();
const scope = useScopeStore();
const period = usePeriodStore();
const categoriesStore = useCategoriesStore();
const toast = useToast();
const theme = useChartTheme();

// ---------- compteurs existants (summary) ----------
const summary = ref(null);
const loadingSummary = ref(true);

const percentCategorized = computed(() => {
    const s = summary.value;
    if (!s || !s.operationsCount) return 0;
    return Math.round((100 * s.categorizedCount) / s.operationsCount);
});

async function loadSummary() {
    loadingSummary.value = true;
    try {
        summary.value = await getSummary(scope.params);
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Tableau de bord indisponible', detail: apiMessage(e), life: 6000 });
    } finally {
        loadingSummary.value = false;
    }
}

// Relevés mensuels manquants (trous dans la série ou relevés échus non importés).
const MISSING_SHOWN = 6;
const MISSING_REASON = {
    gap: { label: 'manquant', severity: 'danger' },
    overdue: { label: 'en retard', severity: 'warn' },
};
const missingStatements = computed(() => summary.value?.missingStatements ?? []);
// Alias affiché seulement si le périmètre contient plusieurs comptes.
const missingShowAccount = computed(() => (summary.value?.accounts?.length ?? 0) > 1);

// ---------- KPI (stats/overview) ----------
const overview = ref(null);
const loadingOverview = ref(true);
const overviewError = ref('');

async function loadOverview() {
    loadingOverview.value = true;
    overviewError.value = '';
    try {
        overview.value = await getOverview(scope.params, period.params);
    } catch (e) {
        overviewError.value = apiMessage(e, 'KPIs indisponibles');
    } finally {
        loadingOverview.value = false;
    }
}

// Variation en % vs période précédente : {text, up} ou null.
function deltaInfo(current, previous) {
    if (current == null || previous == null || previous === 0) return null;
    const pct = ((current - previous) / Math.abs(previous)) * 100;
    return { text: `${pct >= 0 ? '+' : ''}${Math.round(pct)} % vs période précédente`, up: current >= previous };
}

const kpis = computed(() => {
    const o = overview.value;
    if (!o) return [];
    const dExpense = deltaInfo(o.expense, o.previous?.expense);
    const dIncome = deltaInfo(o.income, o.previous?.income);
    const dNet = deltaInfo(o.net, o.previous?.net);
    return [
        {
            label: 'Dépenses',
            value: formatMoney(o.expense),
            delta: dExpense,
            favorable: dExpense ? !dExpense.up : null, // moins dépenser = favorable
            subtext: `Moy. mensuelle (12 mois) : ${formatMoney(o.average12?.expense)}`,
        },
        {
            label: 'Revenus',
            value: formatMoney(o.income),
            delta: dIncome,
            favorable: dIncome ? dIncome.up : null,
            subtext: `Moy. mensuelle (12 mois) : ${formatMoney(o.average12?.income)}`,
        },
        {
            label: 'Solde net',
            value: formatMoney(o.net),
            delta: dNet,
            favorable: dNet ? dNet.up : null,
            subtext: '',
        },
        {
            label: "Taux d'épargne",
            value: formatPercent(o.savingsRate),
            delta: null,
            favorable: null,
            subtext: "Part des revenus non dépensée",
        },
    ];
});

// ---------- dépenses mensuelles par poste (stats/monthly) ----------
const monthly = ref(null);
const loadingMonthly = ref(true);
const monthlyError = ref('');

async function loadMonthly() {
    loadingMonthly.value = true;
    monthlyError.value = '';
    try {
        monthly.value = await getMonthly(scope.params, period.params);
    } catch (e) {
        monthlyError.value = apiMessage(e, 'Graphique indisponible');
    } finally {
        loadingMonthly.value = false;
    }
}

const monthlyRoots = computed(() => {
    const m = monthly.value;
    if (!m) return [];
    const series = m.byRoot.map((r) => ({ category: r.category, values: r.values }));
    if (m.uncategorized?.some((v) => v)) series.push({ category: null, values: m.uncategorized });
    return foldToOthers(series, theme.mode.value);
});

const monthlyOption = computed(() => {
    const m = monthly.value;
    if (!m) return {};
    const c = chartColors(theme.mode.value);
    const roots = monthlyRoots.value;
    const labels = m.months.map((mo) => formatMonth(mo));
    const series = roots.map((r) => ({
        name: r.name,
        type: 'bar',
        stack: 'depenses',
        data: r.values,
        barMaxWidth: 26,
        itemStyle: barItemStyle(r.color, c.surface),
        emphasis: { focus: 'series' },
    }));
    // Ligne des revenus sur le MÊME axe (une seule échelle en €).
    series.push({
        name: 'Revenus',
        type: 'line',
        data: m.income,
        symbolSize: 8,
        lineStyle: { width: 2, color: c.inkSecondary },
        itemStyle: { color: c.inkSecondary },
    });
    return {
        tooltip: baseTooltip(c),
        legend: baseLegend(c),
        grid: baseGrid(),
        xAxis: categoryAxis(c, labels),
        yAxis: valueAxis(c),
        series,
    };
});

const monthlyEmpty = computed(() => !monthly.value || !monthly.value.months?.length);

// Clic sur un segment ⇒ opérations du mois cliqué pour cette racine.
function onMonthlyClick(e) {
    if (e.componentType !== 'series' || e.seriesType !== 'bar' || !monthly.value) return;
    const root = monthlyRoots.value[e.seriesIndex];
    const month = monthly.value.months[e.dataIndex];
    const b = monthBounds(month);
    const query = { from: b.from, to: b.to };
    if (root?.category) query.categoryId = root.category.id;
    else query.uncategorized = '1';
    router.push({ name: 'operations', query });
}

const monthlyTableRows = computed(() => {
    const m = monthly.value;
    if (!m) return [];
    return m.months.map((mo, i) => {
        const row = { month: formatMonth(mo), Revenus: m.income[i], Dépenses: m.expense[i] };
        for (const r of monthlyRoots.value) row[r.name] = r.values[i] ?? 0;
        return row;
    });
});

// ---------- répartition des dépenses (stats/categories) avec drill ----------
const drillParent = ref(null);
const cats = ref(null);
const loadingCats = ref(true);
const catsError = ref('');

async function loadCategories() {
    loadingCats.value = true;
    catsError.value = '';
    try {
        cats.value = await getCategoriesStats(scope.params, period.params, {
            direction: 'expense',
            parentId: drillParent.value,
        });
    } catch (e) {
        catsError.value = apiMessage(e, 'Graphique indisponible');
    } finally {
        loadingCats.value = false;
    }
}

watch(drillParent, loadCategories);

const drillParentName = computed(() => {
    if (!drillParent.value) return '';
    return categoriesStore.byId.get(drillParent.value)?.name ?? '';
});

const catsOption = computed(() => {
    const items = cats.value;
    if (!items) return {};
    const c = chartColors(theme.mode.value);
    const rows = items.map((it) => ({
        name: it.category?.name ?? 'Non catégorisé',
        value: it.total,
        count: it.count,
        share: it.share,
        category: it.category,
        itemStyle: { color: colorForCategory(it.category, theme.mode.value), borderRadius: 4 },
    }));
    // Barres horizontales triées : l'axe Y affiche le plus grand en haut.
    const labels = rows.map((r) => r.name).reverse();
    return {
        tooltip: { ...baseTooltip(c), trigger: 'item', axisPointer: { type: 'shadow' } },
        grid: baseGrid({ left: 8, right: 48, top: 8, bottom: 8 }),
        xAxis: valueAxis(c),
        yAxis: categoryAxisY(c, labels),
        series: [
            {
                name: 'Dépenses',
                type: 'bar',
                data: rows.map((r) => ({ value: r.value, itemStyle: r.itemStyle, category: r.category, count: r.count, share: r.share })).reverse(),
                barMaxWidth: 22,
                label: { show: false },
                emphasis: { focus: 'series' },
            },
        ],
    };
});

const catsEmpty = computed(() => !cats.value || !cats.value.length);

function onCatsClick(e) {
    const item = e.data;
    if (!item) return;
    const b = period.range;
    if (!drillParent.value && item.category) {
        // racine ⇒ détail des sous-catégories
        drillParent.value = item.category.id;
        return;
    }
    const query = { from: b.from, to: b.to };
    if (item.category) query.categoryId = item.category.id;
    else query.uncategorized = '1';
    router.push({ name: 'operations', query });
}

// ---------- solde (stats/balance, granularité mois) ----------
const balance = ref(null);
const loadingBalance = ref(true);
const balanceError = ref('');

async function loadBalance() {
    loadingBalance.value = true;
    balanceError.value = '';
    try {
        balance.value = await getBalance(scope.params, period.params, 'month');
    } catch (e) {
        balanceError.value = apiMessage(e, 'Graphique indisponible');
    } finally {
        loadingBalance.value = false;
    }
}

const balanceOption = computed(() => {
    const b = balance.value;
    if (!b) return {};
    const c = chartColors(theme.mode.value);
    const labels = b.dates.map((d) => formatDate(d));
    const series = [
        {
            name: 'Solde total',
            type: 'line',
            data: b.total,
            connectNulls: false,
            symbolSize: 8,
            lineStyle: { width: 2, color: slotColor(1, theme.mode.value) },
            itemStyle: { color: slotColor(1, theme.mode.value) },
        },
    ];
    (b.accounts ?? []).forEach((a, i) => {
        const color = i < 7 ? slotColor(i + 2, theme.mode.value) : c.others;
        series.push({
            name: a.account.alias,
            type: 'line',
            data: a.values,
            connectNulls: false,
            symbolSize: 6,
            lineStyle: { width: 2, type: 'dashed', color },
            itemStyle: { color },
        });
    });
    return {
        tooltip: baseTooltip(c),
        legend: baseLegend(c, { bottom: 26 }),
        grid: baseGrid({ bottom: 68, top: 8 }),
        xAxis: categoryAxis(c, labels),
        yAxis: valueAxis(c),
        series,
        dataZoom: [
            { type: 'inside' },
            {
                type: 'slider',
                bottom: 0,
                height: 14,
                borderColor: c.grid,
                backgroundColor: c.surface,
                fillerColor: c.grid,
                handleStyle: { color: c.inkSecondary },
                moveHandleStyle: { color: c.inkSecondary },
                labelFormatter: () => '',
            },
        ],
    };
});

const balanceEmpty = computed(() => !balance.value || !balance.value.dates?.length);

const balanceTableRows = computed(() => {
    const b = balance.value;
    if (!b) return [];
    return b.dates.map((d, i) => {
        const row = { date: formatDate(d), 'Solde total': b.total[i] };
        for (const a of b.accounts ?? []) row[a.account.alias] = a.values[i];
        return row;
    });
});

// ---------- chargement global ----------
function loadStats() {
    loadOverview();
    loadMonthly();
    loadCategories();
    loadBalance();
}

watch(() => [scope.params, period.params], loadStats, { deep: true });
onMounted(() => {
    loadSummary();
    loadStats();
});
</script>

<template>
    <div>
        <h1>Tableau de bord</h1>

        <PeriodFilter />

        <div v-if="loadingOverview && !overview" class="kpi-grid">
            <Card v-for="i in 4" :key="i"><Skeleton height="4.5rem" /></Card>
        </div>
        <div v-else-if="overviewError" class="kpi-grid">
            <Card>
                <template #content>
                    <p class="text-muted">{{ overviewError }}</p>
                    <Button label="Réessayer" icon="pi pi-refresh" size="small" outlined severity="secondary" @click="loadOverview" />
                </template>
            </Card>
        </div>
        <div v-else-if="overview" class="kpi-grid">
            <KpiTile
                v-for="k in kpis"
                :key="k.label"
                :label="k.label"
                :value="k.value"
                :subtext="k.subtext"
                :delta-text="k.delta?.text ?? ''"
                :delta-up="k.delta?.up ?? true"
                :favorable="k.favorable"
            />
        </div>

        <div class="charts-grid">
            <ChartCard
                class="span-2"
                title="Dépenses mensuelles par poste"
                subtitle="Barres empilées par catégorie racine, ligne des revenus sur le même axe — clic sur un segment pour voir les opérations du mois"
                aria-label="Barres empilées des dépenses mensuelles par poste de dépense, avec la ligne des revenus sur le même axe en euros"
                :loading="loadingMonthly"
                :error="monthlyError"
                :empty="monthlyEmpty"
                :height="360"
                @retry="loadMonthly"
            >
                <VChart :option="monthlyOption" aria-label="Dépenses mensuelles par poste et revenus" @click="onMonthlyClick" />
                <template #table>
                    <DataTable :value="monthlyTableRows" size="small" scrollable scroll-height="320px" :empty-message="'Aucune donnée'">
                        <Column field="month" header="Mois" />
                        <Column v-for="r in monthlyRoots" :key="r.name" :field="r.name" :header="r.name" class="text-right">
                            <template #body="{ data }">{{ formatMoney(data[r.name]) }}</template>
                        </Column>
                        <Column field="Dépenses" header="Dépenses" class="text-right">
                            <template #body="{ data }">{{ formatMoney(data['Dépenses']) }}</template>
                        </Column>
                        <Column field="Revenus" header="Revenus" class="text-right">
                            <template #body="{ data }">{{ formatMoney(data['Revenus']) }}</template>
                        </Column>
                    </DataTable>
                </template>
            </ChartCard>

            <ChartCard
                class="span-2"
                title="Répartition des dépenses"
                :subtitle="drillParent ? `Sous-catégories de « ${drillParentName} »` : 'Clic sur un poste pour voir ses sous-catégories'"
                aria-label="Barres horizontales des dépenses par poste, triées du plus grand au plus petit"
                :loading="loadingCats"
                :error="catsError"
                :empty="catsEmpty"
                :height="340"
                @retry="loadCategories"
            >
                <template #actions>
                    <Button
                        v-if="drillParent"
                        label="Toutes"
                        icon="pi pi-arrow-left"
                        text
                        size="small"
                        severity="secondary"
                        :aria-label="'Retour à toutes les catégories'"
                        @click="drillParent = null"
                    />
                    <span v-if="drillParent" class="text-muted breadcrumb">/ {{ drillParentName }}</span>
                </template>
                <VChart :option="catsOption" aria-label="Répartition des dépenses par poste" @click="onCatsClick" />
                <template #table>
                    <DataTable :value="cats ?? []" size="small" scrollable scroll-height="320px" :empty-message="'Aucune donnée'">
                        <Column header="Poste">
                            <template #body="{ data }">
                                <span class="cat-dot" :style="{ background: colorForCategory(data.category, theme.mode.value) }" aria-hidden="true" />
                                {{ data.category?.name ?? 'Non catégorisé' }}
                            </template>
                        </Column>
                        <Column field="total" header="Dépenses" class="text-right">
                            <template #body="{ data }">{{ formatMoney(data.total) }}</template>
                        </Column>
                        <Column field="share" header="Part" class="text-right">
                            <template #body="{ data }">{{ formatPercent(data.share) }}</template>
                        </Column>
                        <Column field="count" header="Nb opérations" class="text-right">
                            <template #body="{ data }">{{ data.count.toLocaleString('fr-FR') }}</template>
                        </Column>
                    </DataTable>
                </template>
            </ChartCard>

            <ChartCard
                class="span-2"
                title="Solde"
                subtitle="Solde de fin de mois reconstitué — zoom avec le curseur du bas"
                aria-label="Courbe du solde de fin de mois, total et par compte si plusieurs comptes dans le périmètre"
                :loading="loadingBalance"
                :error="balanceError"
                :empty="balanceEmpty"
                :height="320"
                @retry="loadBalance"
            >
                <VChart :option="balanceOption" aria-label="Évolution du solde" />
                <template #table>
                    <DataTable :value="balanceTableRows" size="small" scrollable scroll-height="300px" :empty-message="'Aucune donnée'">
                        <Column field="date" header="Date" />
                        <Column field="Solde total" header="Solde total" class="text-right">
                            <template #body="{ data }">
                                <span v-if="data['Solde total'] !== null">{{ formatMoney(data['Solde total']) }}</span>
                                <span v-else class="text-muted">—</span>
                            </template>
                        </Column>
                        <Column v-for="a in balance?.accounts ?? []" :key="a.account.id" :field="a.account.alias" :header="a.account.alias" class="text-right">
                            <template #body="{ data }">
                                <span v-if="data[a.account.alias] !== null && data[a.account.alias] !== undefined">{{ formatMoney(data[a.account.alias]) }}</span>
                                <span v-else class="text-muted">—</span>
                            </template>
                        </Column>
                    </DataTable>
                </template>
            </ChartCard>
        </div>

        <div v-if="loadingSummary" class="cards-grid section">
            <Card v-for="i in 4" :key="i"><Skeleton height="4rem" /></Card>
        </div>

        <template v-else-if="summary">
            <div class="cards-grid section">
                <Card>
                    <template #title>Opérations</template>
                    <template #content>
                        <p class="big-number">{{ summary.operationsCount.toLocaleString('fr-FR') }}</p>
                        <p class="text-muted">du {{ formatDate(summary.firstDate) }} au {{ formatDate(summary.lastDate) }}</p>
                    </template>
                </Card>
                <Card>
                    <template #title>Catégorisées</template>
                    <template #content>
                        <p class="big-number">{{ percentCategorized }} %</p>
                        <ProgressBar :value="percentCategorized" :show-value="false" />
                        <p class="text-muted">
                            {{ summary.categorizedCount.toLocaleString('fr-FR') }} catégorisées ·
                            <RouterLink :to="{ name: 'to-categorize' }">{{ summary.uncategorizedCount.toLocaleString('fr-FR') }} à catégoriser</RouterLink>
                        </p>
                    </template>
                </Card>
                <Card v-if="(summary.vehicleUnassignedCount ?? 0) > 0">
                    <template #title>Véhicules à affecter</template>
                    <template #content>
                        <p class="big-number">{{ summary.vehicleUnassignedCount.toLocaleString('fr-FR') }}</p>
                        <p>
                            <RouterLink :to="{ name: 'operations', query: { vehicleUnassigned: '1' } }">
                                opération(s) de catégorie véhicule sans véhicule
                            </RouterLink>
                        </p>
                    </template>
                </Card>
                <Card>
                    <template #title>Doublons probables</template>
                    <template #content>
                        <p class="big-number">{{ summary.probableDuplicatesCount }}</p>
                        <p><RouterLink :to="{ name: 'duplicates' }">Vérifier les doublons</RouterLink></p>
                    </template>
                </Card>
                <Card>
                    <template #title>Virements à vérifier</template>
                    <template #content>
                        <p class="big-number">{{ summary.pendingTransfersCount }}</p>
                        <p><RouterLink :to="{ name: 'transfers' }">Voir les virements internes</RouterLink></p>
                    </template>
                </Card>
                <Card>
                    <template #title>Relevés manquants</template>
                    <template #content>
                        <p class="big-number">{{ missingStatements.length }}</p>
                        <p v-if="!missingStatements.length" class="text-muted">
                            <i class="pi pi-check-circle missing-ok" aria-hidden="true" /> Série de relevés complète
                        </p>
                        <template v-else>
                            <ul class="missing-list" aria-label="Relevés mensuels manquants">
                                <li v-for="m in missingStatements.slice(0, MISSING_SHOWN)" :key="`${m.account.id}-${m.month}`">
                                    <span class="missing-month">{{ formatMonth(m.month) }}</span>
                                    <span v-if="missingShowAccount" class="text-muted"> · {{ m.account.alias }}</span>
                                    <Tag :value="MISSING_REASON[m.reason]?.label ?? m.reason" :severity="MISSING_REASON[m.reason]?.severity ?? 'secondary'" class="missing-tag" />
                                </li>
                            </ul>
                            <p v-if="missingStatements.length > MISSING_SHOWN" class="text-muted">
                                et {{ missingStatements.length - MISSING_SHOWN }} autre(s)
                            </p>
                            <p class="text-muted missing-hint">À déposer dans <code>data/inbox/</code>, puis ranger et importer.</p>
                        </template>
                    </template>
                </Card>
            </div>

            <Card class="section">
                <template #title>Dernier import</template>
                <template #content>
                    <p v-if="summary.lastImportAt">{{ formatDateTime(summary.lastImportAt) }}</p>
                    <p v-else class="text-muted">Aucun import pour le moment.</p>
                </template>
            </Card>

            <Card class="section">
                <template #title>Comptes</template>
                <template #content>
                    <DataTable :value="summary.accounts" size="small" :empty-message="'Aucun compte'">
                        <Column field="alias" header="Alias" />
                        <Column field="label" header="Libellé" />
                        <Column field="numberMasked" header="Numéro" />
                        <Column header="Solde" class="text-right">
                            <template #body="{ data }">
                                <MoneyAmount v-if="data.balance" :amount="data.balance.amount" />
                                <span v-else class="text-muted">—</span>
                                <span v-if="data.balance" class="text-muted solde-date">au {{ formatDate(data.balance.date) }}</span>
                            </template>
                        </Column>
                    </DataTable>
                </template>
            </Card>
        </template>
    </div>
</template>

<style scoped>
.kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(13rem, 1fr));
    gap: 1rem;
    margin-bottom: 1rem;
}
.charts-grid {
    display: grid;
    /* 2 colonnes (1 sur petit écran) : jamais de carte isolée sur un tiers de largeur. */
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 1rem;
}
.span-2 {
    grid-column: 1 / -1;
}
.cards-grid {
    display: grid;
    /* 12rem : les 5 cartes tiennent sur une ligne en 1280 px. */
    grid-template-columns: repeat(auto-fit, minmax(12rem, 1fr));
    gap: 1rem;
}
.section {
    margin-top: 1rem;
}
.big-number {
    font-size: 1.6rem;
    font-weight: 700;
    margin: 0 0 0.25rem;
}
.solde-date {
    margin-left: 0.4rem;
    font-size: 0.8rem;
}
.missing-ok {
    color: var(--p-green-500);
}
.missing-list {
    list-style: none;
    margin: 0 0 0.5rem;
    padding: 0;
}
.missing-list li {
    display: flex;
    align-items: center;
    gap: 0.25rem;
    padding: 0.15rem 0;
}
.missing-month::first-letter {
    text-transform: uppercase;
}
.missing-tag {
    margin-left: auto;
    font-size: 0.7rem;
}
.missing-hint {
    font-size: 0.8rem;
}
.breadcrumb {
    font-size: 0.85rem;
    white-space: nowrap;
}
@media (max-width: 900px) {
    .charts-grid {
        grid-template-columns: minmax(0, 1fr);
    }
}
</style>
