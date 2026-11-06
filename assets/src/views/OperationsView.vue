<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import Button from 'primevue/button';
import InputText from 'primevue/inputtext';
import Select from 'primevue/select';
import Checkbox from 'primevue/checkbox';
import DatePicker from 'primevue/datepicker';
import Textarea from 'primevue/textarea';
import Dialog from 'primevue/dialog';
import Card from 'primevue/card';
import Tag from 'primevue/tag';
import { useToast } from 'primevue/usetoast';
import { useScopeStore } from '@/stores/scope';
import { useVehiclesStore } from '@/stores/vehicles';
import { bulkCategorize, listOperations, patchOperation } from '@/api/operations';
import { listRecurrences } from '@/api/recurrences';
import { assignOperationVehicle, bulkAssignVehicle } from '@/api/vehicles';
import { apiMessage } from '@/api/http';
import { centsToEuros, dateToIso, formatDate, formatDateTime, parseEuros } from '@/utils/format';
import MoneyAmount from '@/components/MoneyAmount.vue';
import CategoryTag from '@/components/CategoryTag.vue';
import CategoryPicker from '@/components/CategoryPicker.vue';
import SourceBadge from '@/components/SourceBadge.vue';
import VehicleTag from '@/components/VehicleTag.vue';
import VehiclePicker from '@/components/VehiclePicker.vue';
import CategorizeDialog from '@/components/CategorizeDialog.vue';

const route = useRoute();
const router = useRouter();
const scope = useScopeStore();
const vehiclesStore = useVehiclesStore();
// Colonne « Véhicule » seulement si le foyer a des véhicules.
const hasVehicles = computed(() => vehiclesStore.list.length > 0);
const toast = useToast();

// ---------- état ----------
const items = ref([]);
const total = ref(0);
const sums = ref({ debit: 0, credit: 0 });
const loading = ref(false);
const first = ref(0);
const rows = ref(25);
const sortField = ref('date');
const sortOrder = ref(-1);
const selection = ref([]);
const expandedRows = ref([]);
const notes = reactive({});
const savingNote = reactive({});
const vehicleEdits = reactive({}); // id opération → véhicule choisi (null = aucun)
const savingVehicle = reactive({});

// filtres (synchronisés avec la query string)
// Filtres repliés par défaut sur petit écran.
const showFilters = ref(!window.matchMedia?.('(max-width: 720px)').matches);

const filters = reactive({
    dateRange: null, // [Date|null, Date|null]
    from: null,
    to: null,
    categoryId: null,
    uncategorized: false,
    q: '',
    merchant: '',
    direction: null,
    minEuros: '',
    maxEuros: '',
    source: null,
    duplicateStatus: null,
    vehicle: null, // null | 'unassigned' | id de véhicule
    recurringSeriesId: null, // id de série récurrente (lien depuis l'écran Récurrences)
});

const directionOptions = [
    { label: 'Tous les sens', value: null },
    { label: 'Débits', value: 'debit' },
    { label: 'Crédits', value: 'credit' },
];
const sourceOptions = [
    { label: 'Toutes les sources', value: null },
    { label: 'Manuelle', value: 'manual' },
    { label: 'Règle', value: 'rule' },
    { label: 'Similarité', value: 'similarity' },
    { label: 'IA', value: 'llm' },
    { label: 'Virement', value: 'transfer' },
];
const duplicateOptions = [
    { label: 'Tous les doublons', value: null },
    { label: 'Probables', value: 'probable' },
    { label: 'Confirmés distincts', value: 'confirmed_distinct' },
];
const vehicleFilterOptions = computed(() => [
    { label: 'Tous les véhicules', value: null },
    { label: 'Non affectées (catégories véhicule)', value: 'unassigned' },
    ...vehiclesStore.list.map((v) => ({ label: v.archived ? `${v.name} (archivé)` : v.name, value: v.id })),
]);

