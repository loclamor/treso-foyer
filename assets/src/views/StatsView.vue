<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import Select from 'primevue/select';
import SelectButton from 'primevue/selectbutton';
import { useToast } from 'primevue/usetoast';
import { useScopeStore } from '@/stores/scope';
import { usePeriodStore } from '@/stores/period';
import { useVehiclesStore } from '@/stores/vehicles';
import {
    getBalance,
    getCalendar,
    getCategoriesStats,
    getCategoryTrend,
    getMerchantsStats,
    getSankey,
    getVehiclesStats,
} from '@/api/stats';
import { apiMessage } from '@/api/http';
import { escapeHtml, formatCompactEuro, formatDate, formatMoney, formatMonth, formatPercent, monthBounds } from '@/utils/format';
import {
    chartColors,
    useChartTheme,
    baseTooltip,
    baseLegend,
    baseGrid,
    valueAxis,
    categoryAxis,
    categoryAxisY,
    barItemStyle,
} from '@/charts/theme';
import { colorForCategory, slotColor, SEQUENTIAL_BLUE, DEFICIT_COLOR, withAlpha } from '@/charts/palette';
import { fr as frLocale } from '@/locale/fr';
import PeriodFilter from '@/components/PeriodFilter.vue';
import ChartCard from '@/components/ChartCard.vue';
import VChart from '@/components/VChart.vue';
import CategoryPicker from '@/components/CategoryPicker.vue';

const router = useRouter();
const scope = useScopeStore();
const period = usePeriodStore();
const vehiclesStore = useVehiclesStore();
const toast = useToast();
const theme = useChartTheme();

// Filtres supplémentaires de la vue : sens et comparaison (une seule ligne au-dessus des graphiques).
const direction = ref('expense');
const directionOptions = [
    { label: 'Dépenses', value: 'expense' },
    { label: 'Revenus', value: 'income' },
];
const compare = ref('previous');
const compareOptions = [
    { label: 'Période précédente', value: 'previous' },
    { label: 'Année précédente', value: 'year' },
];

// ---------- 1. comparaison par poste ----------
const compareCats = ref(null);
// Plages réellement comparées (en-têtes X-Comparable-* / X-Compare-* de l'API) :
// le mois en cours incomplet en est exclu (comparable = mois complets ou « mois à date »).
const compareMeta = ref({ comparableFrom: null, comparableTo: null, compareFrom: null, compareTo: null });
const loadingCompare = ref(true);
const compareError = ref('');

async function loadCompare() {
    loadingCompare.value = true;
    compareError.value = '';
    try {
        const res = await getCategoriesStats(scope.params, period.params, {
            direction: direction.value,
            compare: compare.value,
        });
        compareCats.value = res.rows;
        compareMeta.value = res.meta;
    } catch (e) {
        compareError.value = apiMessage(e, 'Graphique indisponible');
    } finally {
        loadingCompare.value = false;
    }
}

// Libellé de la comparaison avec les plages : « 01/11/2026 → 05/11/2026 vs 01/10/2026 → 05/10/2026 ».
const compareSubtitle = computed(() => {
    const meta = compareMeta.value;
    const sens = direction.value === 'expense' ? 'dépenses' : 'revenus';
    if (!meta.comparableFrom) {
        return `Période vs ${compare.value === 'year' ? 'année précédente' : 'période précédente'} — ${sens}`;
    }
    const compareLabel = compare.value === 'year' ? 'année précédente' : 'période précédente';
    return `${formatDate(meta.comparableFrom)} → ${formatDate(meta.comparableTo)} vs ${compareLabel} ${formatDate(meta.compareFrom)} → ${formatDate(meta.compareTo)} — ${sens}`;
});

const compareOption = computed(() => {
    const items = compareCats.value;
    if (!items) return {};
    const c = chartColors(theme.mode.value);
    const labels = items.map((it) => it.category?.name ?? 'Non catégorisé');
    return {
        tooltip: baseTooltip(c),
        legend: baseLegend(c),
        grid: baseGrid({ left: 8, right: 24 }),
        xAxis: valueAxis(c),
        yAxis: categoryAxisY(c, [...labels].reverse()),
        series: [
            {
                name: 'Période',
                type: 'bar',
                // comparableTotal (pas total) : la barre « Période » est sur la même plage que la comparaison.
                data: items.map((it) => it.comparableTotal ?? it.total).reverse(),
                barMaxWidth: 24,
                itemStyle: barItemStyle(slotColor(1, theme.mode.value), c.surface),
            },
            {
                name: compare.value === 'year' ? 'Année précédente' : 'Période précédente',
                type: 'bar',
                data: items.map((it) => it.compareTotal ?? 0).reverse(),
                barMaxWidth: 24,
                itemStyle: barItemStyle(c.others, c.surface),
            },
        ],
    };
});

