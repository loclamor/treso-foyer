<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import Button from 'primevue/button';
import InputText from 'primevue/inputtext';
import Checkbox from 'primevue/checkbox';
import Paginator from 'primevue/paginator';
import ProgressBar from 'primevue/progressbar';
import Skeleton from 'primevue/skeleton';
import Tag from 'primevue/tag';
import { useToast } from 'primevue/usetoast';
import { useScopeStore } from '@/stores/scope';
import { listMerchants, categorizeMerchant } from '@/api/merchants';
import { listOperations } from '@/api/operations';
import { getSummary } from '@/api/summary';
import { apiMessage } from '@/api/http';
import { formatDate } from '@/utils/format';
import MoneyAmount from '@/components/MoneyAmount.vue';
import CategoryTag from '@/components/CategoryTag.vue';
import CategoryPicker from '@/components/CategoryPicker.vue';
import CategorizeDialog from '@/components/CategorizeDialog.vue';

const scope = useScopeStore();
const toast = useToast();

const summary = ref(null);
const groups = ref([]);
const total = ref(0);
const loading = ref(false);
const first = ref(0);
const rows = ref(20);
const showAll = ref(false);
const q = ref('');
const picked = ref({}); // clé groupe → categoryId choisi dans le picker
const expanded = ref({}); // clé groupe → bool
const groupOps = ref({}); // clé groupe → [Operation]
const loadingOps = ref({});

const catVisible = ref(false);
const catOperation = ref(null);

function keyOf(g) {
    return `${g.key}::${g.direction}`;
}

const uncategorizedCount = computed(() => summary.value?.uncategorizedCount ?? 0);
const percentCategorized = computed(() => {
    const s = summary.value;
    if (!s || !s.operationsCount) return 0;
    return Math.round((100 * s.categorizedCount) / s.operationsCount);
});

async function loadSummary() {
    try {
        summary.value = await getSummary(scope.params);
    } catch {
        summary.value = null;
    }
}

async function load() {
    loading.value = true;
    try {
        const params = {
            ...scope.params,
            page: Math.floor(first.value / rows.value) + 1,
            perPage: rows.value,
            sort: 'count',
            order: 'desc',
        };
        if (!showAll.value) params.uncategorized = 1;
        if (q.value.trim()) params.q = q.value.trim();
        const res = await listMerchants(params);
        groups.value = res.items;
        total.value = res.total;
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Chargement impossible', detail: apiMessage(e, 'Les commerçants n’ont pas pu être chargés.'), life: 6000 });
    } finally {
        loading.value = false;
    }
}

function onPage(event) {
    first.value = event.first;
    rows.value = event.rows;
    load();
}

let qTimer = null;
watch(q, () => {
    clearTimeout(qTimer);
    qTimer = setTimeout(() => {
        first.value = 0;
        load();
    }, 300);
});
watch(showAll, () => {
    first.value = 0;
    load();
});
watch(() => scope.params, () => {
    first.value = 0;
    load();
    loadSummary();
}, { deep: true });

// ---------- classement d'un groupe ----------
async function classify(g, categoryId) {
    if (!categoryId) return;
    const k = keyOf(g);
    try {
        const res = await categorizeMerchant({ key: g.key, direction: g.direction, categoryId, createRule: true });
        toast.add({
            severity: 'success',
            summary: 'Commerçant classé',
            detail: `${res.updatedCount} opération(s) mises à jour${res.rule ? ', règle créée' : ''}.`,
            life: 5000,
        });
        groups.value = groups.value.filter((x) => keyOf(x) !== k);
        total.value = Math.max(0, total.value - 1);
        delete picked.value[k];
        loadSummary();
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Classement impossible', detail: apiMessage(e), life: 6000 });
    }
}