// ---------- init depuis la query string ----------
function initFromQuery() {
    const q = route.query;
    if (q.from) filters.from = String(q.from);
    if (q.to) filters.to = String(q.to);
    if (q.from && q.to) {
        const [y1, m1, d1] = String(q.from).split('-').map(Number);
        const [y2, m2, d2] = String(q.to).split('-').map(Number);
        if (y1 && m1 && d1 && y2 && m2 && d2) filters.dateRange = [new Date(y1, m1 - 1, d1), new Date(y2, m2 - 1, d2)];
    }
    if (q.categoryId) filters.categoryId = Number(q.categoryId);
    filters.uncategorized = q.uncategorized === '1' || q.uncategorized === 'true';
    if (q.q) filters.q = String(q.q);
    if (q.merchant) filters.merchant = String(q.merchant);
    if (q.direction) filters.direction = String(q.direction);
    if (q.minAmount) filters.minEuros = centsToEuros(Number(q.minAmount));
    if (q.maxAmount) filters.maxEuros = centsToEuros(Number(q.maxAmount));
    if (q.source) filters.source = String(q.source);
    if (q.duplicateStatus) filters.duplicateStatus = String(q.duplicateStatus);
    if (q.vehicleId) filters.vehicle = Number(q.vehicleId);
    if (q.vehicleUnassigned === '1' || q.vehicleUnassigned === 'true') filters.vehicle = 'unassigned';
    if (q.recurringSeriesId) filters.recurringSeriesId = Number(q.recurringSeriesId);
    if (q.page) first.value = (Number(q.page) - 1) * rows.value;
    if (q.perPage) rows.value = Number(q.perPage);
    if (q.sort) sortField.value = String(q.sort);
    if (q.order === 'asc') sortOrder.value = 1;
}

function buildQuery() {
    const query = {};
    if (filters.from) query.from = filters.from;
    if (filters.to) query.to = filters.to;
    if (filters.categoryId) query.categoryId = filters.categoryId;
    if (filters.uncategorized) query.uncategorized = '1';
    if (filters.q.trim()) query.q = filters.q.trim();
    if (filters.merchant.trim()) query.merchant = filters.merchant.trim();
    if (filters.direction) query.direction = filters.direction;
    const min = parseEuros(filters.minEuros);
    const max = parseEuros(filters.maxEuros);
    if (min !== null) query.minAmount = Math.abs(min);
    if (max !== null) query.maxAmount = Math.abs(max);
    if (filters.source) query.source = filters.source;
    if (filters.duplicateStatus) query.duplicateStatus = filters.duplicateStatus;
    if (filters.vehicle === 'unassigned') query.vehicleUnassigned = '1';
    else if (filters.vehicle) query.vehicleId = filters.vehicle;
    if (filters.recurringSeriesId) query.recurringSeriesId = filters.recurringSeriesId;
    const page = Math.floor(first.value / rows.value) + 1;
    if (page > 1) query.page = page;
    if (rows.value !== 25) query.perPage = rows.value;
    if (sortField.value !== 'date') query.sort = sortField.value;
    if (sortOrder.value === 1) query.order = 'asc';
    return query;
}

function buildParams() {
    const params = {
        ...scope.params,
        page: Math.floor(first.value / rows.value) + 1,
        perPage: rows.value,
        sort: sortField.value || 'date',
        order: sortOrder.value === 1 ? 'asc' : 'desc',
    };
    if (filters.from) params.from = filters.from;
    if (filters.to) params.to = filters.to;
    if (filters.categoryId) params.categoryId = filters.categoryId;
    if (filters.uncategorized) params.uncategorized = 1;
    if (filters.q.trim()) params.q = filters.q.trim();
    if (filters.merchant.trim()) params.merchant = filters.merchant.trim();
    if (filters.direction) params.direction = filters.direction;
    const min = parseEuros(filters.minEuros);
    const max = parseEuros(filters.maxEuros);
    if (min !== null) params.minAmount = Math.abs(min);
    if (max !== null) params.maxAmount = Math.abs(max);
    if (filters.source) params.source = filters.source;
    if (filters.duplicateStatus) params.duplicateStatus = filters.duplicateStatus;
    if (filters.vehicle === 'unassigned') params.vehicleUnassigned = 1;
    else if (filters.vehicle) params.vehicleId = filters.vehicle;
    if (filters.recurringSeriesId) params.recurringSeriesId = filters.recurringSeriesId;
    return params;
}