const compareEmpty = computed(() => !compareCats.value || !compareCats.value.length);

const compareTableRows = computed(() =>
    (compareCats.value ?? []).map((it) => {
        // Écart calculé sur comparableTotal vs compareTotal (pas sur total, qui inclut le mois en cours).
        const current = it.comparableTotal ?? it.total;
        const diff = current - (it.compareTotal ?? 0);
        const pct = it.compareTotal ? (diff / Math.abs(it.compareTotal)) * 100 : null;
        return { name: it.category?.name ?? 'Non catégorisé', total: current, compareTotal: it.compareTotal ?? 0, diff, pct };
    }),
);

// ---------- 2. top commerçants ----------
const merchants = ref(null);
const prevMerchants = ref(null); // période précédente (case « Comparer » du filtre), null si inactive
const loadingMerchants = ref(true);
const merchantsError = ref('');

async function loadMerchants() {
    loadingMerchants.value = true;
    merchantsError.value = '';
    try {
        const cur = await getMerchantsStats(scope.params, period.params, { direction: direction.value, limit: 15 });
        // Période précédente : totaux des MÊMES commerçants (filtre keys ; absents ⇒ 0).
        const prevParams = period.previousParams;
        const keys = cur.map((it) => it.key);
        let prev = null;
        if (prevParams) {
            prev = keys.length
                ? await getMerchantsStats(scope.params, prevParams, { direction: direction.value, limit: keys.length, keys })
                : [];
        }
        merchants.value = cur;
        prevMerchants.value = prev;
    } catch (e) {
        merchantsError.value = apiMessage(e, 'Graphique indisponible');
    } finally {
        loadingMerchants.value = false;
    }
}

const merchantsOption = computed(() => {
    const items = merchants.value;
    if (!items) return {};
    const c = chartColors(theme.mode.value);
    const rows = items.map((it) => ({
        name: it.merchant ?? it.key,
        value: it.total,
        key: it.key,
        category: it.category,
        itemStyle: { color: colorForCategory(it.category, theme.mode.value), borderRadius: 4 },
    }));
    const labels = rows.map((r) => r.name).reverse();
    const curName = direction.value === 'expense' ? 'Dépenses' : 'Revenus';
    const series = [
        {
            name: curName,
            type: 'bar',
            data: rows.map((r) => ({ value: r.value, itemStyle: r.itemStyle, key: r.key })).reverse(),
            barMaxWidth: 22,
        },
    ];
    const hasPrev = prevMerchants.value !== null;
    const tooltip = { ...baseTooltip(c), trigger: hasPrev ? 'axis' : 'item', axisPointer: { type: 'shadow' } };
    if (hasPrev) {
        const prevByKey = new Map(prevMerchants.value.map((it) => [it.key, it.total]));
        series.push({
            name: PREV_NAME,
            type: 'bar',
            data: rows.map((r) => ({
                value: prevByKey.get(r.key) ?? 0,
                key: r.key,
                itemStyle: { color: withAlpha(colorForCategory(r.category, theme.mode.value), 0.35), borderRadius: 4 },
            })).reverse(),
            barMaxWidth: 22,
            barGap: '15%',
            emphasis: { disabled: true },
        });
        tooltip.formatter = (params) => comparisonTooltip(params, curName);
    }
    return {
        tooltip,
        grid: baseGrid({ left: 8, right: 48, top: 8, bottom: 8 }),
        xAxis: valueAxis(c),
        yAxis: categoryAxisY(c, labels),
        series,
    };
});

// Infobulle « axe » des barres comparées : valeur courante, précédente (avec la plage) et variation.
const PREV_NAME = 'Période précédente';
function comparisonTooltip(params, curName) {
    const list = Array.isArray(params) ? params : [params];
    const cur = list.find((p) => p.seriesName === curName);
    const prv = list.find((p) => p.seriesName === PREV_NAME);
    const curV = cur?.value ?? 0;
    const prvV = prv?.value ?? 0;
    const delta = prvV ? Math.round(((curV - prvV) / Math.abs(prvV)) * 100) : null;
    const r = period.previousRange;
    return [
        `<strong>${escapeHtml(list[0]?.axisValueLabel ?? list[0]?.name ?? '')}</strong>`,
        `${cur?.marker ?? ''} Période : <strong>${formatMoney(curV)}</strong>`,
        `${prv?.marker ?? ''} Précédente${r ? ` (${formatDate(r.from)} → ${formatDate(r.to)})` : ''} : ${formatMoney(prvV)}`,
        delta === null ? '' : `<span style="opacity:.7">Variation : ${delta >= 0 ? '+' : ''}${delta} %</span>`,
    ].filter(Boolean).join('<br/>');
}

