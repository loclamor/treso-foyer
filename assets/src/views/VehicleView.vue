<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import Button from 'primevue/button';
import Dialog from 'primevue/dialog';
import InputText from 'primevue/inputtext';
import InputNumber from 'primevue/inputnumber';
import DatePicker from 'primevue/datepicker';
import Tag from 'primevue/tag';
import Select from 'primevue/select';
import SelectButton from 'primevue/selectbutton';
import { useToast } from 'primevue/usetoast';
import { useConfirm } from 'primevue/useconfirm';
import { useScopeStore } from '@/stores/scope';
import { usePeriodStore } from '@/stores/period';
import { useVehiclesStore } from '@/stores/vehicles';
import { listOperations } from '@/api/operations';
import { getVehiclesStats } from '@/api/stats';
import {
    createAdblueFill,
    createOdometerReading,
    deleteAdblueFill,
    deleteOdometerReading,
    getVehicleAdblue,
    getVehicleFuel,
    listAdblueCandidates,
    listOdometerReadings,
    updateAdblueFill,
    updateOdometerReading,
} from '@/api/vehicles';
import { apiMessage } from '@/api/http';
import { dateToIso, escapeHtml, formatConsumption, formatDate, formatLiters, formatMoney, formatPercent, formatPricePerLiter, isoToDate, percentChange } from '@/utils/format';
import { latestGuard } from '@/utils/latest';
import { VEHICLE_ENERGY_LABELS, VEHICLE_TYPE_ICONS, VEHICLE_TYPE_LABELS } from '@/utils/vehicles';
import { CHART_FONT, baseGrid, baseLegend, baseTooltip, categoryAxis, chartColors, useChartTheme, valueAxis } from '@/charts/theme';
import { CATEGORICAL, OTHERS_COLOR } from '@/charts/palette';
import PeriodFilter from '@/components/PeriodFilter.vue';
import KpiTile from '@/components/KpiTile.vue';
import ChartCard from '@/components/ChartCard.vue';
import VChart from '@/components/VChart.vue';
import MoneyAmount from '@/components/MoneyAmount.vue';
import CategoryTag from '@/components/CategoryTag.vue';
import VehicleTag from '@/components/VehicleTag.vue';

const route = useRoute();
const scope = useScopeStore();
const period = usePeriodStore();
const vehiclesStore = useVehiclesStore();
const toast = useToast();
const confirm = useConfirm();
const theme = useChartTheme();

const vehicleId = Number(route.params.id);

// Pas de GET /api/vehicles/{id} : le véhicule complet vient de la liste (archivés inclus).
const vehicle = computed(() => vehiclesStore.list.find((v) => v.id === vehicleId));
const notFound = ref(false);

// ---------- stats du véhicule sur la période (KPIs + donut) ----------
const vehicleStats = ref(null); // entrée {vehicle, total, usageTotal, financingTotal, count, monthlyAverage, usageMonthlyAverage, byCategory, distance, costPerKm, costPerKmTotal}
const prevVehicleStats = ref(null); // même entrée sur la période précédente (case « Comparer »), null si inactive
const loadingStats = ref(false);
// L'API ne liste que les véhicules ayant une dépense sur la période : repli à zéro.
const EMPTY_STATS = { total: 0, usageTotal: 0, financingTotal: 0, count: 0, monthlyAverage: 0, usageMonthlyAverage: 0, byCategory: [], distance: null, costPerKm: null, costPerKmTotal: null };
const statsError = ref('');

const statsGuard = latestGuard();
async function loadStats() {
    const isLatest = statsGuard();
    if (!vehicle.value) return;
    loadingStats.value = true;
    statsError.value = '';
    try {
        const prevParams = period.previousParams;
        const [res, prev] = await Promise.all([
            getVehiclesStats(scope.params, period.params),
            prevParams ? getVehiclesStats(scope.params, prevParams) : Promise.resolve(null),
        ]);
        if (!isLatest()) return;
        vehicleStats.value = res.vehicles.find((v) => v.vehicle.id === vehicleId) ?? EMPTY_STATS;
        prevVehicleStats.value = prev ? prev.vehicles.find((v) => v.vehicle.id === vehicleId) ?? EMPTY_STATS : null;
    } catch (e) {
        if (isLatest()) statsError.value = apiMessage(e, 'Statistiques indisponibles');
    } finally {
        if (isLatest()) loadingStats.value = false;
    }
}

// Écart vs période précédente pour KpiTile : {deltaText, deltaUp, favorable} ou {} (pas de comparaison / base nulle).
// lowerIsBetter : true = baisse favorable (dépenses, coûts, consommation), false = hausse favorable, null = neutre.
function delta(current, previous, lowerIsBetter = true) {
    if (current == null || previous == null || previous === 0) return {};
    const pct = Math.round(percentChange(current, previous));
    const up = current >= previous;
    return {
        deltaText: `${pct >= 0 ? '+' : ''}${pct} % vs période précédente`,
        deltaUp: up,
        favorable: lowerIsBetter === null || pct === 0 ? null : lowerIsBetter ? !up : up,
    };
}

const kpis = computed(() => {
    const vs = vehicleStats.value;
    if (!vs) return [];
    const pv = prevVehicleStats.value; // null sans comparaison
    const km = vs.distance ? Math.round(vs.distance.km).toLocaleString('fr-FR') + ' km' : '—';
    // Financement (crédit…, ADR 0011) : rattaché au véhicule mais hors coût d'usage.
    const hasFinancing = (vs.financingTotal ?? 0) > 0;
    const ops = `${vs.count.toLocaleString('fr-FR')} opération(s) sur la période`;
    const tiles = [
        {
            label: 'Total dépensé',
            value: formatMoney(vs.total),
            subtext: hasFinancing ? `dont ${formatMoney(vs.financingTotal)} de financement · ${ops}` : ops,
            ...(pv ? delta(vs.total, pv.total) : {}),
        },
        {
            label: 'Moyenne mensuelle',
            value: formatMoney(vs.monthlyAverage),
            subtext: hasFinancing ? `usage seul : ${formatMoney(vs.usageMonthlyAverage)}/mois` : '',
            ...(pv ? delta(vs.monthlyAverage, pv.monthlyAverage) : {}),
        },
        {
            label: 'Km parcourus',
            value: km,
            subtext: vs.distance ? `du ${formatDate(vs.distance.coveredFrom)} au ${formatDate(vs.distance.coveredTo)}` : 'moins de 2 relevés',
            ...(pv ? delta(vs.distance?.km, pv.distance?.km, null) : {}),
        },
        {
            label: hasFinancing ? "Coût d'usage au km" : 'Coût au km',
            value: vs.costPerKm != null ? `${formatMoney(vs.costPerKm)}/km` : '—',
            subtext: hasFinancing ? 'hors financement · période couverte ÷ km' : 'dépenses de la période couverte ÷ km',
            ...(pv ? delta(vs.costPerKm, pv.costPerKm) : {}),
        },
    ];
    if (hasFinancing) {
        tiles.push({
            label: 'Coût total au km',
            value: vs.costPerKmTotal != null ? `${formatMoney(vs.costPerKmTotal)}/km` : '—',
            subtext: 'usage + financement · période couverte ÷ km',
            ...(pv ? delta(vs.costPerKmTotal, pv.costPerKmTotal) : {}),
        });
    }
    return tiles;
});