// ---------- chargement ----------
async function load() {
    loading.value = true;
    router.replace({ name: 'operations', query: buildQuery() });
    try {
        const res = await listOperations(buildParams());
        items.value = res.items;
        total.value = res.total;
        sums.value = res.sums ?? { debit: 0, credit: 0 };
        for (const op of res.items) {
            notes[op.id] = op.note ?? '';
            vehicleEdits[op.id] = op.vehicle?.id ?? null;
        }
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Chargement impossible', detail: apiMessage(e, 'Les opérations n’ont pas pu être chargées.'), life: 6000 });
    } finally {
        loading.value = false;
    }
}

function onFilterChange() {
    first.value = 0;
    load();
}

function onPage(event) {
    first.value = event.first;
    rows.value = event.rows;
    load();
}

function onSort(event) {
    sortField.value = event.sortField || 'date';
    sortOrder.value = event.sortOrder ?? -1;
    first.value = 0;
    load();
}

// debounce 300 ms sur le texte et les montants
let debounceTimer = null;
function debouncedFilter() {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(onFilterChange, 300);
}

watch(() => filters.q, debouncedFilter);
watch(() => [filters.minEuros, filters.maxEuros], debouncedFilter);
watch(
    () => filters.dateRange,
    (range) => {
        filters.from = range?.[0] ? dateToIso(range[0]) : null;
        filters.to = range?.[1] ? dateToIso(range[1]) : null;
        onFilterChange();
    },
);
watch(() => scope.params, onFilterChange, { deep: true });

function resetFilters() {
    filters.dateRange = null;
    filters.from = null;
    filters.to = null;
    filters.categoryId = null;
    filters.uncategorized = false;
    filters.q = '';
    filters.direction = null;
    filters.minEuros = '';
    filters.maxEuros = '';
    filters.source = null;
    filters.duplicateStatus = null;
    filters.vehicle = null;
    filters.recurringSeriesId = null;
    onFilterChange();
}

// ---------- puce du filtre récurrence actif ----------
// Libellé « Récurrence : <merchant> » quand la série est retrouvée, sinon « Récurrence #id ».
const recurringFilterLabel = ref('');

async function loadRecurringFilterLabel() {
    if (!filters.recurringSeriesId) {
        recurringFilterLabel.value = '';
        return;
    }
    try {
        const res = await listRecurrences({ ...scope.params });
        const found = (res.items ?? []).find((r) => r.id === filters.recurringSeriesId);
        recurringFilterLabel.value = found?.merchant ? `Récurrence : ${found.merchant}` : `Récurrence #${filters.recurringSeriesId}`;
    } catch {
        recurringFilterLabel.value = `Récurrence #${filters.recurringSeriesId}`;
    }
}

function clearRecurringFilter() {
    filters.recurringSeriesId = null;
    recurringFilterLabel.value = '';
    onFilterChange();
}

// ---------- catégorisation ----------
const catVisible = ref(false);
const catOperation = ref(null);

function openCategorize(op) {
    catOperation.value = op;
    catVisible.value = true;
}

async function onCategorized(res) {
    if (res?.operation) {
        const found = items.value.find((o) => o.id === res.operation.id);
        if (found) Object.assign(found, res.operation);
    }
    load();
}

// ---------- catégorisation en masse ----------
const bulkVisible = ref(false);
const bulkCategoryId = ref(null);
const bulkLoading = ref(false);

const selectionCount = computed(() => selection.value.length);

function openBulk() {
    bulkCategoryId.value = null;
    bulkVisible.value = true;
}

async function applyBulk() {
    if (!selection.value.length || !bulkCategoryId.value) return;
    bulkLoading.value = true;
    try {
        const res = await bulkCategorize(selection.value.map((o) => o.id), bulkCategoryId.value);
        toast.add({ severity: 'success', summary: 'Catégorisation en masse', detail: `${res.updatedCount} opération(s) mises à jour.`, life: 5000 });
        bulkVisible.value = false;
        selection.value = [];
        load();
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Catégorisation en masse impossible', detail: apiMessage(e), life: 6000 });
    } finally {
        bulkLoading.value = false;
    }
}

