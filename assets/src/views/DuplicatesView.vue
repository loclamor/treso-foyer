<script setup>
import { onMounted, ref, watch } from 'vue';
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import Button from 'primevue/button';
import { useToast } from 'primevue/usetoast';
import { useConfirm } from 'primevue/useconfirm';
import { useScopeStore } from '@/stores/scope';
import { getOperation, listOperations, resolveDuplicate } from '@/api/operations';
import { apiMessage } from '@/api/http';
import { formatDate } from '@/utils/format';
import MoneyAmount from '@/components/MoneyAmount.vue';
import SourceBadge from '@/components/SourceBadge.vue';
import CategoryTag from '@/components/CategoryTag.vue';

const scope = useScopeStore();
const toast = useToast();
const confirm = useConfirm();

const items = ref([]);
const total = ref(0);
const loading = ref(false);
const first = ref(0);
const rows = ref(20);
const expandedRows = ref([]);
const refs = ref({}); // duplicateOfId → Operation (cache)

async function load() {
    loading.value = true;
    try {
        const res = await listOperations({
            ...scope.params,
            duplicateStatus: 'probable',
            page: Math.floor(first.value / rows.value) + 1,
            perPage: rows.value,
            sort: 'date',
            order: 'desc',
        });
        items.value = res.items;
        total.value = res.total;
        // pré-charge les opérations de référence pour l'affichage côte à côte
        const missing = [...new Set(res.items.map((o) => o.duplicateOfId).filter(Boolean))].filter((id) => !refs.value[id]);
        await Promise.all(missing.map(async (id) => {
            try {
                refs.value = { ...refs.value, [id]: await getOperation(id) };
            } catch {
                refs.value = { ...refs.value, [id]: null };
            }
        }));
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Doublons illisibles', detail: apiMessage(e), life: 6000 });
    } finally {
        loading.value = false;
    }
}

function onPage(event) {
    first.value = event.first;
    rows.value = event.rows;
    load();
}

watch(() => scope.params, () => {
    first.value = 0;
    load();
}, { deep: true });

function merge(op) {
    confirm.require({
        message: `Fusionner ? L’opération de référence (libellé PDF prioritaire) est conservée, l’autre est supprimée. La catégorie manuelle et la note sont reportées si besoin.`,
        header: `Fusionner #${op.id} avec #${op.duplicateOfId ?? '…'}`,
        icon: 'pi pi-exclamation-triangle',
        acceptLabel: 'Fusionner',
        rejectLabel: 'Annuler',
        acceptClass: 'p-button-danger',
        accept: async () => {
            try {
                await resolveDuplicate(op.id, 'merge');
                toast.add({ severity: 'success', summary: 'Doublon fusionné', life: 3000 });
                load();
            } catch (e) {
                toast.add({ severity: 'error', summary: 'Fusion impossible', detail: apiMessage(e), life: 6000 });
            }
        },
    });
}

function distinct(op) {
    confirm.require({
        message: 'Confirmer que ce sont deux opérations distinctes (elles ne seront plus proposées comme doublons).',
        header: `Opérations #${op.id} et #${op.duplicateOfId ?? '…'} distinctes`,
        icon: 'pi pi-question-circle',
        acceptLabel: 'Ce sont deux opérations distinctes',
        rejectLabel: 'Annuler',
        accept: async () => {
            try {
                await resolveDuplicate(op.id, 'distinct');
                toast.add({ severity: 'success', summary: 'Distinction confirmée', life: 3000 });
                load();
            } catch (e) {
                toast.add({ severity: 'error', summary: 'Action impossible', detail: apiMessage(e), life: 6000 });
            }
        },
    });
}

onMounted(load);
</script>