// ---------- donut par catégorie ----------
// Les sous-catégories n'ont pas d'emplacement de couleur (seules les racines en ont) : toutes
// prendraient la couleur de « Transport ». Couleur propre à chaque catégorie, stable d'une
// période à l'autre (rang par id de catégorie), « Non catégorisé » en gris.
function donutColor(category) {
    const mode = theme.mode.value;
    if (!category) return OTHERS_COLOR[mode] ?? OTHERS_COLOR.light;
    const palette = CATEGORICAL[mode] ?? CATEGORICAL.light;
    const ids = [...new Set((vehicleStats.value?.byCategory ?? []).filter((b) => b.category).map((b) => b.category.id))].sort((a, b) => a - b);
    return palette[Math.max(0, ids.indexOf(category.id)) % palette.length];
}

const donutOption = computed(() => {
    const vs = vehicleStats.value;
    if (!vs || !vs.byCategory?.length) return {};
    const c = chartColors(theme.mode.value);
    const total = vs.byCategory.reduce((s, b) => s + (b.total ?? 0), 0);
    const data = vs.byCategory.map((b) => ({
        name: b.category?.name ?? 'Non catégorisé',
        value: b.total,
        itemStyle: { color: donutColor(b.category), borderRadius: 4, borderColor: c.surface, borderWidth: 2 },
    }));
    return {
        tooltip: {
            trigger: 'item',
            backgroundColor: c.surface,
            borderColor: c.grid,
            borderWidth: 1,
            textStyle: { color: c.ink, fontFamily: CHART_FONT, fontSize: 12 },
            formatter: (p) => `${escapeHtml(p.name)} : <strong>${formatMoney(p.value)}</strong>${total ? ` (${formatPercent(p.value / total, 1)})` : ''}`,
        },
        legend: baseLegend(c),
        series: [
            {
                name: 'Dépenses',
                type: 'pie',
                radius: ['42%', '70%'],
                center: ['50%', '46%'],
                label: { show: false },
                data,
            },
        ],
    };
});

const donutEmpty = computed(() => !vehicleStats.value || !vehicleStats.value.byCategory?.length);
const donutRows = computed(() => (vehicleStats.value?.byCategory ?? []).map((b) => ({ category: b.category, total: b.total })));

// L'API ne fournit pas de tendance mensuelle par véhicule : pas de graphique inventé.

// ---------- pleins de carburant (ADR 0015) sur la période ----------
const fuelData = ref(null); // {fills: [...], summary: {count, volumeMl, amount, avgPricePerLiter, consumption, distanceKm}}
const prevFuelSummary = ref(null); // synthèse de la période précédente (case « Comparer »), null si inactive
const loadingFuel = ref(false);
const fuelError = ref('');

const fuelGuard = latestGuard();
async function loadFuel() {
    const isLatest = fuelGuard();
    if (!vehicle.value) return;
    loadingFuel.value = true;
    fuelError.value = '';
    try {
        const prevParams = period.previousParams;
        const [cur, prev] = await Promise.all([
            getVehicleFuel(vehicleId, period.params),
            prevParams ? getVehicleFuel(vehicleId, prevParams) : Promise.resolve(null),
        ]);
        if (!isLatest()) return;
        fuelData.value = cur;
        prevFuelSummary.value = prev?.summary ?? null;
    } catch (e) {
        if (isLatest()) fuelError.value = apiMessage(e, 'Pleins indisponibles');
    } finally {
        if (isLatest()) loadingFuel.value = false;
    }
}

const fuelKpis = computed(() => {
    const s = fuelData.value?.summary;
    if (!s) return [];
    const ps = prevFuelSummary.value;
    const dist = s.distanceKm != null ? `${s.distanceKm.toLocaleString('fr-FR')} km` : null;
    return [
        {
            label: 'Consommation moyenne',
            value: formatConsumption(s.consumption),
            subtext: dist ? `sur ${dist} entre pleins complets` : 'pleins complets avec kilométrage requis',
            ...(ps ? delta(s.consumption, ps.consumption) : {}),
        },
        { label: 'Prix moyen au litre', value: formatPricePerLiter(s.avgPricePerLiter), subtext: '', ...(ps ? delta(s.avgPricePerLiter, ps.avgPricePerLiter) : {}) },
        { label: 'Volume total', value: formatLiters(s.volumeMl), subtext: '', ...(ps ? delta(s.volumeMl, ps.volumeMl, null) : {}) },
        { label: 'Nombre de pleins', value: String(s.count), subtext: '', ...(ps ? delta(s.count, ps.count, null) : {}) },
    ];
});