const merchantsEmpty = computed(() => !merchants.value || !merchants.value.length);

function onMerchantClick(e) {
    const key = e.data?.key;
    if (!key || e.seriesName === PREV_NAME) return;
    router.push({
        name: 'operations',
        query: { merchant: key, direction: direction.value === 'expense' ? 'debit' : 'credit', from: period.range.from, to: period.range.to },
    });
}

// ---------- 3. sankey revenus → postes ----------
const sankey = ref(null);
const loadingSankey = ref(true);
const sankeyError = ref('');

async function loadSankey() {
    loadingSankey.value = true;
    sankeyError.value = '';
    try {
        sankey.value = await getSankey(scope.params, period.params);
    } catch (e) {
        sankeyError.value = apiMessage(e, 'Graphique indisponible');
    } finally {
        loadingSankey.value = false;
    }
}

const sankeyOption = computed(() => {
    const s = sankey.value;
    if (!s) return {};
    const c = chartColors(theme.mode.value);
    const mode = theme.mode.value;
    const nodes = s.nodes.map((n) => {
        let color;
        if (n.kind === 'expense') color = colorForCategory(n.category, mode); // couleur du poste
        else if (n.kind === 'savings') color = slotColor(1, mode); // épargne = bleu slot 1
        else if (n.kind === 'deficit') color = DEFICIT_COLOR; // déficit = statut critique
        else color = c.inkSecondary; // revenus + hub = gris neutre
        return { id: n.id, name: n.name, itemStyle: { color }, label: { color: c.ink, fontFamily: 'Inter, system-ui, sans-serif' } };
    });
    const links = s.links.map((l) => ({
        source: l.source,
        target: l.target,
        value: l.value,
        lineStyle: { color: 'source', opacity: 0.35, curveness: 0.5 },
    }));
    const nameById = new Map(nodes.map((n) => [n.id, n.name]));
    return {
        tooltip: {
            trigger: 'item',
            backgroundColor: c.surface,
            borderColor: c.grid,
            textStyle: { color: c.ink },
            formatter: (p) => (p.dataType === 'edge'
                ? `${nameById.get(p.data.source) ?? p.data.source} → ${nameById.get(p.data.target) ?? p.data.target} : <strong>${formatMoney(p.value)}</strong>`
                : `${nameById.get(p.data?.id) ?? p.name} : <strong>${formatMoney(p.value)}</strong>`),
        },
        series: [
            {
                type: 'sankey',
                layoutIterations: 32,
                data: nodes,
                links,
                emphasis: { focus: 'adjacency' },
                lineStyle: { curveness: 0.5 },
                // Les nœuds sont identifiés par id (noms non uniques) : afficher le nom.
                label: { color: c.ink, formatter: (p) => nameById.get(p.data?.id) ?? p.name },
                left: 8,
                right: 140,
            },
        ],
    };
});

const sankeyEmpty = computed(() => !sankey.value || !sankey.value.nodes?.length);

const sankeyTableRows = computed(() => {
    const s = sankey.value;
    if (!s) return [];
    const nameById = new Map(s.nodes.map((n) => [n.id, n.name]));
    return s.links.map((l) => ({
        source: nameById.get(l.source) ?? l.source,
        target: nameById.get(l.target) ?? l.target,
        value: l.value,
    }));
});

// ---------- 4. calendrier des dépenses ----------
const calendar = ref(null);
const loadingCalendar = ref(true);
const calendarError = ref('');

async function loadCalendar() {
    loadingCalendar.value = true;
    calendarError.value = '';
    try {
        calendar.value = await getCalendar(scope.params, period.params);
    } catch (e) {
        calendarError.value = apiMessage(e, 'Graphique indisponible');
    } finally {
        loadingCalendar.value = false;
    }
}

// Années couvertes par la période (sélecteur affiché si plusieurs).
const calendarYears = computed(() => {
    const from = Number(String(period.range.from).slice(0, 4));
    const to = Number(String(period.range.to).slice(0, 4));
    if (!from || !to) return [];
    const years = [];
    for (let y = from; y <= to; y++) years.push(y);
    return years;
});
const calendarYear = ref(null);
watch(
    calendarYears,
    (years) => {
        if (!years.length) return;
        if (!calendarYear.value || !years.includes(calendarYear.value)) {
            calendarYear.value = years[years.length - 1];
        }
    },
    { immediate: true },
);