<template>
    <div>
        <h1>Doublons probables</h1>
        <p class="text-muted">Deux opérations de même montant rapprochées dans le temps : fusionnez-les ou confirmez qu’elles sont distinctes.</p>

        <DataTable
            :value="items"
            lazy
            paginator
            :rows="rows"
            :first="first"
            :total-records="total"
            :loading="loading"
            v-model:expanded-rows="expandedRows"
            data-key="id"
            @page="onPage"
        >
            <Column expander style="width: 3rem" aria-label="Voir le doublon côte à côte" />
            <Column field="date" header="Date" style="width: 7.5rem">
                <template #body="{ data }">{{ formatDate(data.date) }}</template>
            </Column>
            <Column header="Compte" style="width: 8rem">
                <template #body="{ data }">{{ data.account?.alias ?? data.account?.numberMasked }}</template>
            </Column>
            <Column header="Libellé">
                <template #body="{ data }">
                    <strong v-if="data.merchant">{{ data.merchant }}</strong>
                    <span class="raw-label text-muted" :title="data.rawLabel"> {{ data.rawLabel }}</span>
                </template>
            </Column>
            <Column header="Montant" style="width: 9rem">
                <template #body="{ data }"><MoneyAmount :amount="data.amount" /></template>
            </Column>
            <Column header="Source" style="width: 8rem">
                <template #body="{ data }"><SourceBadge :source="data.categorizationSource" /></template>
            </Column>
            <Column header="Catégorie" style="width: 11rem">
                <template #body="{ data }"><CategoryTag :category="data.category" /></template>
            </Column>
            <Column header="Doublon de" style="width: 8rem">
                <template #body="{ data }">#{{ data.duplicateOfId ?? '—' }}</template>
            </Column>
            <Column header="Actions" style="width: 13rem">
                <template #body="{ data }">
                    <Button label="Fusionner" icon="pi pi-clone" size="small" severity="danger" outlined @click="merge(data)" />
                    <Button label="Distinctes" icon="pi pi-check" size="small" severity="secondary" text @click="distinct(data)" />
                </template>
            </Column>

            <template #expansion="{ data }">
                <div class="dup-compare">
                    <div class="dup-panel">
                        <h3>Opération #{{ data.id }}</h3>
                        <p>{{ formatDate(data.date) }} · <MoneyAmount :amount="data.amount" /> · {{ data.account?.alias ?? data.account?.numberMasked }}</p>
                        <p><strong v-if="data.merchant">{{ data.merchant }}</strong> {{ data.rawLabel }}</p>
                        <p><CategoryTag :category="data.category" /> <SourceBadge :source="data.categorizationSource" /></p>
                    </div>
                    <div class="dup-panel">
                        <h3>Opération de référence #{{ data.duplicateOfId }}</h3>
                        <template v-if="refs[data.duplicateOfId]">
                            <p>
                                {{ formatDate(refs[data.duplicateOfId].date) }} ·
                                <MoneyAmount :amount="refs[data.duplicateOfId].amount" /> ·
                                {{ refs[data.duplicateOfId].account?.alias ?? refs[data.duplicateOfId].account?.numberMasked }}
                            </p>
                            <p>
                                <strong v-if="refs[data.duplicateOfId].merchant">{{ refs[data.duplicateOfId].merchant }}</strong>
                                {{ refs[data.duplicateOfId].rawLabel }}
                            </p>
                            <p>
                                <CategoryTag :category="refs[data.duplicateOfId].category" />
                                <SourceBadge :source="refs[data.duplicateOfId].categorizationSource" />
                            </p>
                        </template>
                        <p v-else-if="refs[data.duplicateOfId] === null" class="text-muted">Opération de référence introuvable.</p>
                        <p v-else class="text-muted">Chargement…</p>
                    </div>
                </div>
            </template>

            <template #empty><p class="empty-state">Aucun doublon probable dans ce périmètre.</p></template>
        </DataTable>
    </div>
</template>

<style scoped>
.dup-compare {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
    padding: 0.5rem 1rem;
}
@media (max-width: 768px) {
    .dup-compare {
        grid-template-columns: 1fr;
    }
}
.dup-panel {
    border: 1px solid var(--p-content-border-color);
    border-radius: var(--p-border-radius);
    padding: 0.75rem 1rem;
}
.dup-panel h3 {
    margin: 0 0 0.4rem;
    font-size: 0.95rem;
}
.dup-panel p {
    margin: 0.2rem 0;
}
.raw-label {
    font-size: 0.85rem;
}
.empty-state {
    text-align: center;
    color: var(--p-text-muted-color);
    padding: 1rem;
}
</style>