// ---------- affectation véhicule (unitaire) ----------
async function saveVehicle(op) {
    savingVehicle[op.id] = true;
    try {
        // vehicleId null = retrait de l'affectation manuelle (resync auto ensuite).
        const updated = await assignOperationVehicle(op.id, vehicleEdits[op.id] ?? null);
        Object.assign(op, updated);
        toast.add({ severity: 'success', summary: 'Véhicule mis à jour', detail: updated.vehicle ? `Affectée à « ${updated.vehicle.name} ».` : 'Affectation retirée.', life: 3000 });
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Affectation impossible', detail: apiMessage(e), life: 6000 });
    } finally {
        savingVehicle[op.id] = false;
    }
}

// ---------- affectation véhicule en masse ----------
const bulkVehicleVisible = ref(false);
const bulkVehicleId = ref(null);
const bulkVehicleLoading = ref(false);

function openBulkVehicle() {
    bulkVehicleId.value = null;
    bulkVehicleVisible.value = true;
}

async function applyBulkVehicle() {
    if (!selection.value.length) return;
    bulkVehicleLoading.value = true;
    try {
        const res = await bulkAssignVehicle(selection.value.map((o) => o.id), bulkVehicleId.value);
        toast.add({ severity: 'success', summary: 'Affectation en masse', detail: `${res.updatedCount} opération(s) mises à jour.`, life: 5000 });
        bulkVehicleVisible.value = false;
        selection.value = [];
        load();
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Affectation en masse impossible', detail: apiMessage(e), life: 6000 });
    } finally {
        bulkVehicleLoading.value = false;
    }
}

// ---------- note ----------
async function saveNote(op) {
    savingNote[op.id] = true;
    try {
        const updated = await patchOperation(op.id, { note: notes[op.id]?.trim() ? notes[op.id].trim() : null });
        Object.assign(op, updated);
        toast.add({ severity: 'success', summary: 'Note enregistrée', life: 3000 });
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Note non enregistrée', detail: apiMessage(e), life: 6000 });
    } finally {
        savingNote[op.id] = false;
    }
}

// ---------- divers ----------
const showAccountColumn = computed(() => scope.current.type !== 'account');

onMounted(() => {
    initFromQuery();
    if (!vehiclesStore.loaded) vehiclesStore.refresh().catch(() => {});
    loadRecurringFilterLabel();
    load();
});
</script>