// Prix au litre par plein (€/L) + consommation par segment (L/100 km, axe Y secondaire).
const fuelChartOption = computed(() => {
    const fillsChrono = [...(fuelData.value?.fills ?? [])].reverse(); // fills reçus date desc
    if (!fillsChrono.length) return {};
    const c = chartColors(theme.mode.value);
    const palette = CATEGORICAL[theme.mode.value] ?? CATEGORICAL.light;
    const labels = fillsChrono.map((f) => formatDate(f.date));
    const hasConsumption = fillsChrono.some((f) => f.consumption !== null && f.consumption !== undefined);
    const series = [
        {
            name: 'Prix au litre (€/L)',
            type: 'line',
            data: fillsChrono.map((f) => f.pricePerLiter),
            yAxisIndex: 0,
            smooth: true,
            symbol: 'circle',
            symbolSize: 6,
            lineStyle: { width: 2 },
            itemStyle: { color: palette[0] },
        },
    ];
    if (hasConsumption) {
        series.push({
            name: 'Consommation (L/100 km)',
            type: 'line',
            data: fillsChrono.map((f) => f.consumption),
            yAxisIndex: 1,
            smooth: true,
            symbol: 'diamond',
            symbolSize: 6,
            lineStyle: { width: 2, type: 'dashed' },
            itemStyle: { color: palette[1] },
        });
    }
    return {
        tooltip: {
            ...baseTooltip(c),
            formatter: (params) => {
                const arr = Array.isArray(params) ? params : [params];
                const head = arr[0]?.axisValueLabel ?? arr[0]?.name ?? '';
                const lines = arr.map(
                    (p) => `${p.marker} ${escapeHtml(p.seriesName)} : <strong>${p.seriesIndex === 0 ? formatPricePerLiter(p.value) : formatConsumption(p.value)}</strong>`,
                );
                return [escapeHtml(head), ...lines].join('<br/>');
            },
        },
        legend: baseLegend(c),
        grid: baseGrid({ right: hasConsumption ? 56 : 16 }),
        xAxis: categoryAxis(c, labels),
        yAxis: [
            valueAxis(c, { formatter: (v) => `${Number(v).toFixed(3).replace('.', ',')} €/L` }),
            ...(hasConsumption
                ? [valueAxis(c, { formatter: (v) => `${Number(v).toFixed(2).replace('.', ',')} L/100` })]
                : []),
        ],
        series,
    };
});

// ---------- dernières opérations du véhicule ----------
const ops = ref([]);
const loadingOps = ref(false);

const opsGuard = latestGuard();
async function loadOps() {
    const isLatest = opsGuard();
    if (!vehicle.value) return;
    loadingOps.value = true;
    try {
        const res = await listOperations({ ...scope.params, vehicleId, page: 1, perPage: 10, sort: 'date', order: 'desc' });
        if (!isLatest()) return;
        ops.value = res.items;
    } catch (e) {
        if (isLatest()) toast.add({ severity: 'error', summary: 'Opérations illisibles', detail: apiMessage(e), life: 6000 });
    } finally {
        if (isLatest()) loadingOps.value = false;
    }
}

// ---------- relevés kilométriques ----------
const readings = ref([]);
const loadingReadings = ref(false);
const readingDialogVisible = ref(false);
const readingMode = ref('create'); // 'create' | 'edit'
const editingReadingId = ref(null);
const savingReading = ref(false);
const readingForm = reactive({ date: null, km: null, note: '' });
const readingErrors = reactive({});


function clearReadingErrors() {
    for (const k of Object.keys(readingErrors)) delete readingErrors[k];
}

const readingsGuard = latestGuard();
async function loadReadings() {
    const isLatest = readingsGuard();
    if (!vehicle.value) return;
    loadingReadings.value = true;
    try {
        const res = await listOdometerReadings(vehicleId);
        if (!isLatest()) return;
        // Clé unique : les points des pleins et de l'AdBlue n'ont pas d'id de relevé.
        readings.value = res.map((r) => ({ ...r, key: `${r.source}-${r.id ?? r.operationId ?? r.adblueFillId}` }));
    } catch (e) {
        if (isLatest()) toast.add({ severity: 'error', summary: 'Relevés illisibles', detail: apiMessage(e), life: 6000 });
    } finally {
        if (isLatest()) loadingReadings.value = false;
    }
}

function openReadingCreate() {
    readingMode.value = 'create';
    editingReadingId.value = null;
    Object.assign(readingForm, { date: new Date(), km: null, note: '' });
    clearReadingErrors();
    readingDialogVisible.value = true;
}

function openReadingEdit(r) {
    readingMode.value = 'edit';
    editingReadingId.value = r.id;
    Object.assign(readingForm, { date: isoToDate(r.date), km: r.km, note: r.note ?? '' });
    clearReadingErrors();
    readingDialogVisible.value = true;
}

async function submitReading() {
    if (!readingForm.date || readingForm.km === null || readingForm.km === undefined) return;
    savingReading.value = true;
    clearReadingErrors();
    const payload = {
        date: dateToIso(readingForm.date),
        km: Math.abs(Math.round(readingForm.km)),
        note: readingForm.note.trim(),
    };
    try {
        if (readingMode.value === 'create') {
            const created = await createOdometerReading(vehicleId, payload);
            toast.add({ severity: 'success', summary: 'Relevé ajouté', life: 3000 });
            // Le relevé peut être incohérent avec ses voisins : l'API renvoie un « warning » (pas une erreur).
            if (created.warning) {
                toast.add({ severity: 'warn', summary: 'Relevé incohérent', detail: created.warning, life: 10000 });
            }
        } else {
            await updateOdometerReading(editingReadingId.value, payload);
            toast.add({ severity: 'success', summary: 'Relevé mis à jour', life: 3000 });
        }
        readingDialogVisible.value = false;
        await Promise.all([loadReadings(), loadStats(), vehiclesStore.refresh(true)]);
    } catch (e) {
        const data = e.response?.data;
        if (Array.isArray(data?.violations)) {
            for (const v of data.violations) readingErrors[v.field] = v.message;
        } else if (e.response?.status === 409 && data?.error === 'reading_date_exists') {
            readingErrors.date = data.message;
        }
        toast.add({ severity: e.response?.status === 409 ? 'warn' : 'error', summary: 'Relevé non enregistré', detail: apiMessage(e), life: 8000 });
    } finally {
        savingReading.value = false;
    }
}

function removeReading(r) {
    confirm.require({
        message: `Supprimer le relevé du ${formatDate(r.date)} (${r.km.toLocaleString('fr-FR')} km) ?`,
        header: 'Suppression',
        icon: 'pi pi-exclamation-triangle',
        acceptLabel: 'Supprimer',
        rejectLabel: 'Annuler',
        acceptClass: 'p-button-danger',
        accept: async () => {
            try {
                await deleteOdometerReading(r.id);
                toast.add({ severity: 'success', summary: 'Relevé supprimé', life: 3000 });
                await Promise.all([loadReadings(), loadStats(), vehiclesStore.refresh(true)]);
            } catch (e) {
                toast.add({ severity: 'error', summary: 'Suppression impossible', detail: apiMessage(e), life: 6000 });
            }
        },
    });
}