// ---------- opérations du groupe (ligne dépliable) ----------
async function toggleOps(g) {
    const k = keyOf(g);
    if (expanded.value[k]) {
        expanded.value = { ...expanded.value, [k]: false };
        return;
    }
    loadingOps.value = { ...loadingOps.value, [k]: true };
    try {
        const params = {
            ...scope.params,
            perPage: 200,
            sort: 'date',
            order: 'desc',
            direction: g.direction,
        };
        // merchant = égalité exacte sur la clé de groupe (commerçant, ou libellé normalisé à défaut)
        params.merchant = g.key;
        if (!showAll.value) params.uncategorized = 1;
        const res = await listOperations(params);
        groupOps.value = { ...groupOps.value, [k]: res.items };
        expanded.value = { ...expanded.value, [k]: true };
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Opérations illisibles', detail: apiMessage(e), life: 6000 });
    } finally {
        loadingOps.value = { ...loadingOps.value, [k]: false };
    }
}

function openCategorize(op) {
    catOperation.value = op;
    catVisible.value = true;
}

function onOpCategorized(res) {
    if (res?.operation) {
        for (const k of Object.keys(groupOps.value)) {
            const found = groupOps.value[k].find((o) => o.id === res.operation.id);
            if (found) Object.assign(found, res.operation);
        }
    }
}

onMounted(() => {
    load();
    loadSummary();
});
</script>

