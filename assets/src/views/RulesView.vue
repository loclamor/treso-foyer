<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import Button from 'primevue/button';
import Dialog from 'primevue/dialog';
import InputText from 'primevue/inputtext';
import InputNumber from 'primevue/inputnumber';
import Select from 'primevue/select';
import ToggleSwitch from 'primevue/toggleswitch';
import Checkbox from 'primevue/checkbox';
import { useToast } from 'primevue/usetoast';
import { useConfirm } from 'primevue/useconfirm';
import { useAccountsStore } from '@/stores/accounts';
import { createRule, deleteRule, listRules, previewRule, runCategorization, updateRule } from '@/api/rules';
import { apiMessage } from '@/api/http';
import { centsToEuros, formatDate, parseEuros } from '@/utils/format';
import CategoryTag from '@/components/CategoryTag.vue';
import CategoryPicker from '@/components/CategoryPicker.vue';
import MoneyAmount from '@/components/MoneyAmount.vue';

const toast = useToast();
const confirm = useConfirm();
const accountsStore = useAccountsStore();

const rules = ref([]);
const loading = ref(false);

const FIELD_LABELS = { merchant: 'Commerçant', label: 'Libellé' };
const TYPE_LABELS = { contains: 'Contient', starts_with: 'Commence par', exact: 'Égal à', regex: 'Expression régulière' };
const DIRECTION_LABELS = { any: 'Tous', debit: 'Débit', credit: 'Crédit' };
const FIELD_OPTIONS = [
    { label: 'Commerçant', value: 'merchant' },
    { label: 'Libellé', value: 'label' },
];
const TYPE_OPTIONS = [
    { label: 'Contient', value: 'contains' },
    { label: 'Commence par', value: 'starts_with' },
    { label: 'Égal à', value: 'exact' },
    { label: 'Expression régulière', value: 'regex' },
];
const DIRECTION_OPTIONS = [
    { label: 'Tous les sens', value: 'any' },
    { label: 'Débits', value: 'debit' },
    { label: 'Crédits', value: 'credit' },
];

async function load() {
    loading.value = true;
    try {
        rules.value = await listRules();
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Règles illisibles', detail: apiMessage(e), life: 6000 });
    } finally {
        loading.value = false;
    }
}

async function toggleEnabled(rule) {
    const previous = rule.enabled;
    try {
        await updateRule(rule.id, { enabled: rule.enabled });
    } catch (e) {
        rule.enabled = previous;
        toast.add({ severity: 'error', summary: 'Modification impossible', detail: apiMessage(e), life: 6000 });
    }
}

function remove(rule) {
    confirm.require({
        message: `Supprimer la règle « ${rule.pattern} » ? Les opérations qu’elle a catégorisées seront recatégorisées automatiquement.`,
        header: 'Suppression',
        icon: 'pi pi-exclamation-triangle',
        acceptLabel: 'Supprimer',
        rejectLabel: 'Annuler',
        acceptClass: 'p-button-danger',
        accept: async () => {
            try {
                await deleteRule(rule.id);
                toast.add({ severity: 'success', summary: 'Règle supprimée', life: 3000 });
                load();
            } catch (e) {
                toast.add({ severity: 'error', summary: 'Suppression impossible', detail: apiMessage(e), life: 6000 });
            }
        },
    });
}

// ---------- formulaire règle ----------
const dialogVisible = ref(false);
const dialogMode = ref('create');
const editingId = ref(null);
const saving = ref(false);
const form = reactive({
    categoryId: null,
    matchField: 'merchant',
    matchType: 'contains',
    pattern: '',
    direction: 'any',
    amountMinEuros: '',
    amountMaxEuros: '',
    accountId: null,
    priority: 100,
    enabled: true,
});

// aperçu en direct
const preview = ref(null);
const previewLoading = ref(false);
let previewTimer = null;