<template>
    <div>
        <div class="page-header">
            <h1>Opérations</h1>
            <Button
                :label="showFilters ? 'Masquer les filtres' : 'Filtres'"
                icon="pi pi-filter"
                text
                severity="secondary"
                :aria-expanded="showFilters"
                aria-controls="operations-filters"
                @click="showFilters = !showFilters"
            />
            <Button
                v-if="selectionCount"
                :label="`Catégoriser la sélection (${selectionCount})`"
                icon="pi pi-tags"
                severity="secondary"
                @click="openBulk"
            />
            <Button
                v-if="selectionCount"
                :label="`Affecter à un véhicule (${selectionCount})`"
                icon="pi pi-car"
                severity="secondary"
                outlined
                @click="openBulkVehicle"
            />
        </div>

        <div v-if="filters.recurringSeriesId" class="active-filters">
            <Tag
                :value="recurringFilterLabel || `Récurrence #${filters.recurringSeriesId}`"
                severity="info"
                icon="pi pi-replay"
                class="recurring-filter-tag"
                role="button"
                tabindex="0"
                :aria-label="'Retirer le filtre récurrence'"
                @click="clearRecurringFilter"
                @keydown.enter="clearRecurringFilter"
            >
                <template #default>
                    {{ recurringFilterLabel || `Récurrence #${filters.recurringSeriesId}` }}
                    <i class="pi pi-times tag-remove" aria-hidden="true" />
                </template>
            </Tag>
        </div>

        <Card v-show="showFilters" id="operations-filters" class="filters">
            <template #content>
                <div class="filters-grid">
                    <div class="field">
                        <label for="f-dates">Période</label>
                        <DatePicker
                            id="f-dates"
                            v-model="filters.dateRange"
                            selection-mode="range"
                            date-format="dd/mm/yy"
                            show-icon
                            placeholder="Du → au"
                            :manual-input="false"
                            class="w-full"
                        />
                    </div>
                    <div class="field">
                        <label for="f-cat">Catégorie</label>
                        <CategoryPicker id="f-cat" v-model="filters.categoryId" placeholder="Toutes les catégories" />
                    </div>
                    <div class="field field-check">
                        <Checkbox id="f-uncat" v-model="filters.uncategorized" binary @update:model-value="onFilterChange" />
                        <label for="f-uncat">Non catégorisées uniquement</label>
                    </div>
                    <div class="field">
                        <label for="f-q">Recherche</label>
                        <InputText id="f-q" v-model="filters.q" placeholder="Libellé ou commerçant…" class="w-full" />
                    </div>
                    <div class="field">
                        <label for="f-dir">Sens</label>
                        <Select
                            id="f-dir"
                            v-model="filters.direction"
                            :options="directionOptions"
                            option-label="label"
                            option-value="value"
                            class="w-full"
                            @update:model-value="onFilterChange"
                        />
                    </div>
                    <div class="field">
                        <label for="f-min">Montant min (€)</label>
                        <InputText id="f-min" v-model="filters.minEuros" inputmode="decimal" placeholder="0,00" class="w-full" />
                    </div>
                    <div class="field">
                        <label for="f-max">Montant max (€)</label>
                        <InputText id="f-max" v-model="filters.maxEuros" inputmode="decimal" placeholder="0,00" class="w-full" />
                    </div>
                    <div class="field">
                        <label for="f-src">Source</label>
                        <Select
                            id="f-src"
                            v-model="filters.source"
                            :options="sourceOptions"
                            option-label="label"
                            option-value="value"
                            class="w-full"
                            @update:model-value="onFilterChange"
                        />
                    </div>
                    <div class="field">
                        <label for="f-dup">Doublons</label>
                        <Select
                            id="f-dup"
                            v-model="filters.duplicateStatus"
                            :options="duplicateOptions"
                            option-label="label"
                            option-value="value"
                            class="w-full"
                            @update:model-value="onFilterChange"
                        />
                    </div>
                    <div class="field">
                        <label for="f-vehicle">Véhicule</label>
                        <Select
                            id="f-vehicle"
                            v-model="filters.vehicle"
                            :options="vehicleFilterOptions"
                            option-label="label"
                            option-value="value"
                            class="w-full"
                            @update:model-value="onFilterChange"
                        />
                    </div>
                    <div class="field field-reset">
                        <Button label="Réinitialiser" icon="pi pi-filter-slash" text severity="secondary" @click="resetFilters" />
                    </div>
                </div>
            </template>
        </Card>

        <DataTable
            :value="items"
            lazy
            paginator
            :rows="rows"
            :first="first"
            :total-records="total"
            :loading="loading"
            v-model:selection="selection"
            v-model:expanded-rows="expandedRows"
            data-key="id"
            sort-mode="single"
            removable-sort
            table-class="operations-table"
            :table-style="{ tableLayout: 'fixed', width: '100%', minWidth: '56rem' }"
            scrollable
            @page="onPage"
            @sort="onSort"
        >
            <Column selection-mode="multiple" style="width: 3rem" aria-label="Sélection multiple" />
            <Column expander style="width: 3rem" aria-label="Déplier la ligne" />
            <Column field="date" header="Date" sortable style="width: 7.5rem">
                <template #body="{ data }">{{ formatDate(data.date) }}</template>
            </Column>
            <Column v-if="showAccountColumn" field="account" header="Compte" style="width: 9rem">
                <template #body="{ data }">
                    <span :title="data.account?.numberMasked">{{ data.account?.alias ?? data.account?.numberMasked }}</span>
                </template>
            </Column>
            <Column field="merchant" header="Libellé" sortable>
                <template #body="{ data }">
                    <!-- Commerçant en 1re ligne, libellé brut en 2nde ligne (côte à côte, le commerçant
                         était tronqué à quelques lettres). -->
                    <div class="label-cell">
                        <div class="label-text">
                            <strong v-if="data.merchant" class="merchant" :title="data.merchant">{{ data.merchant }}</strong>
                            <span class="raw-label" :title="data.rawLabel">{{ data.rawLabel }}</span>
                        </div>
                        <i
                            v-if="data.labelSource"
                            class="pi pi-file"
                            v-tooltip.top="'Libellé extrait du ' + String(data.labelSource).toUpperCase()"
                            aria-hidden="true"
                        />
                        <i
                            v-if="data.enrichedAt"
                            class="pi pi-sparkles"
                            v-tooltip.top="'Libellé enrichi le ' + formatDateTime(data.enrichedAt)"
                            aria-hidden="true"
                        />
                    </div>
                </template>
            </Column>
            <Column field="category" header="Catégorie" style="width: 13rem">
                <template #body="{ data }">
                    <CategoryTag :category="data.category" clickable @click="openCategorize(data)" />
                </template>
            </Column>
            <Column v-if="hasVehicles" field="vehicle" header="Véhicule" style="width: 10rem">
                <template #body="{ data }">
                    <VehicleTag :vehicle="data.vehicle" :source="data.vehicleSource" />
                </template>
            </Column>
            <Column field="categorizationSource" header="Source" style="width: 7.5rem">
                <template #body="{ data }"><SourceBadge :source="data.categorizationSource" /></template>
            </Column>
            <Column field="amount" header="Montant" sortable style="width: 8.5rem" body-class="text-right">
                <template #body="{ data }"><MoneyAmount :amount="data.amount" /></template>
            </Column>

            <template #expansion="{ data }">
                <div class="op-detail">
                    <p><strong>Libellé brut :</strong> {{ data.rawLabel }}</p>
                    <p v-if="data.normalizedLabel">
                        <span class="text-muted">Libellé normalisé :</span> {{ data.normalizedLabel }}
                    </p>
                    <p v-if="data.merchant"><span class="text-muted">Commerçant :</span> {{ data.merchant }}</p>
                    <div class="note-block">
                        <label :for="'note-' + data.id">Note</label>
                        <Textarea :id="'note-' + data.id" v-model="notes[data.id]" rows="2" auto-resize class="w-full" />
                        <Button
                            size="small"
                            label="Enregistrer la note"
                            icon="pi pi-save"
                            :loading="!!savingNote[data.id]"
                            @click="saveNote(data)"
                        />
                    </div>
                    <div class="note-block">
                        <label :for="'vehicle-' + data.id">Véhicule (« Aucun » retire l'affectation)</label>
                        <div class="vehicle-row">
                            <VehiclePicker :id="'vehicle-' + data.id" v-model="vehicleEdits[data.id]" />
                            <Button
                                size="small"
                                label="Affecter"
                                icon="pi pi-car"
                                :loading="!!savingVehicle[data.id]"
                                @click="saveVehicle(data)"
                            />
                        </div>
                    </div>
                    <p v-if="data.duplicateStatus && data.duplicateStatus !== 'none'">
                        <i class="pi pi-clone" aria-hidden="true" />
                        Doublon {{ data.duplicateStatus === 'probable' ? 'probable' : 'confirmé distinct' }}
                        <template v-if="data.duplicateOfId">de l’opération #{{ data.duplicateOfId }}</template>
                        — <RouterLink :to="{ name: 'duplicates' }">contrôle des doublons</RouterLink>
                    </p>
                    <p v-if="data.internalTransferId">
                        <i class="pi pi-arrow-right-arrow-left" aria-hidden="true" />
                        Virement interne #{{ data.internalTransferId }} —
                        <RouterLink :to="{ name: 'transfers' }">contrôle des virements</RouterLink>
                    </p>
                </div>
            </template>

            <template #footer v-if="total > 0">
                <div class="sums">
                    <span>Total débits : <MoneyAmount :amount="sums.debit" /></span>
                    <span>Total crédits : <MoneyAmount :amount="sums.credit" /></span>
                    <span>Solde : <MoneyAmount :amount="(sums.debit ?? 0) + (sums.credit ?? 0)" /></span>
                </div>
            </template>
            <template #empty>
                <p class="empty-state">Aucune opération ne correspond aux filtres.</p>
            </template>
            <template #loading>
                <p class="empty-state">Chargement des opérations…</p>
            </template>
        </DataTable>

        <CategorizeDialog v-model:visible="catVisible" :operation="catOperation" @done="onCategorized" />

        <Dialog v-model:visible="bulkVehicleVisible" modal header="Affecter la sélection à un véhicule" :style="{ width: '30rem' }" :draggable="false">
            <div class="field">
                <label for="bulk-vehicle-picker">Véhicule pour les {{ selectionCount }} opérations sélectionnées (« Aucun » retire l'affectation)</label>
                <VehiclePicker id="bulk-vehicle-picker" v-model="bulkVehicleId" />
            </div>
            <template #footer>
                <Button label="Annuler" severity="secondary" text @click="bulkVehicleVisible = false" />
                <Button
                    label="Appliquer"
                    icon="pi pi-check"
                    :loading="bulkVehicleLoading"
                    @click="applyBulkVehicle"
                />
            </template>
        </Dialog>

        <Dialog v-model:visible="bulkVisible" modal header="Catégoriser la sélection" :style="{ width: '30rem' }" :draggable="false">
            <div class="field">
                <label for="bulk-picker">Catégorie pour les {{ selectionCount }} opérations sélectionnées</label>
                <CategoryPicker id="bulk-picker" v-model="bulkCategoryId" />
            </div>
            <template #footer>
                <Button label="Annuler" severity="secondary" text @click="bulkVisible = false" />
                <Button
                    label="Appliquer"
                    icon="pi pi-check"
                    :disabled="!bulkCategoryId"
                    :loading="bulkLoading"
                    @click="applyBulk"
                />
            </template>
        </Dialog>
    </div>
</template>

<style scoped>
.page-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    flex-wrap: wrap;
}
.filters {
    margin-bottom: 1rem;
}
.active-filters {
    display: flex;
    gap: 0.5rem;
    margin-bottom: 0.75rem;
}
.recurring-filter-tag {
    cursor: pointer;
}
.tag-remove {
    margin-left: 0.4rem;
    font-size: 0.75rem;
}
.filters-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(13rem, 1fr));
    gap: 0.75rem 1rem;
    align-items: end;
}
.field label {
    display: block;
    font-weight: 600;
    margin-bottom: 0.3rem;
    font-size: 0.9rem;
}
.field-check {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}
.field-check label {
    margin-bottom: 0;
    font-weight: 400;
}
.label-cell {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    min-width: 0;
    overflow: hidden;
}
.label-text {
    display: flex;
    flex-direction: column;
    min-width: 0;
    flex: 1 1 auto;
}
.label-cell .merchant {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.label-cell .raw-label {
    flex: 1 1 auto;
    min-width: 0;
}
.label-cell .raw-label {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    color: var(--p-text-muted-color);
    font-size: 0.85rem;
}
.op-detail {
    padding: 0.5rem 1rem;
}
.note-block {
    max-width: 40rem;
    margin: 0.5rem 0;
}
.note-block label {
    font-weight: 600;
    font-size: 0.9rem;
}
.vehicle-row {
    display: flex;
    gap: 0.5rem;
    align-items: center;
    margin-top: 0.3rem;
}
.vehicle-row .p-select {
    flex: 1 1 auto;
    min-width: 0;
}
.sums {
    display: flex;
    gap: 1.5rem;
    justify-content: flex-end;
    font-weight: 600;
}
.empty-state {
    text-align: center;
    color: var(--p-text-muted-color);
    padding: 1rem;
}
</style>
