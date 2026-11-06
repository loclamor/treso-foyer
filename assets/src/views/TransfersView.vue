<script setup>
import { onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import Button from 'primevue/button';
import Paginator from 'primevue/paginator';
import SelectButton from 'primevue/selectbutton';
import Tag from 'primevue/tag';
import Skeleton from 'primevue/skeleton';
import { useToast } from 'primevue/usetoast';
import { useConfirm } from 'primevue/useconfirm';
import { useScopeStore } from '@/stores/scope';
import { confirmAllTransfers, confirmTransfer, deleteTransfer, listTransfers, matchTransfers } from '@/api/transfers';
import { apiMessage } from '@/api/http';
import { formatDate } from '@/utils/format';
import MoneyAmount from '@/components/MoneyAmount.vue';

const scope = useScopeStore();
const toast = useToast();
const confirm = useConfirm();
const route = useRoute();
const router = useRouter();

const STATUS_OPTIONS = [
    { label: 'À vérifier', value: 'auto' },
    { label: 'Validés', value: 'confirmed' },
    { label: 'Tous', value: 'all' },
];
const status = ref(['auto', 'confirmed'].includes(route.query.status) ? route.query.status : 'all');
const confirming = ref({});
const confirmingAll = ref(false);

const transfers = ref([]);
const total = ref(0);
const loading = ref(false);
const first = ref(0);
const rows = ref(10);
const matching = ref(false);

async function load() {
    loading.value = true;
    try {
        const res = await listTransfers({
            ...scope.params,
            ...(status.value !== 'all' ? { status: status.value } : {}),
            page: Math.floor(first.value / rows.value) + 1,
            perPage: rows.value,
        });
        transfers.value = res.items;
        total.value = res.total;
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Virements illisibles', detail: apiMessage(e), life: 6000 });
    } finally {
        loading.value = false;
    }
}

function onPage(event) {
    first.value = event.first;
    rows.value = event.rows;
    load();
}

watch(status, (s) => {
    first.value = 0;
    router.replace({ query: s === 'all' ? {} : { status: s } });
    load();
});

async function validate(t) {
    confirming.value[t.id] = true;
    try {
        const updated = await confirmTransfer(t.id);
        if (status.value === 'auto') {
            // sort du filtre « À vérifier »
            transfers.value = transfers.value.filter((x) => x.id !== t.id);
            total.value -= 1;
        } else {
            Object.assign(t, updated);
        }
        toast.add({ severity: 'success', summary: 'Virement validé', life: 2500 });
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Validation impossible', detail: apiMessage(e), life: 6000 });
    } finally {
        confirming.value[t.id] = false;
    }
}

function validateAll() {
    confirm.require({
        header: 'Tout valider',
        message: 'Valider tous les virements détectés automatiquement (pas seulement ceux affichés) ? Ils ne seront plus comptés « à vérifier ». Un virement validé peut toujours être défait.',
        icon: 'pi pi-check-circle',
        acceptLabel: 'Tout valider',
        rejectLabel: 'Annuler',
        accept: async () => {
            confirmingAll.value = true;
            try {
                const res = await confirmAllTransfers();
                toast.add({ severity: 'success', summary: 'Virements validés', detail: `${res.confirmedCount} virement(s) validé(s).`, life: 5000 });
                first.value = 0;
                load();
            } catch (e) {
                toast.add({ severity: 'error', summary: 'Validation impossible', detail: apiMessage(e), life: 6000 });
            } finally {
                confirmingAll.value = false;
            }
        },
    });
}

watch(() => scope.params, () => {
    first.value = 0;
    load();
}, { deep: true });

async function rematch() {
    matching.value = true;
    try {
        const res = await matchTransfers();
        toast.add({
            severity: 'success',
            summary: 'Appariement relancé',
            detail: `${res.matched} virement(s) apparié(s), ${res.ambiguous} appariement(s) ambigu(s).`,
            life: 6000,
        });
        load();
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Appariement impossible', detail: apiMessage(e), life: 6000 });
    } finally {
        matching.value = false;
    }
}

function unpair(t) {
    confirm.require({
        message: 'Défaire cet appariement ? Les deux opérations redeviennent des opérations simples (la catégorisation « virement » est retirée).',
        header: `Défaire le virement #${t.id}`,
        icon: 'pi pi-exclamation-triangle',
        acceptLabel: 'Défaire',
        rejectLabel: 'Annuler',
        acceptClass: 'p-button-danger',
        accept: async () => {
            try {
                await deleteTransfer(t.id);
                toast.add({ severity: 'success', summary: 'Appariement défait', life: 3000 });
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
        <div class="page-header">
            <h1>Virements internes</h1>
            <div class="header-actions">
                <Button
                    v-if="status === 'auto' && total > 0"
                    :label="`Tout valider (${total})`"
                    icon="pi pi-check-circle"
                    :loading="confirmingAll"
                    @click="validateAll"
                />
                <Button label="Relancer l’appariement" icon="pi pi-refresh" severity="secondary" outlined :loading="matching" @click="rematch" />
            </div>
        </div>
        <p class="text-muted">Appariements détectés entre vos comptes : validez-les, ou défaites ceux qui sont faux.</p>
        <SelectButton v-model="status" :options="STATUS_OPTIONS" option-label="label" option-value="value" :allow-empty="false" aria-label="Statut des virements" class="status-filter" />

        <div v-if="loading" class="list">
            <Skeleton v-for="i in 3" :key="i" height="6rem" class="item-skeleton" />
        </div>

        <p v-else-if="!transfers.length" class="empty-state">{{ status === 'auto' ? 'Aucun virement à vérifier.' : 'Aucun virement interne détecté dans ce périmètre.' }}</p>

        <div v-else class="list">
            <div v-for="t in transfers" :key="t.id" class="transfer-card">
                <div class="transfer-head">
                    <Tag :value="t.status === 'confirmed' ? 'Validé' : 'À vérifier'" :severity="t.status === 'confirmed' ? 'success' : 'info'" />
                    <span class="text-muted">#{{ t.id }}</span>
                    <Button
                        v-if="t.status === 'auto'"
                        label="Valider"
                        icon="pi pi-check"
                        size="small"
                        severity="success"
                        text
                        :loading="!!confirming[t.id]"
                        :aria-label="'Valider le virement ' + t.id"
                        @click="validate(t)"
                    />
                    <Button label="Défaire" icon="pi pi-times" size="small" severity="danger" text :aria-label="'Défaire le virement ' + t.id" @click="unpair(t)" />
                </div>
                <div class="transfer-ops">
                    <div v-for="op in t.operations" :key="op.id" class="op-line">
                        <span class="nowrap">{{ formatDate(op.date) }}</span>
                        <span class="nowrap">{{ op.account?.alias ?? op.account?.numberMasked }}</span>
                        <MoneyAmount :amount="op.amount" />
                        <span class="op-label" :title="op.rawLabel">
                            <strong v-if="op.merchant">{{ op.merchant }}</strong> {{ op.rawLabel }}
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <Paginator v-if="total > 10" :first="first" :rows="rows" :total-records="total" :rows-per-page-options="[10, 25, 50, 100]" @page="onPage" />
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
.header-actions {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
}
.status-filter {
    margin-bottom: 1rem;
}
.list {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}
.transfer-card {
    border: 1px solid var(--p-content-border-color);
    border-radius: var(--p-border-radius);
    padding: 0.75rem 1rem;
}
.transfer-head {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}
.transfer-ops {
    margin-top: 0.5rem;
    display: flex;
    flex-direction: column;
    gap: 0.3rem;
}
.op-line {
    display: flex;
    align-items: center;
    gap: 0.9rem;
}
/* Libellé tronqué sur la même ligne (en retour à la ligne, il ressemblait à une 3e opération). */
.op-label {
    flex: 1 1 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    min-width: 0;
}
.nowrap {
    white-space: nowrap;
}
.item-skeleton {
    margin-bottom: 0.75rem;
}
.empty-state {
    text-align: center;
    color: var(--p-text-muted-color);
    padding: 2rem;
}
</style>
