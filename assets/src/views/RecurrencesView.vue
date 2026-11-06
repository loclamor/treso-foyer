<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import Button from 'primevue/button';
import Dialog from 'primevue/dialog';
import InputText from 'primevue/inputtext';
import Select from 'primevue/select';
import SelectButton from 'primevue/selectbutton';
import Checkbox from 'primevue/checkbox';
import Tag from 'primevue/tag';
import Card from 'primevue/card';
import { useToast } from 'primevue/usetoast';
import { useScopeStore } from '@/stores/scope';
import { useRecurrencesStore } from '@/stores/recurrences';
import { detectRecurrences, listRecurrences, updateRecurrence } from '@/api/recurrences';
import { getMonthly } from '@/api/stats';
import { apiMessage } from '@/api/http';
import { centsToEuros, formatDate, formatMoney, monthBounds, parseEuros } from '@/utils/format';
import CategoryTag from '@/components/CategoryTag.vue';

const scope = useScopeStore();
const recurrencesStore = useRecurrencesStore();
const toast = useToast();

// ---------- filtres ----------
// 'active' = detected + confirmed (défaut) ; sinon un statut unique.
const statusFilter = ref('active');
const statusOptions = computed(() => {
    const c = recurrencesStore.counts;
    return [
        { label: `Actives (${(c.detected ?? 0) + (c.confirmed ?? 0)})`, value: 'active' },
        { label: `Détectées (${c.detected ?? 0})`, value: 'detected' },
        { label: `Confirmées (${c.confirmed ?? 0})`, value: 'confirmed' },
        { label: `Ignorées (${c.ignored ?? 0})`, value: 'ignored' },
        { label: `Terminées (${c.ended ?? 0})`, value: 'ended' },
    ];
});
const directionFilter = ref(null); // null | 'expense' | 'income'
const directionOptions = [
    { label: 'Toutes', value: null },
    { label: 'Dépenses', value: 'expense' },
    { label: 'Revenus', value: 'income' },
];

function statusParam() {
    return statusFilter.value === 'active' ? 'detected,confirmed' : statusFilter.value;
}

function statusInCurrentFilter(status) {
    if (statusFilter.value === 'active') return status === 'detected' || status === 'confirmed';
    return status === statusFilter.value;
}

// ---------- chargement ----------
const loading = ref(false);

async function load() {
    loading.value = true;
    try {
        const params = { ...scope.params, status: statusParam() };
        if (directionFilter.value) params.direction = directionFilter.value;
        const res = await listRecurrences(params);
        recurrencesStore.items = res.items ?? [];
        recurrencesStore.counts = res.counts ?? { detected: 0, confirmed: 0, ignored: 0, ended: 0 };
        recurrencesStore.loaded = true;
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Récurrences illisibles', detail: apiMessage(e), life: 6000 });
    } finally {
        loading.value = false;
    }
}

watch(() => [statusFilter.value, directionFilter.value], load);
watch(() => scope.params, load, { deep: true });

// ---------- relancer la détection ----------
const detecting = ref(false);

async function runDetection() {
    detecting.value = true;
    try {
        const res = await detectRecurrences();
        toast.add({
            severity: 'success',
            summary: 'Détection relancée',
            detail: `${res.created} créée(s) · ${res.updated} mises à jour · ${res.ended} terminée(s) · ${res.removed ?? 0} retirée(s) · ${res.linked} opérations rattachées.`,
            life: 8000,
        });
        await load();
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Détection impossible', detail: apiMessage(e), life: 6000 });
    } finally {
        detecting.value = false;
    }
}

// ---------- bandeau « Ce mois-ci » (estimate.recurring de stats/monthly) ----------
const monthEstimate = ref(null);
const loadingMonth = ref(false);

async function loadMonth() {
    loadingMonth.value = true;
    try {
        const now = new Date();
        const ym = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;
        const res = await getMonthly(scope.params, monthBounds(ym));
        monthEstimate.value = res.estimate ?? null;
    } catch {
        monthEstimate.value = null; // bandeau masqué si l'estimation est indisponible
    } finally {
        loadingMonth.value = false;
    }
}

