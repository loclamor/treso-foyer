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
import { useToast } from 'primevue/usetoast';
import { useConfirm } from 'primevue/useconfirm';
import { useScopeStore } from '@/stores/scope';
import { usePeriodStore } from '@/stores/period';
import { useVehiclesStore } from '@/stores/vehicles';
import { listOperations } from '@/api/operations';
import { getVehiclesStats } from '@/api/stats';
import { createOdometerReading, deleteOdometerReading, listOdometerReadings, updateOdometerReading } from '@/api/vehicles';
import { apiMessage } from '@/api/http';
import { dateToIso, formatDate, formatMoney, formatPercent } from '@/utils/format';
import { VEHICLE_ENERGY_LABELS, VEHICLE_TYPE_ICONS, VEHICLE_TYPE_LABELS } from '@/utils/vehicles';
import { CHART_FONT, baseLegend, chartColors, useChartTheme } from '@/charts/theme';
import { colorForCategory } from '@/charts/palette';
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
const vehicleStats = ref(null); // entrée {vehicle, total, count, monthlyAverage, byCategory, distance, costPerKm}
const loadingStats = ref(false);
const statsError = ref('');

async function loadStats() {
    if (!vehicle.value) return;
    loadingStats.value = true;
    statsError.value = '';
    try {
        const res = await getVehiclesStats(scope.params, period.params);
        vehicleStats.value = res.vehicles.find((v) => v.vehicle.id === vehicleId) ?? null;
    } catch (e) {
        statsError.value = apiMessage(e, 'Statistiques indisponibles');
    } finally {
        loadingStats.value = false;
    }
}

const kpis = computed(() => {
    const vs = vehicleStats.value;
    if (!vs) return [];
    const km = vs.distance ? Math.round(vs.distance.km).toLocaleString('fr-FR') + ' km' : '—';
    return [
        {
            label: 'Total dépensé',
            value: formatMoney(vs.total),
            subtext: `${vs.count.toLocaleString('fr-FR')} opération(s) sur la période`,
        },
        {
            label: 'Moyenne mensuelle',
            value: formatMoney(vs.monthlyAverage),
            subtext: '',
        },
        {
            label: 'Km parcourus',
            value: km,
            subtext: vs.distance ? `du ${formatDate(vs.distance.coveredFrom)} au ${formatDate(vs.distance.coveredTo)}` : 'moins de 2 relevés',
        },
        {
            label: 'Coût au km',
            value: vs.costPerKm != null ? `${formatMoney(vs.costPerKm)}/km` : '—',
            subtext: 'dépenses de la période couverte ÷ km',
        },
    ];
});

// ---------- donut par catégorie ----------
const donutOption = computed(() => {
    const vs = vehicleStats.value;
    if (!vs || !vs.byCategory?.length) return {};
    const c = chartColors(theme.mode.value);
    const total = vs.byCategory.reduce((s, b) => s + (b.total ?? 0), 0);
    const data = vs.byCategory.map((b) => ({
        name: b.category?.name ?? 'Non catégorisé',
        value: b.total,
        itemStyle: { color: colorForCategory(b.category, theme.mode.value), borderRadius: 4, borderColor: c.surface, borderWidth: 2 },
    }));
    return {
        tooltip: {
            trigger: 'item',
            backgroundColor: c.surface,
            borderColor: c.grid,
            borderWidth: 1,
            textStyle: { color: c.ink, fontFamily: CHART_FONT, fontSize: 12 },
            formatter: (p) => `${p.name} : <strong>${formatMoney(p.value)}</strong>${total ? ` (${formatPercent(p.value / total, 1)})` : ''}`,
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

// ---------- dernières opérations du véhicule ----------
const ops = ref([]);
const loadingOps = ref(false);

async function loadOps() {
    if (!vehicle.value) return;
    loadingOps.value = true;
    try {
        const res = await listOperations({ ...scope.params, vehicleId, page: 1, perPage: 10, sort: 'date', order: 'desc' });
        ops.value = res.items;
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Opérations illisibles', detail: apiMessage(e), life: 6000 });
    } finally {
        loadingOps.value = false;
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

function isoToDate(iso) {
    if (!iso) return null;
    const [y, m, d] = String(iso).split('-').map(Number);
    return y && m && d ? new Date(y, m - 1, d) : null;
}

function clearReadingErrors() {
    for (const k of Object.keys(readingErrors)) delete readingErrors[k];
}

async function loadReadings() {
    if (!vehicle.value) return;
    loadingReadings.value = true;
    try {
        readings.value = await listOdometerReadings(vehicleId);
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Relevés illisibles', detail: apiMessage(e), life: 6000 });
    } finally {
        loadingReadings.value = false;
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

// ---------- init ----------
watch(() => [scope.params, period.params], () => { loadStats(); loadOps(); }, { deep: true });

onMounted(async () => {
    await vehiclesStore.refresh(true).catch(() => {});
    notFound.value = !vehicle.value;
    if (vehicle.value) {
        loadStats();
        loadOps();
        loadReadings();
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
                <KpiTile v-for="k in kpis" :key="k.label" :label="k.label" :value="k.value" :subtext="k.subtext" />
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
                                    <span class="cat-dot" :style="{ background: colorForCategory(data.category, theme.mode.value) }" aria-hidden="true" />
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

            <h2>Dernières opérations</h2>
            <DataTable :value="ops" :loading="loadingOps" size="small" data-key="id">
                <template #empty>Aucune opération pour ce véhicule.</template>
                <Column field="date" header="Date" style="width: 7.5rem">
                    <template #body="{ data }">{{ formatDate(data.date) }}</template>
                </Column>
                <Column header="Libellé">
                    <template #body="{ data }">
                        <strong v-if="data.merchant">{{ data.merchant }}</strong>
                        <span class="text-muted" :title="data.rawLabel"> {{ data.rawLabel }}</span>
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
            <DataTable :value="readings" :loading="loadingReadings" size="small" data-key="id">
                <template #empty>Aucun relevé. Ajoutez le compteur actuel pour calculer les km parcourus.</template>
                <Column field="date" header="Date" style="width: 8rem">
                    <template #body="{ data }">{{ formatDate(data.date) }}</template>
                </Column>
                <Column field="km" header="Kilométrage" class="text-right" style="width: 10rem">
                    <template #body="{ data }">{{ data.km.toLocaleString('fr-FR') }} km</template>
                </Column>
                <Column field="note" header="Note" />
                <Column header="Actions" style="width: 7rem">
                    <template #body="{ data }">
                        <Button icon="pi pi-pencil" text rounded size="small" :aria-label="'Modifier le relevé du ' + formatDate(data.date)" @click="openReadingEdit(data)" />
                        <Button icon="pi pi-trash" text rounded size="small" severity="danger" :aria-label="'Supprimer le relevé du ' + formatDate(data.date)" @click="removeReading(data)" />
                    </template>
                </Column>
            </DataTable>
        </template>

        <div v-else-if="notFound" class="empty-state">
            <p>Véhicule introuvable dans ce foyer.</p>
            <p><RouterLink :to="{ name: 'vehicles' }">Retour à la liste des véhicules</RouterLink></p>
        </div>

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
.empty-state {
    text-align: center;
    color: var(--p-text-muted-color);
    padding: 2rem;
}
@media (max-width: 900px) {
    .charts-grid {
        grid-template-columns: minmax(0, 1fr);
    }
}
</style>