<template>
    <div>
        <div class="page-header">
            <h1>À catégoriser</h1>
            <div class="header-tools">
                <InputText v-model="q" placeholder="Rechercher un commerçant…" aria-label="Rechercher un commerçant" />
                <div class="check-all">
                    <Checkbox id="show-all" v-model="showAll" binary />
                    <label for="show-all">Tous les commerçants</label>
                </div>
            </div>
        </div>

        <div class="progress-block">
            <template v-if="summary">
                <ProgressBar :value="percentCategorized" />
                <p class="text-muted">
                    {{ percentCategorized }} % des opérations catégorisées ·
                    <strong>{{ uncategorizedCount.toLocaleString('fr-FR') }}</strong> opération(s) restante(s) à classer
                    dans le périmètre courant.
                </p>
            </template>
            <Skeleton v-else height="2.5rem" />
        </div>

        <div v-if="loading" class="groups">
            <Skeleton v-for="i in 5" :key="i" height="6rem" class="group-skeleton" />
        </div>

        <p v-else-if="!groups.length" class="empty-state">
            {{ showAll ? 'Aucun commerçant trouvé.' : 'Tout est catégorisé dans ce périmètre. Bravo !' }}
        </p>

        <TransitionGroup v-else name="group" tag="div" class="groups">
            <div v-for="g in groups" :key="keyOf(g)" class="group-card">
                <div class="group-head">
                    <div class="group-title">
                        <strong class="merchant">{{ g.merchant || g.key }}</strong>
                        <Tag :value="g.direction === 'debit' ? 'Débit' : 'Crédit'" :severity="g.direction === 'debit' ? 'danger' : 'success'" />
                        <span class="sample text-muted" :title="g.sampleLabel">{{ g.sampleLabel }}</span>
                    </div>
                    <Button
                        :label="expanded[keyOf(g)] ? 'Masquer les opérations' : `Voir les opérations (${g.count})`"
                        :icon="expanded[keyOf(g)] ? 'pi pi-chevron-up' : 'pi pi-chevron-down'"
                        text
                        size="small"
                        :aria-label="'Voir les opérations de ' + (g.merchant || g.key)"
                        @click="toggleOps(g)"
                    />
                </div>

                <div class="group-stats text-muted">
                    <span><strong>{{ g.count }}</strong> opération(s)</span>
                    <span>total <MoneyAmount :amount="g.total" /></span>
                    <span>montants de <MoneyAmount :amount="g.minAmount" /> à <MoneyAmount :amount="g.maxAmount" /></span>
                    <span>du {{ formatDate(g.firstDate) }} au {{ formatDate(g.lastDate) }}</span>
                </div>

                <div v-if="g.categories?.length" class="group-cats">
                    <span class="text-muted">Répartition :</span>
                    <span v-for="(c, i) in g.categories" :key="i" class="cat-count">
                        <CategoryTag :category="c.category" /> × {{ c.count }}
                    </span>
                </div>

                <div v-if="g.suggestion" class="suggestion">
                    <span class="text-muted">Suggestion :</span>
                    <CategoryTag :category="g.suggestion.category" />
                    <Button
                        label="Accepter la suggestion"
                        icon="pi pi-check"
                        size="small"
                        severity="success"
                        outlined
                        @click="classify(g, g.suggestion.category.id)"
                    />
                </div>

                <div class="group-actions">
                    <CategoryPicker v-model="picked[keyOf(g)]" placeholder="Choisir une catégorie…" />
                    <Button
                        label="Classer"
                        icon="pi pi-tag"
                        :disabled="!picked[keyOf(g)]"
                        @click="classify(g, picked[keyOf(g)])"
                    />
                </div>

                <div v-if="expanded[keyOf(g)]" class="group-ops">
                    <p v-if="loadingOps[keyOf(g)]" class="text-muted">Chargement des opérations…</p>
                    <table v-else class="ops-table">
                        <thead>
                            <tr><th>Date</th><th>Montant</th><th>Libellé</th><th>Catégorie</th></tr>
                        </thead>
                        <tbody>
                            <tr v-for="op in groupOps[keyOf(g)]" :key="op.id">
                                <td class="nowrap">{{ formatDate(op.date) }}</td>
                                <td class="nowrap text-right"><MoneyAmount :amount="op.amount" /></td>
                                <td :title="op.rawLabel" class="raw-label">{{ op.rawLabel }}</td>
                                <td><CategoryTag :category="op.category" clickable @click="openCategorize(op)" /></td>
                            </tr>
                            <tr v-if="!groupOps[keyOf(g)]?.length">
                                <td colspan="4" class="text-muted">Aucune opération dans ce groupe.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </TransitionGroup>

        <Paginator
            v-if="total > rows"
            :first="first"
            :rows="rows"
            :total-records="total"
            :rows-per-page-options="[10, 20, 50]"
            @page="onPage"
        />

        <CategorizeDialog v-model:visible="catVisible" :operation="catOperation" default-scope="operation" @done="onOpCategorized" />
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
.header-tools {
    display: flex;
    align-items: center;
    gap: 1rem;
    flex-wrap: wrap;
}
.check-all {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}
.progress-block {
    margin-bottom: 1rem;
}
.groups {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}
.group-card {
    border: 1px solid var(--p-surface-border, var(--p-content-border-color));
    border-radius: var(--p-border-radius);
    padding: 0.9rem 1rem;
    background: var(--p-surface-0, var(--p-content-background));
}
.group-head {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 1rem;
}
.group-title {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    flex-wrap: wrap;
}
.group-title .merchant {
    font-size: 1.05rem;
}
.sample {
    font-size: 0.85rem;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    max-width: 30rem;
}
.group-stats {
    display: flex;
    flex-wrap: wrap;
    gap: 0.4rem 1.2rem;
    margin: 0.4rem 0;
    font-size: 0.9rem;
}
.group-cats {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 0.4rem 0.8rem;
    margin: 0.3rem 0;
    font-size: 0.9rem;
}
.suggestion {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    margin: 0.4rem 0;
}
.group-actions {
    display: flex;
    gap: 0.6rem;
    margin-top: 0.6rem;
    max-width: 34rem;
}
.group-ops {
    margin-top: 0.75rem;
    overflow-x: auto;
}
.ops-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.9rem;
}
.ops-table th,
.ops-table td {
    text-align: left;
    padding: 0.3rem 0.6rem;
    border-bottom: 1px solid var(--p-content-border-color);
}
.ops-table .raw-label {
    max-width: 30rem;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.nowrap {
    white-space: nowrap;
}
.group-skeleton {
    margin-bottom: 0.75rem;
}
.empty-state {
    text-align: center;
    color: var(--p-text-muted-color);
    padding: 2rem;
}
.group-leave-active {
    transition: opacity 0.35s ease, transform 0.35s ease;
}
.group-leave-to {
    opacity: 0;
    transform: translateX(2rem);
}
.group-move {
    transition: transform 0.35s ease;
}
</style>