watch(() => scope.params, loadMonth, { deep: true });

// Échéances du mois en cours : non payées d'abord, puis payées (grisées avec coche), triées par date.
const monthItems = computed(() => {
    const est = monthEstimate.value;
    if (!est?.recurring) return [];
    const exp = (est.recurring.expense?.items ?? []).map((it) => ({ ...it, direction: 'expense' }));
    const inc = (est.recurring.income?.items ?? []).map((it) => ({ ...it, direction: 'income' }));
    return [...exp, ...inc].sort((a, b) => Number(a.paid) - Number(b.paid) || String(a.expectedDate).localeCompare(String(b.expectedDate)));
});

const monthExpenseRemaining = computed(() => monthEstimate.value?.recurring?.expense?.remaining ?? 0);
const monthIncomeRemaining = computed(() => monthEstimate.value?.recurring?.income?.remaining ?? 0);

// ---------- actions de statut ----------
const STATUS_SEVERITY = { detected: 'info', confirmed: 'success', ignored: 'secondary', ended: 'contrast' };

async function applyUpdated(updated) {
    const found = recurrencesStore.items.find((r) => r.id === updated.id);
    if (found) Object.assign(found, updated);
    // la série sort du filtre courant (ex. « Ignorer » depuis « Actives ») ⇒ on la retire de la liste
    if (!statusInCurrentFilter(updated.status)) {
        recurrencesStore.items = recurrencesStore.items.filter((r) => r.id !== updated.id);
    }
    try {
        await recurrencesStore.refreshCounts(scope.params);
    } catch {
        // compteurs inchangés si l'appel échoue
    }
}

async function setStatus(rec, status) {
    try {
        const updated = await updateRecurrence(rec.id, { status });
        await applyUpdated(updated);
        toast.add({ severity: 'success', summary: `Récurrence ${updated.statusLabel?.toLowerCase() ?? ''}`, detail: rec.merchant, life: 3000 });
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Modification impossible', detail: apiMessage(e), life: 6000 });
    }
}

// ---------- dialogue d'édition ----------
const FREQUENCY_OPTIONS = [
    { label: 'Hebdomadaire', value: 'weekly' },
    { label: 'Mensuelle', value: 'monthly' },
    { label: 'Tous les 2 mois', value: 'bimonthly' },
    { label: 'Trimestrielle', value: 'quarterly' },
    { label: 'Semestrielle', value: 'semiannual' },
    { label: 'Annuelle', value: 'yearly' },
];
const MONTH_LABELS = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];

const dialogVisible = ref(false);
const editing = ref(null);
const saving = ref(false);
const form = reactive({
    expectedAmountEuros: '',
    amountToleranceEuros: '',
    frequency: 'monthly',
    allMonths: true,
    months: [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12],
});

function openEdit(rec) {
    editing.value = rec;
    Object.assign(form, {
        expectedAmountEuros: centsToEuros(rec.expectedAmount),
        amountToleranceEuros: centsToEuros(rec.amountTolerance),
        frequency: rec.frequency,
        allMonths: rec.activeMonths == null,
        months: rec.activeMonths ?? [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12],
    });
    dialogVisible.value = true;
}

function toggleMonth(m) {
    const i = form.months.indexOf(m);
    if (i === -1) form.months.push(m);
    else form.months.splice(i, 1);
    form.months.sort((a, b) => a - b);
}

