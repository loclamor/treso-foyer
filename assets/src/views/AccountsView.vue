<script setup>
import { onMounted, reactive, ref } from 'vue';
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import Button from 'primevue/button';
import Dialog from 'primevue/dialog';
import InputText from 'primevue/inputtext';
import Select from 'primevue/select';
import MultiSelect from 'primevue/multiselect';
import DatePicker from 'primevue/datepicker';
import { useToast } from 'primevue/usetoast';
import { useAccountsStore } from '@/stores/accounts';
import { useMembersStore } from '@/stores/members';
import { updateAccount } from '@/api/accounts';
import { apiMessage } from '@/api/http';
import { dateToIso, formatDate } from '@/utils/format';
import MoneyAmount from '@/components/MoneyAmount.vue';

const accountsStore = useAccountsStore();
const membersStore = useMembersStore();
const toast = useToast();

const TYPE_LABELS = { personal: 'Personnel', joint: 'Commun', savings: 'Épargne' };
const TYPE_OPTIONS = [
    { label: 'Personnel', value: 'personal' },
    { label: 'Commun', value: 'joint' },
    { label: 'Épargne', value: 'savings' },
];

const dialogVisible = ref(false);
const editing = ref(null);
const saving = ref(false);
const form = reactive({ alias: '', label: '', type: 'personal', holderIds: [], closedAt: null });

function isoToDate(iso) {
    if (!iso) return null;
    const [y, m, d] = iso.split('-').map(Number);
    return new Date(y, m - 1, d);
}

function openEdit(account) {
    editing.value = account;
    form.alias = account.alias;
    form.label = account.label;
    form.type = account.type;
    form.holderIds = (account.holders ?? []).map((h) => h.id);
    form.closedAt = isoToDate(account.closedAt);
    dialogVisible.value = true;
}

async function submit() {
    if (!editing.value) return;
    saving.value = true;
    try {
        await updateAccount(editing.value.id, {
            alias: form.alias.trim(),
            label: form.label.trim(),
            type: form.type,
            holderIds: form.holderIds,
            // Chaîne vide : compte rouvert (date effacée).
            closedAt: form.closedAt ? dateToIso(form.closedAt) : '',
        });
        toast.add({ severity: 'success', summary: 'Compte mis à jour', life: 3000 });
        dialogVisible.value = false;
        await accountsStore.refresh();
    } catch (e) {
        toast.add({
            severity: e.response?.status === 409 ? 'warn' : 'error',
            summary: e.response?.status === 409 ? 'Conflit d’alias' : 'Mise à jour impossible',
            detail: apiMessage(e),
            life: 8000,
        });
    } finally {
        saving.value = false;
    }
}

onMounted(async () => {
    try {
        await Promise.all([accountsStore.refresh(), membersStore.refresh()]);
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Comptes illisibles', detail: apiMessage(e), life: 6000 });
    }
});
</script>

<template>
    <div>
        <h1>Comptes</h1>

        <DataTable :value="accountsStore.list" data-key="id">
            <template #empty>Aucun compte</template>
            <Column field="alias" header="Alias" />
            <Column field="label" header="Libellé" />
            <Column field="numberMasked" header="Numéro" />
            <Column header="Type" style="width: 8rem">
                <template #body="{ data }">
                    {{ TYPE_LABELS[data.type] ?? data.type }}
                    <div v-if="data.closedAt" class="text-muted closed">Clôturé le {{ formatDate(data.closedAt) }}</div>
                </template>
            </Column>
            <Column header="Titulaires">
                <template #body="{ data }">{{ (data.holders ?? []).map((h) => h.displayName).join(', ') || '—' }}</template>
            </Column>
            <Column field="operationsCount" header="Nb opérations" style="width: 9rem" />
            <Column header="Période" style="width: 13rem">
                <template #body="{ data }">{{ formatDate(data.firstDate) }} → {{ formatDate(data.lastDate) }}</template>
            </Column>
            <Column header="Solde" style="width: 11rem">
                <template #body="{ data }">
                    <MoneyAmount v-if="data.balance" :amount="data.balance.amount" />
                    <span v-else class="text-muted">—</span>
                    <span v-if="data.balance" class="text-muted solde-date">au {{ formatDate(data.balance.date) }}</span>
                </template>
            </Column>
            <Column header="Actions" style="width: 5rem">
                <template #body="{ data }">
                    <Button icon="pi pi-pencil" text rounded size="small" :aria-label="'Modifier le compte ' + data.alias" @click="openEdit(data)" />
                </template>
            </Column>
        </DataTable>

        <Dialog v-model:visible="dialogVisible" modal :header="'Modifier le compte ' + (editing?.alias ?? '')" :style="{ width: '30rem' }" :draggable="false">
            <div class="form">
                <div class="field">
                    <label for="acc-alias">Alias (unique)</label>
                    <InputText id="acc-alias" v-model="form.alias" class="w-full" required />
                </div>
                <div class="field">
                    <label for="acc-label">Libellé</label>
                    <InputText id="acc-label" v-model="form.label" class="w-full" />
                </div>
                <div class="field">
                    <label for="acc-type">Type</label>
                    <Select id="acc-type" v-model="form.type" :options="TYPE_OPTIONS" option-label="label" option-value="value" class="w-full" />
                </div>
                <div class="field">
                    <label for="acc-closed">Date de clôture</label>
                    <DatePicker
                        input-id="acc-closed"
                        v-model="form.closedAt"
                        date-format="dd/mm/yy"
                        placeholder="Compte ouvert"
                        show-icon
                        show-button-bar
                        class="w-full"
                    />
                    <small class="text-muted">Aucun relevé n'est attendu après le mois de clôture.</small>
                </div>
                <div class="field">
                    <label for="acc-holders">Titulaires</label>
                    <MultiSelect
                        id="acc-holders"
                        v-model="form.holderIds"
                        :options="membersStore.list"
                        option-label="displayName"
                        option-value="id"
                        filter
                        display="chip"
                        class="w-full"
                    />
                </div>
            </div>
            <template #footer>
                <Button label="Annuler" severity="secondary" text @click="dialogVisible = false" />
                <Button label="Enregistrer" icon="pi pi-check" :loading="saving" :disabled="!form.alias.trim()" @click="submit" />
            </template>
        </Dialog>
    </div>
</template>

<style scoped>
.form .field {
    margin-bottom: 1rem;
}
.form label {
    display: block;
    font-weight: 600;
    margin-bottom: 0.3rem;
}
.solde-date {
    margin-left: 0.4rem;
    font-size: 0.8rem;
}
.closed {
    font-size: 0.8rem;
}
</style>