const calendarOption = computed(() => {
    const days = calendar.value;
    const year = calendarYear.value;
    if (!days || !year) return {};
    const c = chartColors(theme.mode.value);
    const data = days
        .filter((d) => d.date.startsWith(`${year}-`))
        .map((d) => [d.date, d.expense]);
    const max = data.reduce((m, [, v]) => Math.max(m, v), 0);
    const pad = (n) => String(n).padStart(2, '0');
    return {
        tooltip: {
            trigger: 'item',
            backgroundColor: c.surface,
            borderColor: c.grid,
            textStyle: { color: c.ink },
            formatter: (p) => `${formatDate(p.data[0])} : <strong>${formatMoney(p.data[1])}</strong>`,
        },
        visualMap: {
            type: 'continuous',
            min: 0,
            max: max || 1,
            calculable: true,
            orient: 'horizontal',
            bottom: 0,
            left: 'center',
            inRange: { color: SEQUENTIAL_BLUE },
            textStyle: { color: c.muted },
            formatter: (v) => formatCompactEuro(v),
        },
        calendar: {
            top: 24,
            left: 36,
            right: 16,
            bottom: 56,
            range: [`${year}-01-01`, `${year}-12-31`],
            cellSize: 14,
            splitLine: { lineStyle: { color: c.grid, width: 1 } },
            itemStyle: { color: c.surface, borderColor: c.grid, borderWidth: 1 },
            dayLabel: { color: c.inkSecondary, firstDay: 1, nameMap: frLocale.dayNamesMin },
            monthLabel: { color: c.inkSecondary, nameMap: frLocale.monthNamesShort },
            yearLabel: { show: false },
        },
        series: [
            {
                type: 'heatmap',
                coordinateSystem: 'calendar',
                data,
            },
        ],
    };
});

const calendarEmpty = computed(() => !calendar.value || !calendar.value.length);

const calendarTableRows = computed(() =>
    (calendar.value ?? [])
        .filter((d) => !calendarYear.value || d.date.startsWith(`${calendarYear.value}-`))
        .map((d) => ({ date: d.date, expense: d.expense, count: d.count })),
);

// ---------- 5. évolution d'un poste ----------
const trendCategoryId = ref(null);
const trend = ref(null);
const prevTrend = ref(null); // période précédente alignée mois par mois, null si inactive
const loadingTrend = ref(false);
const trendError = ref('');

async function loadTrend() {
    if (!trendCategoryId.value) {
        trend.value = null;
        prevTrend.value = null;
        return;
    }
    loadingTrend.value = true;
    trendError.value = '';
    try {
        // Évolution mensuelle : période précédente jusqu'à la fin de son dernier mois.
        const prevParams = period.previousParams
            ? { from: period.previousParams.from, to: monthBounds(period.previousParams.to.slice(0, 7)).to }
            : null;
        const [cur, prev] = await Promise.all([
            getCategoryTrend(scope.params, period.params, trendCategoryId.value),
            prevParams ? getCategoryTrend(scope.params, prevParams, trendCategoryId.value) : Promise.resolve(null),
        ]);
        trend.value = cur;
        prevTrend.value = prev;
    } catch (e) {
        trendError.value = apiMessage(e, 'Graphique indisponible');
    } finally {
        loadingTrend.value = false;
    }
}

// Moyenne mobile 3 mois (même axe).
function movingAverage(values, window = 3) {
    return values.map((_, i) => {
        const slice = values.slice(Math.max(0, i - window + 1), i + 1).filter((v) => v != null);
        if (!slice.length) return null;
        return slice.reduce((a, b) => a + b, 0) / slice.length;
    });
}