async function submitEdit() {
    const amount = parseEuros(form.expectedAmountEuros);
    const tolerance = parseEuros(form.amountToleranceEuros);
    if (amount === null || amount <= 0) {
        toast.add({ severity: 'warn', summary: 'Montant invalide', detail: 'Saisissez un montant attendu positif en euros.', life: 5000 });
        return;
    }
    if (!form.allMonths && !form.months.length) {
        toast.add({ severity: 'warn', summary: 'Mois actifs invalides', detail: 'Cochez au moins un mois ou « Tous les mois ».', life: 5000 });
        return;
    }
    saving.value = true;
    try {
        const updated = await updateRecurrence(editing.value.id, {
            expectedAmount: Math.abs(amount), // centimes
            amountTolerance: tolerance === null ? 0 : Math.abs(tolerance),
            frequency: form.frequency,
            activeMonths: form.allMonths ? null : [...form.months],
        });
        await applyUpdated(updated);
        dialogVisible.value = false;
        toast.add({ severity: 'success', summary: 'Récurrence enregistrée', detail: updated.merchant, life: 3000 });
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Enregistrement impossible', detail: apiMessage(e), life: 6000 });
    } finally {
        saving.value = false;
    }
}

// ---------- affichage ----------
function frequencyText(rec) {
    if (!rec.activeMonths?.length) return rec.frequencyLabel ?? rec.frequency;
    return `${rec.frequencyLabel ?? rec.frequency} (${rec.activeMonths.length} mois)`;
}

function activeMonthsTooltip(rec) {
    if (!rec.activeMonths?.length) return '';
    return rec.activeMonths.map((m) => MONTH_LABELS[m - 1] ?? m).join(', ');
}

const DIRECTION_LABELS = { expense: 'Dépense', income: 'Revenu' };
const DIRECTION_SEVERITY = { expense: 'danger', income: 'success' };

onMounted(() => {
    load();
    loadMonth();
});
</script>