function formPayload() {
    const min = parseEuros(form.amountMinEuros);
    const max = parseEuros(form.amountMaxEuros);
    return {
        categoryId: form.categoryId,
        matchField: form.matchField,
        matchType: form.matchType,
        pattern: form.pattern,
        direction: form.direction,
        amountMin: min === null ? null : Math.abs(min),
        amountMax: max === null ? null : Math.abs(max),
        accountId: form.accountId,
        priority: form.priority,
        enabled: form.enabled,
    };
}

function canPreview() {
    return !!form.categoryId && !!form.pattern.trim();
}

watch(
    () => [form.categoryId, form.matchField, form.matchType, form.pattern, form.direction, form.amountMinEuros, form.amountMaxEuros, form.accountId],
    () => {
        if (!dialogVisible.value || !canPreview()) {
            preview.value = null;
            return;
        }
        clearTimeout(previewTimer);
        previewTimer = setTimeout(async () => {
            previewLoading.value = true;
            try {
                preview.value = await previewRule(formPayload());
            } catch {
                preview.value = null;
            } finally {
                previewLoading.value = false;
            }
        }, 400);
    },
);

function openCreate() {
    dialogMode.value = 'create';
    editingId.value = null;
    Object.assign(form, {
        categoryId: null, matchField: 'merchant', matchType: 'contains', pattern: '',
        direction: 'any', amountMinEuros: '', amountMaxEuros: '', accountId: null, priority: 100, enabled: true,
    });
    preview.value = null;
    dialogVisible.value = true;
}

function openEdit(rule) {
    dialogMode.value = 'edit';
    editingId.value = rule.id;
    Object.assign(form, {
        categoryId: rule.category?.id ?? null,
        matchField: rule.matchField,
        matchType: rule.matchType,
        pattern: rule.pattern,
        direction: rule.direction ?? 'any',
        amountMinEuros: rule.amountMin != null ? centsToEuros(rule.amountMin) : '',
        amountMaxEuros: rule.amountMax != null ? centsToEuros(rule.amountMax) : '',
        accountId: rule.account?.id ?? null,
        priority: rule.priority,
        enabled: rule.enabled,
    });
    preview.value = null;
    dialogVisible.value = true;
}

async function submit() {
    if (!canPreview()) return;
    saving.value = true;
    try {
        if (dialogMode.value === 'create') {
            await createRule(formPayload());
            toast.add({ severity: 'success', summary: 'Règle créée', life: 3000 });
        } else {
            await updateRule(editingId.value, formPayload());
            toast.add({ severity: 'success', summary: 'Règle mise à jour', life: 3000 });
        }
        dialogVisible.value = false;
        load();
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Enregistrement impossible', detail: apiMessage(e, 'La règle n’a pas pu être enregistrée.'), life: 6000 });
    } finally {
        saving.value = false;
    }
}

// ---------- relancer la catégorisation ----------
const runVisible = ref(false);
const runAll = ref(false);
const running = ref(false);

async function run() {
    running.value = true;
    try {
        const res = await runCategorization(runAll.value);
        toast.add({
            severity: 'success',
            summary: 'Catégorisation relancée',
            detail: `${res.processed} opérations traitées · ${res.byRule} par règle · ${res.bySimilarity} par similarité · ${res.stillUncategorized} toujours non catégorisées (${res.categorizedPercent} %).`,
            life: 8000,
        });
        runVisible.value = false;
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Relance impossible', detail: apiMessage(e), life: 6000 });
    } finally {
        running.value = false;
    }
}

const accountOptions = computed(() => accountsStore.list.map((a) => ({ label: `${a.alias} — ${a.numberMasked}`, value: a.id })));

onMounted(async () => {
    load();
    if (!accountsStore.loaded) await accountsStore.refresh().catch(() => {});
});
</script>