// ---------- AdBlue (ADR 0016) ----------
const adblueData = ref(null); // {fills: [...], summary: {count, volumeMl, cost, estimatedCost, avgPricePerLiter, consumption, distanceKm}}
const prevAdblueSummary = ref(null); // synthèse de la période précédente (case « Comparer »), null si inactive
const loadingAdblue = ref(false);
const adblueError = ref('');
const ADBLUE_SOURCES = [
    { label: 'Station', value: 'station' },
    { label: 'Bidon', value: 'can' },
];
const consumptionPer1000Fmt = new Intl.NumberFormat('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
function formatPer1000(v) {
    return v === null || v === undefined ? '—' : `${consumptionPer1000Fmt.format(v)} L/1000 km`;
}

const adblueGuard = latestGuard();
async function loadAdblue() {
    const isLatest = adblueGuard();
    if (!vehicle.value) return;
    loadingAdblue.value = true;
    adblueError.value = '';
    try {
        const prevParams = period.previousParams;
        const [cur, prev] = await Promise.all([
            getVehicleAdblue(vehicleId, period.params),
            prevParams ? getVehicleAdblue(vehicleId, prevParams) : Promise.resolve(null),
        ]);
        if (!isLatest()) return;
        adblueData.value = cur;
        prevAdblueSummary.value = prev?.summary ?? null;
    } catch (e) {
        if (isLatest()) adblueError.value = apiMessage(e, 'AdBlue indisponible');
    } finally {
        if (isLatest()) loadingAdblue.value = false;
    }
}

const adblueKpis = computed(() => {
    const s = adblueData.value?.summary;
    if (!s) return [];
    const ps = prevAdblueSummary.value;
    const d = (cur, prev, lowerIsBetter) => (ps ? delta(cur, prev, lowerIsBetter) : {});
    return [
        {
            label: 'Consommation AdBlue',
            value: formatPer1000(s.consumption),
            subtext: s.distanceKm != null ? `sur ${s.distanceKm.toLocaleString('fr-FR')} km couverts par les relevés` : '2 relevés kilométriques requis',
            ...d(s.consumption, ps?.consumption),
        },
        { label: 'Volume versé', value: formatLiters(s.volumeMl), subtext: `${s.count} remplissage(s)`, ...d(s.volumeMl, ps?.volumeMl, null) },
        { label: 'Coût en station', value: formatMoney(s.cost), subtext: 'lignes de relevé liées', ...d(s.cost, ps?.cost) },
        {
            label: 'Coût bidons (estimé)',
            value: formatMoney(s.estimatedCost),
            subtext: 'au prorata du prix du bidon · déjà compté dans les courses',
            ...d(s.estimatedCost, ps?.estimatedCost),
        },
    ];
});

// Section affichée pour un diesel, ou dès qu'un remplissage existe.
const showAdblue = computed(() => vehicle.value?.energy === 'diesel' || (adblueData.value?.fills?.length ?? 0) > 0);

const adblueDialogVisible = ref(false);
const editingAdblueId = ref(null);
const savingAdblue = ref(false);
const adblueForm = reactive({ date: null, source: 'station', volumeL: null, odometerKm: null, operationId: null, canPriceEur: null, canVolumeL: null, note: '' });
const adblueErrors = reactive({});
const adblueCandidates = ref([]);
const loadingCandidates = ref(false);

function clearAdblueErrors() {
    for (const k of Object.keys(adblueErrors)) delete adblueErrors[k];
}

// Dernier bidon saisi : pré-remplit prix et contenance (même bidon le plus souvent).
function lastCan() {
    return (adblueData.value?.fills ?? []).find((f) => f.source === 'can' && f.canPrice != null) ?? null;
}

function openAdblueCreate() {
    editingAdblueId.value = null;
    const can = lastCan();
    Object.assign(adblueForm, {
        date: new Date(),
        source: 'station',
        volumeL: null,
        odometerKm: null,
        operationId: null,
        canPriceEur: can ? can.canPrice / 100 : null,
        canVolumeL: can ? can.canVolumeMl / 1000 : null,
        note: '',
    });
    clearAdblueErrors();
    adblueDialogVisible.value = true;
    loadCandidates();
}

function openAdblueEdit(f) {
    editingAdblueId.value = f.id;
    const can = lastCan();
    Object.assign(adblueForm, {
        date: isoToDate(f.date),
        source: f.source,
        volumeL: f.volumeMl / 1000,
        odometerKm: f.odometerKm,
        operationId: f.operation?.id ?? null,
        canPriceEur: f.canPrice != null ? f.canPrice / 100 : can ? can.canPrice / 100 : null,
        canVolumeL: f.canVolumeMl != null ? f.canVolumeMl / 1000 : can ? can.canVolumeMl / 1000 : null,
        note: f.note ?? '',
    });
    clearAdblueErrors();
    adblueDialogVisible.value = true;
    loadCandidates();
}

const candidatesGuard = latestGuard();
async function loadCandidates() {
    const isLatest = candidatesGuard();
    if (!adblueForm.date || adblueForm.source !== 'station') {
        // Une requête précédente encore en vol sera ignorée : ne pas laisser le chargement affiché.
        loadingCandidates.value = false;
        return;
    }
    loadingCandidates.value = true;
    try {
        const res = await listAdblueCandidates(vehicleId, dateToIso(adblueForm.date), editingAdblueId.value);
        if (!isLatest()) return;
        adblueCandidates.value = res;
    } catch (e) {
        if (isLatest()) {
            adblueCandidates.value = [];
            toast.add({ severity: 'error', summary: 'Opérations indisponibles', detail: apiMessage(e), life: 6000 });
        }
    } finally {
        if (isLatest()) loadingCandidates.value = false;
    }
}

watch(() => [adblueForm.date, adblueForm.source], () => {
    if (adblueDialogVisible.value) loadCandidates();
});

const candidateOptions = computed(() =>
    adblueCandidates.value.map((o) => ({
        value: o.id,
        label: `${formatDate(o.date)} · ${formatMoney(o.amount)} · ${o.merchant || o.label}${o.awaitingStatement ? ' (saisie)' : ''}`,
    })),
);

// Coût estimé du remplissage au bidon, en centimes (aperçu dans le dialogue).
const adblueEstimate = computed(() => {
    if (adblueForm.source !== 'can' || !adblueForm.volumeL || !adblueForm.canPriceEur || !adblueForm.canVolumeL) return null;
    return Math.round((adblueForm.canPriceEur * 100 * adblueForm.volumeL) / adblueForm.canVolumeL);
});

const adblueCanSubmit = computed(() => !!adblueForm.date && !!adblueForm.volumeL && adblueForm.volumeL > 0);

async function submitAdblue() {
    if (!adblueCanSubmit.value) return;
    savingAdblue.value = true;
    clearAdblueErrors();
    const isCan = adblueForm.source === 'can';
    const hasCan = isCan && adblueForm.canPriceEur > 0 && adblueForm.canVolumeL > 0;
    const payload = {
        date: dateToIso(adblueForm.date),
        source: adblueForm.source,
        volumeMl: Math.round(adblueForm.volumeL * 1000),
        odometerKm: adblueForm.odometerKm === null || adblueForm.odometerKm === undefined ? null : Math.round(adblueForm.odometerKm),
        operationId: isCan ? null : adblueForm.operationId,
        canPrice: hasCan ? Math.round(adblueForm.canPriceEur * 100) : null,
        canVolumeMl: hasCan ? Math.round(adblueForm.canVolumeL * 1000) : null,
        note: adblueForm.note.trim() || null,
    };
    try {
        const saved = editingAdblueId.value
            ? await updateAdblueFill(editingAdblueId.value, payload)
            : await createAdblueFill(vehicleId, payload);
        toast.add({ severity: 'success', summary: editingAdblueId.value ? 'Remplissage mis à jour' : 'Remplissage ajouté', life: 3000 });
        if (saved.warning) {
            toast.add({ severity: 'warn', summary: 'Kilométrage incohérent', detail: saved.warning, life: 10000 });
        }
        adblueDialogVisible.value = false;
        await Promise.all([loadAdblue(), loadReadings(), loadStats(), loadOps(), vehiclesStore.refresh(true)]);
    } catch (e) {
        const data = e.response?.data;
        if (Array.isArray(data?.violations)) {
            for (const v of data.violations) adblueErrors[v.field] = v.message;
        } else if (e.response?.status === 409) {
            adblueErrors.operationId = data?.message;
        }
        toast.add({ severity: e.response?.status === 409 ? 'warn' : 'error', summary: 'Remplissage non enregistré', detail: apiMessage(e), life: 8000 });
    } finally {
        savingAdblue.value = false;
    }
}

function removeAdblue(f) {
    confirm.require({
        message: `Supprimer le remplissage d'AdBlue du ${formatDate(f.date)} (${formatLiters(f.volumeMl)}) ? L'opération liée est conservée.`,
        header: 'Suppression',
        icon: 'pi pi-exclamation-triangle',
        acceptLabel: 'Supprimer',
        rejectLabel: 'Annuler',
        acceptClass: 'p-button-danger',
        accept: async () => {
            try {
                await deleteAdblueFill(f.id);
                toast.add({ severity: 'success', summary: 'Remplissage supprimé', life: 3000 });
                await Promise.all([loadAdblue(), loadReadings(), loadStats(), vehiclesStore.refresh(true)]);
            } catch (e) {
                toast.add({ severity: 'error', summary: 'Suppression impossible', detail: apiMessage(e), life: 6000 });
            }
        },
    });
}

// ---------- init ----------
watch(() => [scope.params, period.params], () => { loadStats(); loadOps(); loadFuel(); loadAdblue(); }, { deep: true });
// Case « Comparer à la période précédente » : ne change pas period.params, recharge les synthèses.
watch(() => period.compareActive, () => { loadStats(); loadFuel(); loadAdblue(); });

onMounted(async () => {
    await vehiclesStore.refresh(true).catch(() => {});
    notFound.value = !vehicle.value;
    if (vehicle.value) {
        loadStats();
        loadOps();
        loadReadings();
        loadFuel();
        loadAdblue();
    }
});
</script>

<template>
    <div>
        <p class="text-muted back-link">
            <RouterLink :to="{ name: 'vehicles' }">Réglages › Véhicules</RouterLink>
        </p>

        <template v-if="vehicle">
            <div class="page-header">
                <h1>
                    <i :class="VEHICLE_TYPE_ICONS[vehicle.type] ?? 'pi pi-car'" aria-hidden="true" />
                    {{ vehicle.name }}
                    <Tag v-if="vehicle.archived" value="Archivé" severity="secondary" class="archived-tag" />
                </h1>
                <RouterLink :to="{ name: 'vehicles' }">
                    <Button label="Modifier dans Réglages" icon="pi pi-pencil" text severity="secondary" size="small" />
                </RouterLink>
            </div>
            <p class="text-muted vehicle-sub">
                {{ VEHICLE_TYPE_LABELS[vehicle.type] ?? vehicle.type }}
                <template v-if="vehicle.energy"> · {{ VEHICLE_ENERGY_LABELS[vehicle.energy] ?? vehicle.energy }}</template>
                <template v-if="[vehicle.make, vehicle.model].filter(Boolean).length">
                    · {{ [vehicle.make, vehicle.model].filter(Boolean).join(' ') }}
                </template>
                <template v-if="vehicle.mainDriver"> · conducteur principal : {{ vehicle.mainDriver.displayName }}</template>
            </p>

            <PeriodFilter />

            <div class="kpi-grid">
                <KpiTile v-for="k in kpis" :key="k.label" v-bind="k" />
            </div>
            <p v-if="statsError" class="text-muted" role="alert">{{ statsError }}</p>

            <div class="charts-grid">
                <ChartCard
                    title="Dépenses par catégorie"
                    subtitle="Répartition des dépenses du véhicule sur la période"
                    aria-label="Donut des dépenses du véhicule par catégorie"
                    :loading="loadingStats"
                    :error="statsError"
                    :empty="donutEmpty"
                    empty-text="Aucune dépense sur la période."
                    :height="320"
                    @retry="loadStats"
                >
                    <VChart :option="donutOption" aria-label="Donut des dépenses par catégorie" />
                    <template #table>
                        <DataTable :value="donutRows" size="small" scrollable scroll-height="280px">
                            <template #empty>Aucune donnée</template>
                            <Column header="Catégorie">
                                <template #body="{ data }">
                                    <span class="cat-dot" :style="{ background: donutColor(data.category) }" aria-hidden="true" />
                                    {{ data.category?.name ?? 'Non catégorisé' }}
                                </template>
                            </Column>
                            <Column field="total" header="Total" class="text-right">
                                <template #body="{ data }">{{ formatMoney(data.total) }}</template>
                            </Column>
                        </DataTable>
                    </template>
                </ChartCard>
            </div>

            <div class="page-header section">
                <h2>Pleins</h2>
            </div>
            <p v-if="fuelError" class="text-muted" role="alert">{{ fuelError }}</p>
            <template v-if="fuelData">
                <p v-if="!fuelData.summary.count" class="empty-state">
                    Aucun plein renseigné : saisissez le volume lors de la saisie ou de la catégorisation d'une dépense Carburant.
                </p>
                <template v-else>
                    <div class="kpi-grid">
                        <KpiTile v-for="k in fuelKpis" :key="k.label" v-bind="k" />
                    </div>

                    <div class="charts-grid">
                        <ChartCard
                            v-if="fuelData.fills.length >= 2"
                            title="Prix au litre par plein"
                            subtitle="Évolution du prix au litre et de la consommation par segment (méthode du plein complet)"
                            aria-label="Graphique du prix au litre par plein et de la consommation"
                            :loading="loadingFuel"
                            :error="fuelError"
                            :empty="false"
                            :height="320"
                            @retry="loadFuel"
                        >
                            <VChart :option="fuelChartOption" aria-label="Prix au litre par plein et consommation par segment" />
                        </ChartCard>
                    </div>

                    <DataTable :value="fuelData.fills" :loading="loadingFuel" size="small" data-key="operationId">
                        <template #empty>Aucun plein sur la période.</template>
                        <Column field="date" header="Date" style="width: 7.5rem">
                            <template #body="{ data }">{{ formatDate(data.date) }}</template>
                        </Column>
                        <Column header="Libellé">
                            <template #body="{ data }">
                                <span :title="data.label">{{ data.label }}</span>
                                <Tag
                                    v-if="data.awaitingStatement"
                                    value="en attente de relevé"
                                    severity="warn"
                                    icon="pi pi-clock"
                                    class="awaiting-tag"
                                />
                            </template>
                        </Column>
                        <Column field="amount" header="Montant" class="text-right" style="width: 8.5rem">
                            <template #body="{ data }"><MoneyAmount :amount="data.amount" /></template>
                        </Column>
                        <Column field="volumeMl" header="Volume" class="text-right" style="width: 8rem">
                            <template #body="{ data }">{{ formatLiters(data.volumeMl) }}</template>
                        </Column>
                        <Column field="pricePerLiter" header="Prix/L" class="text-right" style="width: 8.5rem">
                            <template #body="{ data }">{{ formatPricePerLiter(data.pricePerLiter) }}</template>
                        </Column>
                        <Column header="Plein" style="width: 7rem">
                            <template #body="{ data }">
                                <Tag v-if="data.fullTank === false" value="partiel" severity="warn" />
                                <span v-else class="text-muted">complet</span>
                            </template>
                        </Column>
                        <Column field="odometerKm" header="Km" class="text-right" style="width: 8rem">
                            <template #body="{ data }">{{ data.odometerKm != null ? `${data.odometerKm.toLocaleString('fr-FR')} km` : '—' }}</template>
                        </Column>
                        <Column header="Conso. segment" class="text-right" style="width: 11rem">
                            <template #body="{ data }">
                                {{ formatConsumption(data.consumption)
                                }}<span v-if="data.segmentKm != null" class="text-muted"> ({{ Math.round(data.segmentKm).toLocaleString('fr-FR') }} km)</span>
                            </template>
                        </Column>
                    </DataTable>
                </template>
            </template>

            <template v-if="showAdblue">
                <div class="page-header section">
                    <h2>AdBlue</h2>
                    <Button label="Ajouter un remplissage" icon="pi pi-plus" size="small" @click="openAdblueCreate" />
                </div>
                <p v-if="adblueError" class="text-muted" role="alert">{{ adblueError }}</p>
                <template v-if="adblueData">
                    <p v-if="!adblueData.summary.count" class="empty-state">
                        Aucun remplissage sur la période. En station, liez la ligne du relevé ; au bidon, indiquez son prix et sa contenance pour estimer le coût.
                    </p>
                    <template v-else>
                        <div class="kpi-grid">
                            <KpiTile v-for="k in adblueKpis" :key="k.label" v-bind="k" />
                        </div>
                        <DataTable :value="adblueData.fills" :loading="loadingAdblue" size="small" data-key="id">
                            <Column field="date" header="Date" style="width: 7.5rem">
                                <template #body="{ data }">{{ formatDate(data.date) }}</template>
                            </Column>
                            <Column header="Origine" style="width: 7rem">
                                <template #body="{ data }">
                                    <Tag :value="data.source === 'can' ? 'Bidon' : 'Station'" :severity="data.source === 'can' ? 'secondary' : 'info'" />
                                </template>
                            </Column>
                            <Column header="Détail">
                                <template #body="{ data }">
                                    <template v-if="data.source === 'station'">
                                        <span v-if="data.operation" :title="data.operation.label">{{ data.operation.label }}</span>
                                        <Tag v-else value="ligne de relevé à lier" severity="warn" icon="pi pi-link" />
                                    </template>
                                    <span v-else-if="data.canPrice != null" class="text-muted">
                                        bidon de {{ formatLiters(data.canVolumeMl) }} à {{ formatMoney(data.canPrice) }}
                                    </span>
                                    <span v-else class="text-muted">prix du bidon non renseigné</span>
                                    <span v-if="data.note" class="text-muted"> · {{ data.note }}</span>
                                </template>
                            </Column>
                            <Column field="volumeMl" header="Volume" class="text-right" style="width: 7.5rem">
                                <template #body="{ data }">{{ formatLiters(data.volumeMl) }}</template>
                            </Column>
                            <Column field="cost" header="Coût" class="text-right" style="width: 9rem">
                                <template #body="{ data }">
                                    <template v-if="data.cost != null">
                                        {{ formatMoney(data.cost) }}<span v-if="data.costEstimated" class="text-muted" title="Estimation au prorata du prix du bidon"> (est.)</span>
                                    </template>
                                    <span v-else class="text-muted">—</span>
                                </template>
                            </Column>
                            <Column field="pricePerLiter" header="Prix/L" class="text-right" style="width: 8rem">
                                <template #body="{ data }">{{ formatPricePerLiter(data.pricePerLiter) }}</template>
                            </Column>
                            <Column field="odometerKm" header="Km" class="text-right" style="width: 8rem">
                                <template #body="{ data }">{{ data.odometerKm != null ? `${data.odometerKm.toLocaleString('fr-FR')} km` : '—' }}</template>
                            </Column>
                            <Column header="Actions" style="width: 7rem">
                                <template #body="{ data }">
                                    <Button icon="pi pi-pencil" text rounded size="small" :aria-label="'Modifier le remplissage du ' + formatDate(data.date)" @click="openAdblueEdit(data)" />
                                    <Button icon="pi pi-trash" text rounded size="small" severity="danger" :aria-label="'Supprimer le remplissage du ' + formatDate(data.date)" @click="removeAdblue(data)" />
                                </template>
                            </Column>
                        </DataTable>
                    </template>
                </template>
            </template>

            <h2>Dernières opérations</h2>
            <DataTable :value="ops" :loading="loadingOps" size="small" data-key="id">
                <template #empty>Aucune opération pour ce véhicule.</template>
                <Column field="date" header="Date" style="width: 7.5rem">
                    <template #body="{ data }">{{ formatDate(data.date) }}</template>
                </Column>
                <Column header="Libellé">
                    <template #body="{ data }">
                        <strong v-if="data.merchant" class="op-merchant">{{ data.merchant }}</strong>
                        <span class="text-muted" :title="data.rawLabel">{{ data.rawLabel }}</span>
                    </template>
                </Column>
                <Column header="Catégorie" style="width: 13rem">
                    <template #body="{ data }"><CategoryTag :category="data.category" /></template>
                </Column>
                <Column header="Véhicule" style="width: 11rem">
                    <template #body="{ data }"><VehicleTag :vehicle="data.vehicle" :source="data.vehicleSource" /></template>
                </Column>
                <Column field="amount" header="Montant" class="text-right" style="width: 9rem">
                    <template #body="{ data }"><MoneyAmount :amount="data.amount" /></template>
                </Column>
            </DataTable>
            <p>
                <RouterLink :to="{ name: 'operations', query: { vehicleId: vehicle.id } }">Voir toutes les opérations du véhicule</RouterLink>
            </p>

            <div class="page-header section">
                <h2>Relevés kilométriques</h2>
                <Button label="Ajouter un relevé" icon="pi pi-plus" size="small" @click="openReadingCreate" />
            </div>
            <DataTable :value="readings" :loading="loadingReadings" size="small" data-key="key">
                <template #empty>Aucun relevé. Ajoutez le compteur actuel pour calculer les km parcourus.</template>
                <Column field="date" header="Date" style="width: 8rem">
                    <template #body="{ data }">{{ formatDate(data.date) }}</template>
                </Column>
                <Column field="km" header="Kilométrage" class="text-right" style="width: 10rem">
                    <template #body="{ data }">{{ data.km.toLocaleString('fr-FR') }} km</template>
                </Column>
                <Column header="Origine" style="width: 8rem">
                    <template #body="{ data }">
                        <Tag v-if="data.source === 'fuel'" value="Plein" severity="info" />
                        <Tag v-else-if="data.source === 'adblue'" value="AdBlue" severity="secondary" />
                        <Tag v-else value="Relevé" severity="contrast" />
                    </template>
                </Column>
                <Column field="note" header="Note" />
                <Column header="Actions" style="width: 7rem">
                    <template #body="{ data }">
                        <span v-if="data.source !== 'reading'" class="text-muted" :title="data.source === 'fuel' ? 'Modifiable depuis le plein (opération)' : 'Modifiable depuis le remplissage d\'AdBlue'">—</span>
                        <template v-else>
                            <Button icon="pi pi-pencil" text rounded size="small" :aria-label="'Modifier le relevé du ' + formatDate(data.date)" @click="openReadingEdit(data)" />
                            <Button icon="pi pi-trash" text rounded size="small" severity="danger" :aria-label="'Supprimer le relevé du ' + formatDate(data.date)" @click="removeReading(data)" />
                        </template>
                    </template>
                </Column>
            </DataTable>
        </template>

        <div v-else-if="notFound" class="empty-state">
            <p>Véhicule introuvable dans ce foyer.</p>
            <p><RouterLink :to="{ name: 'vehicles' }">Retour à la liste des véhicules</RouterLink></p>
        </div>

        <Dialog
            v-model:visible="adblueDialogVisible"
            modal
            :header="editingAdblueId ? 'Modifier le remplissage d’AdBlue' : 'Ajouter un remplissage d’AdBlue'"
            :style="{ width: '30rem' }"
            :breakpoints="{ '575px': '95vw' }"
            :draggable="false"
        >
            <div class="form">
                <div class="field">
                    <label for="adblue-source">Origine</label>
                    <SelectButton
                        id="adblue-source"
                        v-model="adblueForm.source"
                        :options="ADBLUE_SOURCES"
                        option-label="label"
                        option-value="value"
                        :allow-empty="false"
                    />
                </div>
                <div class="field">
                    <label for="adblue-date">Date</label>
                    <DatePicker input-id="adblue-date" v-model="adblueForm.date" date-format="dd/mm/yy" show-icon class="w-full" :invalid="!!adblueErrors.date" />
                    <small v-if="adblueErrors.date" class="field-error">{{ adblueErrors.date }}</small>
                </div>
                <div class="field">
                    <label for="adblue-volume">Volume versé (L)</label>
                    <InputNumber
                        input-id="adblue-volume"
                        v-model="adblueForm.volumeL"
                        locale="fr-FR"
                        :min-fraction-digits="0"
                        :max-fraction-digits="2"
                        :min="0"
                        suffix=" L"
                        class="w-full"
                        :invalid="!!adblueErrors.volumeMl"
                    />
                    <small v-if="adblueErrors.volumeMl" class="field-error">{{ adblueErrors.volumeMl }}</small>
                </div>
                <div class="field">
                    <label for="adblue-km">Kilométrage au compteur (optionnel)</label>
                    <InputNumber input-id="adblue-km" v-model="adblueForm.odometerKm" :use-grouping="true" :min="0" suffix=" km" class="w-full" :invalid="!!adblueErrors.odometerKm" />
                    <small v-if="adblueErrors.odometerKm" class="field-error">{{ adblueErrors.odometerKm }}</small>
                </div>
                <div v-if="adblueForm.source === 'station'" class="field">
                    <label for="adblue-op">Ligne du relevé (paiement)</label>
                    <Select
                        input-id="adblue-op"
                        v-model="adblueForm.operationId"
                        :options="candidateOptions"
                        option-label="label"
                        option-value="value"
                        :loading="loadingCandidates"
                        show-clear
                        filter
                        placeholder="Pas encore sur le relevé"
                        empty-message="Aucun débit libre à ± 10 jours"
                        class="w-full"
                        :invalid="!!adblueErrors.operationId"
                    />
                    <small v-if="adblueErrors.operationId" class="field-error">{{ adblueErrors.operationId }}</small>
                    <small v-else class="text-muted form-hint">Débits à ± 10 jours de la date. La ligne liée est affectée au véhicule.</small>
                </div>
                <template v-else>
                    <div class="field-row">
                        <div class="field">
                            <label for="adblue-can-price">Prix du bidon</label>
                            <InputNumber input-id="adblue-can-price" v-model="adblueForm.canPriceEur" mode="currency" currency="EUR" locale="fr-FR" :min="0" class="w-full" :invalid="!!adblueErrors.canPrice" />
                        </div>
                        <div class="field">
                            <label for="adblue-can-volume">Contenance</label>
                            <InputNumber
                                input-id="adblue-can-volume"
                                v-model="adblueForm.canVolumeL"
                                locale="fr-FR"
                                :min-fraction-digits="0"
                                :max-fraction-digits="2"
                                :min="0"
                                suffix=" L"
                                class="w-full"
                                :invalid="!!adblueErrors.canVolumeMl"
                            />
                        </div>
                    </div>
                    <small v-if="adblueErrors.canPrice || adblueErrors.canVolumeMl" class="field-error form-hint">{{ adblueErrors.canPrice || adblueErrors.canVolumeMl }}</small>
                    <small v-else class="text-muted form-hint">
                        <template v-if="adblueEstimate != null">Coût estimé : {{ formatMoney(adblueEstimate) }} · </template>
                        déjà compté dans les courses côté banque : affiché à part, jamais ajouté aux dépenses du véhicule.
                    </small>
                </template>
                <div class="field">
                    <label for="adblue-note">Note (optionnelle)</label>
                    <InputText id="adblue-note" v-model="adblueForm.note" class="w-full" />
                </div>
            </div>
            <template #footer>
                <Button label="Annuler" severity="secondary" text @click="adblueDialogVisible = false" />
                <Button :label="editingAdblueId ? 'Enregistrer' : 'Ajouter'" icon="pi pi-check" :loading="savingAdblue" :disabled="!adblueCanSubmit" @click="submitAdblue" />
            </template>
        </Dialog>

        <Dialog
            v-model:visible="readingDialogVisible"
            modal
            :header="readingMode === 'create' ? 'Ajouter un relevé' : 'Modifier le relevé'"
            :style="{ width: '26rem' }"
            :draggable="false"
        >
            <div class="form">
                <div class="field">
                    <label for="reading-date">Date</label>
                    <DatePicker
                        input-id="reading-date"
                        v-model="readingForm.date"
                        date-format="dd/mm/yy"
                        show-icon
                        show-button-bar
                        class="w-full"
                        :invalid="!!readingErrors.date"
                    />
                    <small v-if="readingErrors.date" class="field-error">{{ readingErrors.date }}</small>
                </div>
                <div class="field">
                    <label for="reading-km">Kilométrage</label>
                    <InputNumber
                        id="reading-km"
                        v-model="readingForm.km"
                        :use-grouping="true"
                        :min="0"
                        class="w-full"
                        :invalid="!!readingErrors.km"
                    />
                    <small v-if="readingErrors.km" class="field-error">{{ readingErrors.km }}</small>
                </div>
                <div class="field">
                    <label for="reading-note">Note (optionnelle)</label>
                    <InputText id="reading-note" v-model="readingForm.note" class="w-full" />
                </div>
            </div>
            <template #footer>
                <Button label="Annuler" severity="secondary" text @click="readingDialogVisible = false" />
                <Button
                    :label="readingMode === 'create' ? 'Ajouter' : 'Enregistrer'"
                    icon="pi pi-check"
                    :loading="savingReading"
                    :disabled="!readingForm.date || readingForm.km === null || readingForm.km === undefined"
                    @click="submitReading"
                />
            </template>
        </Dialog>
    </div>
</template>

<style scoped>
.back-link {
    margin: 0 0 0.5rem;
    font-size: 0.9rem;
}
.page-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    flex-wrap: wrap;
}
.page-header h1 {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
}
.archived-tag {
    font-size: 0.75rem;
}
.vehicle-sub {
    margin-top: -0.75rem;
}
.kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(13rem, 1fr));
    gap: 1rem;
    margin-bottom: 1rem;
}
.charts-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 1rem;
}
.section {
    margin-top: 2rem;
}
.form .field {
    margin-bottom: 1rem;
}
.form label {
    display: block;
    font-weight: 600;
    margin-bottom: 0.3rem;
}
.field-error {
    display: block;
    margin-top: 0.3rem;
    color: var(--p-red-500);
}
.op-merchant {
    margin-right: 0.4rem;
}
.field-row {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.75rem;
}
.field-row .field {
    margin-bottom: 0.3rem;
}
.form-hint {
    display: block;
    margin: 0.3rem 0 1rem;
}
.empty-state {
    text-align: center;
    color: var(--p-text-muted-color);
    padding: 2rem;
}
.awaiting-tag {
    margin-left: 0.4rem;
    font-size: 0.7rem;
    white-space: nowrap;
}
@media (max-width: 900px) {
    .charts-grid {
        grid-template-columns: minmax(0, 1fr);
    }
}
</style>