<template>
    <div>
        <div class="page-header">
            <h1>Récurrences</h1>
            <div class="header-tools">
                <Button
                    label="Relancer la détection"
                    icon="pi pi-refresh"
                    severity="secondary"
                    outlined
                    :loading="detecting"
                    @click="runDetection"
                />
            </div>
        </div>

        <Card v-if="monthEstimate" class="month-banner">
            <template #title>Ce mois-ci</template>
            <template #content>
                <div class="month-totals">
                    <span>
                        Dépenses récurrentes restantes :
                        <strong class="amount-debit">{{ formatMoney(monthExpenseRemaining) }}</strong>
                    </span>
                    <span>
                        Revenus récurrents attendus :
                        <strong class="amount-credit">{{ formatMoney(monthIncomeRemaining) }}</strong>
                    </span>
                </div>
                <ul v-if="monthItems.length" class="month-items" aria-label="Échéances du mois en cours">
                    <li v-for="(it, i) in monthItems" :key="`${it.seriesId}-${i}`" :class="{ paid: it.paid }">
                        <i v-if="it.paid" class="pi pi-check" aria-hidden="true" />
                        <span class="item-date">{{ formatDate(it.expectedDate) }}</span>
                        <span class="item-merchant">{{ it.merchant }}</span>
                        <span :class="it.direction === 'income' ? 'amount-credit' : 'amount-debit'">{{ formatMoney(it.amount) }}</span>
                    </li>
                </ul>
                <p v-else class="text-muted">Aucune échéance récurrente ce mois-ci.</p>
            </template>
        </Card>

        <div class="filter-row">
            <SelectButton v-model="statusFilter" :options="statusOptions" option-label="label" option-value="value" aria-label="Filtrer par statut" />
            <SelectButton v-model="directionFilter" :options="directionOptions" option-label="label" option-value="value" aria-label="Filtrer par sens" />
        </div>

        <DataTable
            :value="recurrencesStore.items"
            :loading="loading"
            data-key="id"
            sort-mode="single"
            removable-sort
            table-class="recurrences-table"
            :table-style="{ minWidth: '56rem' }"
            scrollable
        >
            <!-- Mise en page automatique (largeurs fixes cumulées > largeur de la carte ⇒ colonnes
                 écrasées) : libellé + compte dans la 1re colonne, sens porté par la couleur du montant. -->
            <Column field="merchant" header="Libellé" sortable>
                <template #body="{ data }">
                    <div class="merchant-cell">
                        <strong class="merchant-name" :title="data.merchant">{{ data.merchant }}</strong>
                        <small class="text-muted">
                            {{ data.account?.alias ?? '—' }} ·
                            <RouterLink :to="{ name: 'operations', query: { recurringSeriesId: data.id } }">{{ data.occurrences }} opération{{ data.occurrences > 1 ? 's' : '' }}</RouterLink>
                        </small>
                    </div>
                </template>
            </Column>
            <Column header="Catégorie">
                <template #body="{ data }"><CategoryTag :category="data.category" /></template>
            </Column>
            <Column header="Fréquence" style="white-space: nowrap">
                <template #body="{ data }">
                    <span v-tooltip.top="activeMonthsTooltip(data) || undefined">{{ frequencyText(data) }}</span>
                </template>
            </Column>
            <Column field="expectedAmount" header="Montant attendu" sortable style="white-space: nowrap" body-class="text-right">
                <template #body="{ data }">
                    <span :class="['money', data.direction === 'income' ? 'amount-credit' : 'amount-debit']" v-tooltip.top="DIRECTION_LABELS[data.direction]">
                        {{ data.direction === 'income' ? '+' : '−' }}{{ formatMoney(data.expectedAmount) }}
                    </span>
                    <br />
                    <small v-if="data.amountTolerance" class="text-muted">± {{ formatMoney(data.amountTolerance) }}</small>
                </template>
            </Column>
            <Column field="nextExpected" header="Prochaine" sortable style="white-space: nowrap">
                <template #body="{ data }">
                    {{ formatDate(data.nextExpected) }}
                    <Tag v-if="data.overdue" value="En retard" severity="danger" class="overdue-tag" />
                    <br />
                    <small class="text-muted">dernière {{ formatDate(data.lastOccurrence) }}</small>
                </template>
            </Column>
            <Column field="status" header="Statut" sortable style="white-space: nowrap">
                <template #body="{ data }">
                    <Tag :value="data.statusLabel ?? data.status" :severity="STATUS_SEVERITY[data.status] ?? 'secondary'" />
                </template>
            </Column>
            <Column header="Actions" style="white-space: nowrap">
                <template #body="{ data }">
                    <div class="row-actions">
                        <Button v-if="data.status === 'detected'" icon="pi pi-check" text rounded size="small" aria-label="Confirmer" v-tooltip.top="'Confirmer'" @click="setStatus(data, 'confirmed')" />
                        <Button v-if="['detected', 'confirmed', 'ended'].includes(data.status)" icon="pi pi-eye-slash" text rounded size="small" severity="secondary" aria-label="Ignorer" v-tooltip.top="'Ignorer'" @click="setStatus(data, 'ignored')" />
                        <Button v-if="['detected', 'confirmed'].includes(data.status)" icon="pi pi-stop" text rounded size="small" severity="secondary" aria-label="Terminer" v-tooltip.top="'Terminer'" @click="setStatus(data, 'ended')" />
                        <Button v-if="['ignored', 'ended'].includes(data.status)" icon="pi pi-replay" text rounded size="small" severity="secondary" aria-label="Réactiver" v-tooltip.top="'Réactiver'" @click="setStatus(data, 'detected')" />
                        <Button icon="pi pi-pencil" text rounded size="small" aria-label="Modifier" v-tooltip.top="'Modifier'" @click="openEdit(data)" />
                    </div>
                </template>
            </Column>
            <template #empty>
                <div class="empty-state">
                    <p>Aucune récurrence dans ce filtre.</p>
                    <p class="text-muted">
                        Les récurrences sont détectées automatiquement après chaque import d'opérations.
                    </p>
                    <Button label="Relancer la détection" icon="pi pi-refresh" size="small" outlined severity="secondary" :loading="detecting" @click="runDetection" />
                </div>
            </template>
        </DataTable>

        <Dialog
            v-model:visible="dialogVisible"
            modal
            header="Modifier la récurrence"
            :style="{ width: '32rem' }"
            :draggable="false"
        >
            <div class="form" v-if="editing">
                <p class="text-muted">{{ editing.merchant }} — {{ editing.frequencyLabel ?? editing.frequency }}</p>
                <div class="form-row">
                    <div class="field">
                        <label for="rec-amount">Montant attendu (€)</label>
                        <InputText id="rec-amount" v-model="form.expectedAmountEuros" inputmode="decimal" placeholder="0,00" class="w-full" />
                    </div>
                    <div class="field">
                        <label for="rec-tol">Tolérance (€)</label>
                        <InputText id="rec-tol" v-model="form.amountToleranceEuros" inputmode="decimal" placeholder="0,00" class="w-full" />
                    </div>
                </div>
                <div class="field">
                    <label for="rec-freq">Fréquence</label>
                    <Select id="rec-freq" v-model="form.frequency" :options="FREQUENCY_OPTIONS" option-label="label" option-value="value" class="w-full" />
                </div>
                <div class="field">
                    <div class="field-check">
                        <Checkbox id="rec-all-months" v-model="form.allMonths" binary />
                        <label for="rec-all-months">Tous les mois</label>
                    </div>
                    <div v-if="!form.allMonths" class="months-grid">
                        <div v-for="m in 12" :key="m" class="field-check">
                            <Checkbox :id="`rec-month-${m}`" :model-value="form.months.includes(m)" binary @update:model-value="toggleMonth(m)" />
                            <label :for="`rec-month-${m}`">{{ MONTH_LABELS[m - 1] }}</label>
                        </div>
                    </div>
                </div>
                <small class="text-muted">
                    Modifier le montant, la fréquence ou les mois d'une série « détectée » la passe automatiquement en « confirmée ».
                </small>
            </div>
            <template #footer>
                <Button label="Annuler" severity="secondary" text @click="dialogVisible = false" />
                <Button label="Enregistrer" icon="pi pi-check" :loading="saving" @click="submitEdit" />
            </template>
        </Dialog>
    </div>