const trendOption = computed(() => {
    const t = trend.value;
    if (!t) return {};
    const c = chartColors(theme.mode.value);
    const labels = t.months.map((mo) => formatMonth(mo));
    const color = colorForCategory(t.category, theme.mode.value);
    const p = prevTrend.value;
    const tooltip = baseTooltip(c);
    const extra = [];
    if (p) {
        // Mois i précédent dessiné au mois i courant ; l'infobulle donne son vrai mois.
        extra.push({
            id: 'prev',
            name: PREV_NAME,
            type: 'line',
            data: t.months.map((_, i) => p.values?.[i] ?? null),
            symbolSize: 5,
            lineStyle: { width: 2, color: withAlpha(color, 0.4) },
            itemStyle: { color: withAlpha(color, 0.4) },
        });
        tooltip.formatter = (params) => {
            const list = Array.isArray(params) ? params : [params];
            const i = list[0]?.dataIndex ?? 0;
            return [
                `<strong>${escapeHtml(list[0]?.axisValueLabel ?? '')}</strong>`,
                ...list.map((q) => {
                    const label = q.seriesId === 'prev'
                        ? `${PREV_NAME}${p.months?.[i] ? ` (${formatMonth(p.months[i])})` : ''}`
                        : escapeHtml(q.seriesName);
                    return `${q.marker} ${label} : <strong>${q.value == null ? '—' : formatMoney(q.value)}</strong>`;
                }),
            ].join('<br/>');
        };
    }
    return {
        tooltip,
        legend: baseLegend(c),
        grid: baseGrid(),
        xAxis: categoryAxis(c, labels),
        yAxis: valueAxis(c),
        series: [
            {
                name: t.category?.name ?? 'Poste',
                type: 'line',
                data: t.values,
                symbolSize: 8,
                lineStyle: { width: 2, color },
                itemStyle: { color },
            },
            {
                name: 'Moyenne mobile 3 mois',
                type: 'line',
                data: movingAverage(t.values),
                symbolSize: 0,
                lineStyle: { width: 2, type: 'dashed', color: c.muted },
                itemStyle: { color: c.muted },
            },
            ...extra,
        ],
    };
});

const trendEmpty = computed(() => !trendCategoryId.value || !trend.value || !trend.value.months?.length);

// ---------- 6. solde quotidien ----------
const balanceDay = ref(null);
const loadingBalanceDay = ref(true);
const balanceDayError = ref('');

async function loadBalanceDay() {
    loadingBalanceDay.value = true;
    balanceDayError.value = '';
    try {
        balanceDay.value = await getBalance(scope.params, period.params, 'day');
    } catch (e) {
        balanceDayError.value = apiMessage(e, 'Graphique indisponible');
    } finally {
        loadingBalanceDay.value = false;
    }
}