<template>
    <div>
        <div class="page-header">
            <h1>Règles de catégorisation</h1>
            <div class="header-tools">
                <Button label="Relancer la catégorisation" icon="pi pi-refresh" severity="secondary" outlined @click="runVisible = true" />
                <Button label="Nouvelle règle" icon="pi pi-plus" @click="openCreate" />
            </div>
        </div>

        <DataTable :value="rules" :loading="loading" data-key="id" :empty-message="'Aucune règle'">
            <Column field="priority" header="Priorité" sortable style="width: 7rem" />
            <Column header="Catégorie" style="width: 12rem">
                <template #body="{ data }"><CategoryTag :category="data.category" /></template>
            </Column>
            <Column header="Champ" style="width: 9rem">
                <template #body="{ data }">{{ FIELD_LABELS[data.matchField] ?? data.matchField }}</template>
            </Column>
            <Column header="Type" style="width: 11rem">
                <template #body="{ data }">{{ TYPE_LABELS[data.matchType] ?? data.matchType }}</template>
            </Column>
            <Column field="pattern" header="Motif">
                <template #body="{ data }"><code class="pattern" :title="data.pattern">{{ data.pattern }}</code></template>
            </Column>
            <Column header="Sens" style="width: 7rem">
                <template #body="{ data }">{{ DIRECTION_LABELS[data.direction] ?? data.direction }}</template>
            </Column>
            <Column header="Montant min" style="width: 9rem">
                <template #body="{ data }"><MoneyAmount v-if="data.amountMin != null" :amount="data.amountMin" /><span v-else class="text-muted">—</span></template>
            </Column>
            <Column header="Montant max" style="width: 9rem">
                <template #body="{ data }"><MoneyAmount v-if="data.amountMax != null" :amount="data.amountMax" /><span v-else class="text-muted">—</span></template>
            </Column>
            <Column header="Compte" style="width: 9rem">
                <template #body="{ data }">{{ data.account?.numberMasked ?? '—' }}</template>
            </Column>
            <Column header="Activée" style="width: 6rem">
                <template #body="{ data }">
                    <ToggleSwitch v-model="data.enabled" :aria-label="'Activer/désactiver la règle ' + data.pattern" @update:model-value="toggleEnabled(data)" />
                </template>
            </Column>
            <Column field="matchCount" header="Appliquée à" style="width: 8rem" />
            <Column header="Actions" style="width: 7rem">
                <template #body="{ data }">
                    <Button icon="pi pi-pencil" text rounded size="small" :aria-label="'Modifier la règle ' + data.pattern" @click="openEdit(data)" />
                    <Button icon="pi pi-trash" text rounded size="small" severity="danger" :aria-label="'Supprimer la règle ' + data.pattern" @click="remove(data)" />
                </template>
            </Column>
        </DataTable>

        <Dialog
            v-model:visible="dialogVisible"
            modal
            :header="dialogMode === 'create' ? 'Nouvelle règle' : 'Modifier la règle'"
            :style="{ width: '36rem' }"
            :draggable="false"
        >
            <div class="form">
                <div class="field">
                    <label for="rule-cat">Catégorie cible</label>
                    <CategoryPicker id="rule-cat" v-model="form.categoryId" />
                </div>
                <div class="form-row">
                    <div class="field">
                        <label for="rule-field">Champ</label>
                        <Select id="rule-field" v-model="form.matchField" :options="FIELD_OPTIONS" option-label="label" option-value="value" class="w-full" />
                    </div>
                    <div class="field">
                        <label for="rule-type">Type de correspondance</label>
                        <Select id="rule-type" v-model="form.matchType" :options="TYPE_OPTIONS" option-label="label" option-value="value" class="w-full" />
                    </div>
                </div>
                <div class="field">
                    <label for="rule-pattern">Motif</label>
                    <InputText id="rule-pattern" v-model="form.pattern" class="w-full" placeholder="ex. INTERMARCHE" />
                </div>
                <div class="form-row">
                    <div class="field">
                        <label for="rule-dir">Sens</label>
                        <Select id="rule-dir" v-model="form.direction" :options="DIRECTION_OPTIONS" option-label="label" option-value="value" class="w-full" />
                    </div>
                    <div class="field">
                        <label for="rule-prio">Priorité</label>
                        <InputNumber id="rule-prio" v-model="form.priority" :use-grouping="false" :min-fraction-digits="0" :max-fraction-digits="0" class="w-full" />
                    </div>
                </div>
                <div class="form-row">
                    <div class="field">
                        <label for="rule-min">Montant min (€)</label>
                        <InputText id="rule-min" v-model="form.amountMinEuros" inputmode="decimal" placeholder="illimité" class="w-full" />
                    </div>
                    <div class="field">
                        <label for="rule-max">Montant max (€)</label>
                        <InputText id="rule-max" v-model="form.amountMaxEuros" inputmode="decimal" placeholder="illimité" class="w-full" />
                    </div>
                </div>
                <div class="field">
                    <label for="rule-account">Compte (optionnel)</label>
                    <Select
                        id="rule-account"
                        v-model="form.accountId"
                        :options="accountOptions"
                        option-label="label"
                        option-value="value"
                        show-clear
                        placeholder="Tous les comptes"
                        class="w-full"
                    />
                </div>
                <div class="field field-check">
                    <ToggleSwitch id="rule-enabled" v-model="form.enabled" />
                    <label for="rule-enabled">Règle activée</label>
                </div>

                <div v-if="canPreview()" class="preview">
                    <h3>Aperçu</h3>
                    <p v-if="previewLoading" class="text-muted"><i class="pi pi-spin pi-spinner" aria-hidden="true" /> Calcul…</p>
                    <template v-else-if="preview">
                        <p>
                            <strong>{{ preview.matchCount }}</strong> opération(s) correspondante(s) ·
                            <strong>{{ preview.conflicts }}</strong> déjà catégorisées autrement ·
                            <strong>{{ preview.manualCount ?? 0 }}</strong> catégorisées manuellement
                        </p>
                        <ul v-if="preview.sample?.length" class="sample">
                            <li v-for="op in preview.sample" :key="op.id">
                                {{ formatDate(op.date) }} — <MoneyAmount :amount="op.amount" /> —
                                <strong v-if="op.merchant">{{ op.merchant }}</strong> {{ op.rawLabel }}
                            </li>
                        </ul>
                    </template>
                </div>
            </div>
            <template #footer>
                <Button label="Annuler" severity="secondary" text @click="dialogVisible = false" />
                <Button label="Enregistrer" icon="pi pi-check" :loading="saving" :disabled="!canPreview()" @click="submit" />
            </template>
        </Dialog>

        <Dialog v-model:visible="runVisible" modal header="Relancer la catégorisation" :style="{ width: '26rem' }" :draggable="false">
            <div class="field-check">
                <Checkbox id="run-all" v-model="runAll" binary />
                <label for="run-all">Tout recalculer (y compris les opérations déjà catégorisées automatiquement)</label>
            </div>
            <template #footer>
                <Button label="Annuler" severity="secondary" text @click="runVisible = false" />
                <Button label="Relancer" icon="pi pi-refresh" :loading="running" @click="run" />
            </template>
        </Dialog>
    </div>
</template>

<style scoped>
.pattern {
    display: inline-block;
    max-width: 28rem;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    vertical-align: middle;
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
.pattern {
    background: var(--p-surface-100);
    padding: 0.1rem 0.35rem;
    border-radius: 0.3rem;
}
.app-dark .pattern {
    background: var(--p-surface-800);
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
    gap: 0.6rem;
}
.preview {
    border-top: 1px solid var(--p-content-border-color);
    padding-top: 0.75rem;
}
.preview h3 {
    margin: 0 0 0.4rem;
    font-size: 1rem;
}
.sample {
    margin: 0.4rem 0 0;
    padding-left: 1.2rem;
    font-size: 0.85rem;
    max-height: 10rem;
    overflow: auto;
}
</style>