</template>

<style scoped>
.merchant-cell {
    display: flex;
    flex-direction: column;
    max-width: 13rem;
}
.merchant-name {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.page-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    flex-wrap: wrap;
}
.header-tools {
    display: flex;
    gap: 0.6rem;
    flex-wrap: wrap;
}
.month-banner {
    margin-bottom: 1rem;
}
.month-totals {
    display: flex;
    gap: 1.5rem;
    flex-wrap: wrap;
    font-size: 0.95rem;
}
.month-items {
    list-style: none;
    margin: 0.75rem 0 0;
    padding: 0;
    columns: 2;
    column-gap: 2rem;
}
.month-items li {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.15rem 0;
    break-inside: avoid;
    font-size: 0.9rem;
}
.month-items li.paid {
    color: var(--p-text-muted-color);
    text-decoration: line-through;
}
.month-items .pi-check {
    text-decoration: none;
    color: var(--p-green-500);
}
.item-date {
    flex: none;
    font-variant-numeric: tabular-nums;
}
.item-merchant {
    flex: 1 1 auto;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.filter-row {
    display: flex;
    gap: 1rem;
    flex-wrap: wrap;
    margin-bottom: 1rem;
}
.row-actions {
    display: flex;
    gap: 0.15rem;
}
.overdue-tag {
    margin-left: 0.4rem;
    font-size: 0.7rem;
}
.empty-state {
    text-align: center;
    padding: 1rem;
}
.empty-state .text-muted {
    margin-bottom: 0.75rem;
}
.form .field {
    margin-bottom: 1rem;
}
.form label {
    display: block;
    font-weight: 600;
    margin-bottom: 0.3rem;
}
.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
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
.months-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 0.4rem 1rem;
    margin-top: 0.6rem;
}
@media (max-width: 720px) {
    .month-items {
        columns: 1;
    }
}
</style>