const balanceDayOption = computed(() => {
    const b = balanceDay.value;
    if (!b) return {};
    const c = chartColors(theme.mode.value);
    const labels = b.dates.map((d) => formatDate(d));
    return {
        tooltip: baseTooltip(c),
        grid: baseGrid({ bottom: 68, top: 8 }),
        xAxis: categoryAxis(c, labels, { maxTicks: 8 }),
        yAxis: valueAxis(c),
        series: [
            {
                name: 'Solde quotidien',
                type: 'line',
                data: b.total,
                connectNulls: false,
                symbolSize: 6,
                areaStyle: { opacity: 0.08, color: slotColor(1, theme.mode.value) },
                lineStyle: { width: 2, color: slotColor(1, theme.mode.value) },
                itemStyle: { color: slotColor(1, theme.mode.value) },
            },
        ],
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

const balanceDayEmpty = computed(() => !balanceDay.value || !balanceDay.value.dates?.length);

// ---------- 7. véhicules (lot 5) ----------
const vehiclesStats = ref(null);
const loadingVehicles = ref(false);
const vehiclesError = ref('');

// Carte affichée seulement si au moins un véhicule existe dans le foyer.
async function loadVehicles() {
    if (!vehiclesStore.list.length) {
        vehiclesStats.value = null;
        return;
    }
    loadingVehicles.value = true;
    vehiclesError.value = '';
    try {
        vehiclesStats.value = await getVehiclesStats(scope.params, period.params);
    } catch (e) {
        vehiclesError.value = apiMessage(e, 'Graphique indisponible');
    } finally {
        loadingVehicles.value = false;
    }
}

const vehicleRows = computed(() =>
    (vehiclesStats.value?.vehicles ?? []).map((v) => ({
        vehicle: v.vehicle,
        total: v.total,
        count: v.count,
        monthlyAverage: v.monthlyAverage,
        km: v.distance ? Math.round(v.distance.km) : null,
        costPerKm: v.costPerKm,
    })),
);

const unassignedCount = computed(() => vehiclesStats.value?.unassigned?.count ?? 0);

const vehiclesOption = computed(() => {
    const rows = vehicleRows.value;
    if (!rows.length) return {};
    const c = chartColors(theme.mode.value);
    return {
        tooltip: {
            ...baseTooltip(c),
            formatter: (params) => {
                const p = Array.isArray(params) ? params[0] : params;
                const row = rows[p.dataIndex];
                const cost = row?.costPerKm != null ? `<br/>Coût au km : <strong>${formatMoney(row.costPerKm)}/km</strong>` : '';
                return `${p.name}<br/>Total : <strong>${formatMoney(p.value)}</strong> (${row?.count ?? 0} op.)${cost}`;
            },
        },
        grid: baseGrid({ left: 8, right: 16, top: 8, bottom: 8 }),
        xAxis: categoryAxis(c, rows.map((r) => r.vehicle.name)),
        yAxis: valueAxis(c),
        series: [
            {
                name: 'Dépenses',
                type: 'bar',
                data: rows.map((r) => ({ value: r.total, vehicleId: r.vehicle.id })),
                barMaxWidth: 40,
                itemStyle: barItemStyle(slotColor(1, theme.mode.value), c.surface),
            },
        ],
    };
});

const vehiclesEmpty = computed(() => !vehiclesStats.value || !vehiclesStats.value.vehicles?.length);

// Clic sur une barre ⇒ fiche du véhicule.
function onVehicleClick(e) {
    const id = e.data?.vehicleId;
    if (id) router.push({ name: 'vehicle', params: { id } });
}

// ---------- chargement global ----------
function loadAll() {
    loadCompare();
    loadMerchants();
    loadSankey();
    loadCalendar();
    loadTrend();
    loadBalanceDay();
    loadVehicles();
}

watch(() => [scope.params, period.params], loadAll, { deep: true });
watch([direction, compare], () => {
    loadCompare();
    loadMerchants();
});
watch(trendCategoryId, loadTrend);
// Case « Comparer à la période précédente » : graphes comparés seulement.
watch(() => period.compareActive, () => {
    loadMerchants();
    loadTrend();
});
onMounted(async () => {
    if (!vehiclesStore.loaded) await vehiclesStore.refresh().catch(() => {});
    loadAll();
});

// Toast en cas d'erreur silencieuse de fond (les cartes affichent déjà leur état d'erreur).
watch([compareError, merchantsError, sankeyError, calendarError, balanceDayError, vehiclesError], (errs) => {
    const first = errs.find(Boolean);
    if (first) toast.add({ severity: 'error', summary: 'Statistiques indisponibles', detail: first, life: 6000 });
});
</script>

<template>
    <div>
        <h1>Statistiques</h1>

        <div class="filter-row">
            <PeriodFilter />
            <SelectButton v-model="direction" :options="directionOptions" option-label="label" option-value="value" aria-label="Sens" />
            <Select
                v-model="compare"
                :options="compareOptions"
                option-label="label"
                option-value="value"
                aria-label="Comparaison"
                class="compare-select"
            />
        </div>

        <div class="charts-grid">
            <ChartCard
                class="span-2"
                title="Comparaison par poste"
                :subtitle="compareSubtitle"
                aria-label="Barres groupées comparant chaque poste de dépense entre la période comparable et la période de comparaison"
                :loading="loadingCompare"
                :error="compareError"
                :empty="compareEmpty"
                :height="340"
                @retry="loadCompare"
            >
                <VChart :option="compareOption" aria-label="Comparaison par poste, période vs comparaison" />
                <template #table>
                    <DataTable :value="compareTableRows" size="small" scrollable scroll-height="320px" :empty-message="'Aucune donnée'">
                        <Column field="name" header="Poste" />
                        <Column field="total" :header="compareMeta.comparableFrom ? `Période (${formatDate(compareMeta.comparableFrom)} → ${formatDate(compareMeta.comparableTo)})` : 'Période'" class="text-right">
                            <template #body="{ data }">{{ formatMoney(data.total) }}</template>
                        </Column>
                        <Column field="compareTotal" :header="(compare === 'year' ? 'Année précédente' : 'Période précédente') + (compareMeta.compareFrom ? ` (${formatDate(compareMeta.compareFrom)} → ${formatDate(compareMeta.compareTo)})` : '')" class="text-right">
                            <template #body="{ data }">{{ formatMoney(data.compareTotal) }}</template>
                        </Column>
                        <Column field="diff" header="Écart" class="text-right">
                            <template #body="{ data }">{{ formatMoney(data.diff) }}</template>
                        </Column>
                        <Column field="pct" header="Écart %" class="text-right">
                            <template #body="{ data }">{{ data.pct === null ? '—' : formatPercent(data.pct / 100, 0) }}</template>
                        </Column>
                    </DataTable>
                </template>
            </ChartCard>

            <ChartCard
                v-if="vehiclesStore.list.length"
                title="Véhicules"
                :subtitle="unassignedCount
                    ? `Dépenses totales par véhicule sur la période — ${unassignedCount.toLocaleString('fr-FR')} opération(s) de catégorie véhicule non affectée(s)`
                    : 'Dépenses totales par véhicule sur la période — clic sur une barre pour ouvrir la fiche du véhicule'"
                aria-label="Barres du total des dépenses par véhicule sur la période, coût au km en infobulle"
                :loading="loadingVehicles"
                :error="vehiclesError"
                :empty="vehiclesEmpty"
                empty-text="Aucune dépense véhicule sur la période."
                :height="300"
                @retry="loadVehicles"
            >
                <VChart :option="vehiclesOption" aria-label="Dépenses par véhicule" @click="onVehicleClick" />
                <template #table>
                    <DataTable :value="vehicleRows" size="small" scrollable scroll-height="280px" :empty-message="'Aucune donnée'">
                        <Column header="Véhicule">
                            <template #body="{ data }">
                                <RouterLink :to="{ name: 'vehicle', params: { id: data.vehicle.id } }">{{ data.vehicle.name }}</RouterLink>
                            </template>
                        </Column>
                        <Column field="total" header="Total" class="text-right">
                            <template #body="{ data }">{{ formatMoney(data.total) }}</template>
                        </Column>
                        <Column field="monthlyAverage" header="Moy. mensuelle" class="text-right">
                            <template #body="{ data }">{{ formatMoney(data.monthlyAverage) }}</template>
                        </Column>
                        <Column field="km" header="Km" class="text-right">
                            <template #body="{ data }">{{ data.km != null ? data.km.toLocaleString('fr-FR') : '—' }}</template>
                        </Column>
                        <Column field="costPerKm" header="Coût/km" class="text-right">
                            <template #body="{ data }">{{ data.costPerKm != null ? `${formatMoney(data.costPerKm)}/km` : '—' }}</template>
                        </Column>
                        <Column field="count" header="Nb opérations" class="text-right">
                            <template #body="{ data }">{{ data.count.toLocaleString('fr-FR') }}</template>
                        </Column>
                    </DataTable>
                </template>
            </ChartCard>

            <ChartCard
                title="Top commerçants"
                subtitle="Couleur = poste majoritaire du commerçant — clic pour voir ses opérations"
                aria-label="Barres horizontales des 15 plus gros commerçants, colorées selon leur poste majoritaire"
                :loading="loadingMerchants"
                :error="merchantsError"
                :empty="merchantsEmpty"
                :height="420"
                @retry="loadMerchants"
            >
                <VChart :option="merchantsOption" aria-label="Top commerçants" @click="onMerchantClick" />
                <template #table>
                    <DataTable :value="merchants ?? []" size="small" scrollable scroll-height="380px" :empty-message="'Aucune donnée'">
                        <Column header="Commerçant">
                            <template #body="{ data }">
                                <span class="cat-dot" :style="{ background: colorForCategory(data.category, theme.mode.value) }" aria-hidden="true" />
                                {{ data.merchant ?? data.key }}
                            </template>
                        </Column>
                        <Column field="total" :header="direction === 'expense' ? 'Dépenses' : 'Revenus'" class="text-right">
                            <template #body="{ data }">{{ formatMoney(data.total) }}</template>
                        </Column>
                        <Column field="count" header="Nb opérations" class="text-right">
                            <template #body="{ data }">{{ data.count.toLocaleString('fr-FR') }}</template>
                        </Column>
                    </DataTable>
                </template>
            </ChartCard>

            <ChartCard
                title="Flux revenus → postes"
                subtitle="Revenus (gris) → budget → postes de dépense (couleur du poste) ; épargne en bleu, déficit en rouge"
                aria-label="Diagramme de Sankey des flux des revenus vers les postes de dépense, l'épargne ou le déficit"
                :loading="loadingSankey"
                :error="sankeyError"
                :empty="sankeyEmpty"
                :height="420"
                @retry="loadSankey"
            >
                <VChart :option="sankeyOption" aria-label="Sankey revenus vers postes" />
                <template #table>
                    <DataTable :value="sankeyTableRows" size="small" scrollable scroll-height="380px" :empty-message="'Aucune donnée'">
                        <Column field="source" header="De" />
                        <Column field="target" header="Vers" />
                        <Column field="value" header="Montant" class="text-right">
                            <template #body="{ data }">{{ formatMoney(data.value) }}</template>
                        </Column>
                    </DataTable>
                </template>
            </ChartCard>

            <ChartCard
                class="span-2"
                title="Calendrier des dépenses"
                subtitle="Une journée = une case, montant en dégradé de bleu"
                aria-label="Calendrier thermique des dépenses quotidiennes sur l'année sélectionnée"
                :loading="loadingCalendar"
                :error="calendarError"
                :empty="calendarEmpty"
                :height="300"
                @retry="loadCalendar"
            >
                <template #actions>
                    <Select
                        v-if="calendarYears.length > 1"
                        v-model="calendarYear"
                        :options="calendarYears"
                        aria-label="Année du calendrier"
                        class="year-select"
                    />
                </template>
                <VChart :option="calendarOption" aria-label="Calendrier des dépenses" />
                <template #table>
                    <DataTable :value="calendarTableRows" size="small" scrollable scroll-height="280px" :empty-message="'Aucune donnée'">
                        <Column field="date" header="Date">
                            <template #body="{ data }">{{ formatDate(data.date) }}</template>
                        </Column>
                        <Column field="expense" header="Dépenses" class="text-right">
                            <template #body="{ data }">{{ formatMoney(data.expense) }}</template>
                        </Column>
                        <Column field="count" header="Nb opérations" class="text-right">
                            <template #body="{ data }">{{ data.count.toLocaleString('fr-FR') }}</template>
                        </Column>
                    </DataTable>
                </template>
            </ChartCard>

            <ChartCard
                class="span-2"
                title="Évolution d'un poste"
                subtitle="Courbe mensuelle et moyenne mobile 3 mois"
                aria-label="Courbe mensuelle des dépenses du poste choisi avec sa moyenne mobile sur 3 mois"
                :loading="loadingTrend"
                :error="trendError"
                :empty="trendEmpty"
                :empty-text="trendCategoryId ? 'Aucune donnée sur la période.' : 'Choisissez un poste pour afficher son évolution.'"
                :height="320"
                @retry="loadTrend"
            >
                <template #actions>
                    <CategoryPicker v-model="trendCategoryId" placeholder="Choisir un poste" class="trend-picker" />
                </template>
                <VChart :option="trendOption" aria-label="Évolution mensuelle du poste" />
                <template #table>
                    <DataTable :value="trend?.months.map((mo, i) => ({ month: mo, value: trend.values[i], count: trend.counts[i] })) ?? []" size="small" scrollable scroll-height="300px" :empty-message="'Choisissez un poste'">
                        <Column header="Mois">
                            <template #body="{ data }">{{ formatMonth(data.month) }}</template>
                        </Column>
                        <Column field="value" header="Montant" class="text-right">
                            <template #body="{ data }">{{ formatMoney(data.value) }}</template>
                        </Column>
                        <Column field="count" header="Nb opérations" class="text-right">
                            <template #body="{ data }">{{ data.count?.toLocaleString('fr-FR') }}</template>
                        </Column>
                    </DataTable>
                </template>
            </ChartCard>

            <ChartCard
                class="span-2"
                title="Solde quotidien"
                subtitle="Solde reconstitué jour par jour — zoom avec le curseur du bas"
                aria-label="Courbe du solde quotidien avec zoom"
                :loading="loadingBalanceDay"
                :error="balanceDayError"
                :empty="balanceDayEmpty"
                :height="320"
                @retry="loadBalanceDay"
            >
                <VChart :option="balanceDayOption" aria-label="Solde quotidien" />
                <template #table>
                    <DataTable
                        :value="balanceDay?.dates.map((d, i) => ({ date: d, total: balanceDay.total[i] })) ?? []"
                        size="small"
                        scrollable
                        scroll-height="300px"
                        :empty-message="'Aucune donnée'"
                    >
                        <Column field="date" header="Date">
                            <template #body="{ data }">{{ formatDate(data.date) }}</template>
                        </Column>
                        <Column field="total" header="Solde" class="text-right">
                            <template #body="{ data }">
                                <span v-if="data.total !== null">{{ formatMoney(data.total) }}</span>
                                <span v-else class="text-muted">—</span>
                            </template>
                        </Column>
                    </DataTable>
                </template>
            </ChartCard>
        </div>
    </div>
</template>

<style scoped>
.filter-row {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    flex-wrap: wrap;
    margin-bottom: 1rem;
}
.compare-select {
    min-width: 12rem;
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
.year-select {
    max-width: 7rem;
}
.trend-picker {
    max-width: 16rem;
}
@media (max-width: 900px) {
    .charts-grid {
        grid-template-columns: minmax(0, 1fr);
    }
}
</style>
