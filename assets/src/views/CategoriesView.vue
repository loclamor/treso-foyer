<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import TreeTable from 'primevue/treetable';
import Column from 'primevue/column';
import Button from 'primevue/button';
import Dialog from 'primevue/dialog';
import InputText from 'primevue/inputtext';
import Select from 'primevue/select';
import Checkbox from 'primevue/checkbox';
import ColorPicker from 'primevue/colorpicker';
import Tag from 'primevue/tag';
import { useToast } from 'primevue/usetoast';
import { useConfirm } from 'primevue/useconfirm';
import { useCategoriesStore } from '@/stores/categories';
import { createCategory, deleteCategory, updateCategory } from '@/api/categories';
import { apiMessage } from '@/api/http';
import { slotColor, OTHERS_COLOR } from '@/charts/palette';
import { useChartTheme } from '@/charts/theme';
import CategoryPicker from '@/components/CategoryPicker.vue';

const theme = useChartTheme();

const categoriesStore = useCategoriesStore();
const toast = useToast();
const confirm = useConfirm();

const expandedKeys = ref({});

function toTreeNodes(cats) {
    return (cats ?? []).map((c) => ({
        key: String(c.id),
        data: c,
        children: toTreeNodes(c.children),
    }));
}

const nodes = computed(() => toTreeNodes(categoriesStore.tree));

function allKeys(cats, acc = {}) {
    for (const c of cats ?? []) {
        acc[String(c.id)] = true;
        allKeys(c.children, acc);
    }
    return acc;
}

const KIND_LABELS = { expense: 'Dépense', income: 'Revenu', transfer: 'Virement' };
const KIND_OPTIONS = [
    { label: 'Dépense', value: 'expense' },
    { label: 'Revenu', value: 'income' },
    { label: 'Virement', value: 'transfer' },
];
const ICON_SUGGESTIONS = [
    'pi pi-shopping-cart', 'pi pi-home', 'pi pi-car', 'pi pi-bolt', 'pi pi-wifi', 'pi pi-phone',
    'pi pi-heart', 'pi pi-briefcase', 'pi pi-gift', 'pi pi-plane', 'pi pi-book', 'pi pi-euro',
    'pi pi-arrow-right-arrow-left', 'pi pi-wallet', 'pi pi-tag',
];

// ---------- formulaire création / édition ----------
const dialogVisible = ref(false);
const dialogMode = ref('create'); // 'create' | 'edit'
const editingId = ref(null);
const form = reactive({ name: '', parentId: null, kind: 'expense', color: '#4caf50', icon: '', vehicleRelated: false, fuel: false, financing: false });
const saving = ref(false);

function openCreate(parentId = null) {
    dialogMode.value = 'create';
    editingId.value = null;
    form.name = '';
    form.parentId = parentId;
    form.kind = 'expense';
    form.color = '#4caf50';
    form.icon = '';
    form.vehicleRelated = false;
    form.fuel = false;
    form.financing = false;
    dialogVisible.value = true;
}

function openEdit(cat) {
    dialogMode.value = 'edit';
    editingId.value = cat.id;
    form.name = cat.name;
    form.parentId = cat.parentId ?? null;
    form.kind = cat.kind;
    form.color = cat.color || '#4caf50';
    form.icon = cat.icon || '';
    form.vehicleRelated = !!cat.vehicleRelated;
    form.fuel = !!cat.fuel;
    form.financing = !!cat.financing;
    dialogVisible.value = true;
}

async function submit() {
    if (!form.name.trim()) return;
    saving.value = true;
    const payload = { name: form.name.trim(), kind: form.kind, color: form.color, icon: form.icon.trim() || null, vehicleRelated: form.vehicleRelated, fuel: form.fuel, financing: form.financing };
    try {
        if (dialogMode.value === 'create') {
            await createCategory({ ...payload, parentId: form.parentId });
            toast.add({ severity: 'success', summary: 'Catégorie créée', life: 3000 });
        } else {
            await updateCategory(editingId.value, { ...payload, parentId: form.parentId });
            toast.add({ severity: 'success', summary: 'Catégorie mise à jour', life: 3000 });
        }
        dialogVisible.value = false;
        await categoriesStore.refresh();
        expandedKeys.value = allKeys(categoriesStore.tree);
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Enregistrement impossible', detail: apiMessage(e), life: 6000 });
    } finally {
        saving.value = false;
    }
}

async function toggleArchived(cat) {
    try {
        await updateCategory(cat.id, { archived: !cat.archived });
        toast.add({ severity: 'success', summary: cat.archived ? 'Catégorie désarchivée' : 'Catégorie archivée', life: 3000 });
        await categoriesStore.refresh();
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Archivage impossible', detail: apiMessage(e), life: 6000 });
    }
}

function remove(cat) {
    confirm.require({
        message: `Supprimer la catégorie « ${cat.name} » et ses sous-catégories ?`,
        header: 'Suppression',
        icon: 'pi pi-exclamation-triangle',
        acceptLabel: 'Supprimer',
        rejectLabel: 'Annuler',
        acceptClass: 'p-button-danger',
        accept: async () => {
            try {
                await deleteCategory(cat.id);
                toast.add({ severity: 'success', summary: 'Catégorie supprimée', life: 3000 });
                await categoriesStore.refresh();
            } catch (e) {
                const conflict = e.response?.status === 409;
                toast.add({
                    severity: conflict ? 'warn' : 'error',
                    summary: conflict ? 'Suppression refusée' : 'Suppression impossible',
                    detail: apiMessage(e) + (conflict ? ' — archivez-la plutôt.' : ''),
                    life: 8000,
                });
            }
        },
    });
}

onMounted(async () => {
    try {
        await categoriesStore.refresh();
        expandedKeys.value = allKeys(categoriesStore.tree);
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Catégories illisibles', detail: apiMessage(e), life: 6000 });
    }
});

// Nombre d'opérations d'une catégorie, sous-catégories comprises.
function totalCount(category) {
    return (category?.operationsCount ?? 0) + (category?.children ?? []).reduce((sum, child) => sum + totalCount(child), 0);
}

// ---------- couleur des graphiques (chartSlot, racines de dépense uniquement) ----------
const slotOptions = computed(() => [
    { label: 'Aucune (Autres)', value: null, color: OTHERS_COLOR[theme.mode.value] ?? OTHERS_COLOR.light },
    ...Array.from({ length: 8 }, (_, i) => ({
        label: `Emplacement ${i + 1}`,
        value: i + 1,
        color: slotColor(i + 1, theme.mode.value),
    })),
]);

async function setChartSlot(cat, slot) {
    try {
        await updateCategory(cat.id, { chartSlot: slot });
        toast.add({
            severity: 'success',
            summary: slot ? `Emplacement ${slot} attribué à « ${cat.name} »` : `« ${cat.name} » regroupée en « Autres »`,
            life: 3000,
        });
    } catch (e) {
        if (e.response?.status === 409) {
            toast.add({
                severity: 'warn',
                summary: 'Emplacement déjà utilisé',
                detail: `${apiMessage(e, 'Cet emplacement est déjà utilisé')} — libérez-le d’abord sur l’autre catégorie.`,
                life: 8000,
            });
        } else {
            toast.add({ severity: 'error', summary: 'Enregistrement impossible', detail: apiMessage(e), life: 6000 });
        }
    } finally {
        await categoriesStore.refresh();
    }
}
</script>

<template>
    <div>
        <div class="page-header">
            <h1>Catégories</h1>
            <Button label="Nouvelle catégorie" icon="pi pi-plus" @click="openCreate(null)" />
        </div>

        <TreeTable :value="nodes" v-model:expanded-keys="expandedKeys" data-key="key">
            <template #empty>Aucune catégorie</template>
            <Column field="name" header="Nom" expander>
                <template #body="{ node }">
                    <span class="cat-name" :class="{ archived: node.data.archived }">
                        <span class="cat-dot" :style="{ background: node.data.color || 'var(--p-text-muted-color)' }" aria-hidden="true" />
                        <i v-if="node.data.icon" :class="node.data.icon" aria-hidden="true" />
                        {{ node.data.name }}
                        <i v-if="node.data.vehicleRelated" class="pi pi-car vehicle-icon" v-tooltip.top="'Catégorie liée à un véhicule'" aria-label="Catégorie liée à un véhicule" />
                        <i v-if="node.data.fuel" class="pi pi-gauge vehicle-icon" v-tooltip.top="'Catégorie carburant (volume, prix au litre, kilométrage)'" aria-label="Catégorie carburant" />
                        <i v-if="node.data.financing" class="pi pi-credit-card vehicle-icon" v-tooltip.top="'Financement du véhicule (exclu du coût d’usage au km)'" aria-label="Catégorie de financement" />
                        <Tag v-if="node.data.archived" value="Archivée" severity="secondary" class="archived-tag" />
                    </span>
                </template>
            </Column>
            <Column field="kind" header="Type" style="width: 9rem">
                <template #body="{ node }">{{ KIND_LABELS[node.data.kind] ?? node.data.kind }}</template>
            </Column>
            <Column header="Couleur des graphiques" style="width: 14rem">
                <template #body="{ node }">
                    <Select
                        v-if="node.data.parentId === null && node.data.kind === 'expense'"
                        :model-value="node.data.chartSlot ?? null"
                        :options="slotOptions"
                        option-label="label"
                        option-value="value"
                        size="small"
                        class="w-full"
                        :aria-label="'Couleur des graphiques pour ' + node.data.name"
                        @update:model-value="(v) => setChartSlot(node.data, v)"
                    >
                        <template #value="{ value }">
                            <span v-if="value !== null && value !== undefined" class="slot-chip">
                                <span class="cat-dot" :style="{ background: slotColor(value, theme.mode.value) }" aria-hidden="true" />
                                Emplacement {{ value }}
                            </span>
                            <span v-else class="slot-chip text-muted">
                                <span class="cat-dot" :style="{ background: OTHERS_COLOR[theme.mode.value] ?? OTHERS_COLOR.light }" aria-hidden="true" />
                                Aucune (Autres)
                            </span>
                        </template>
                        <template #option="{ option }">
                            <span class="slot-chip">
                                <span class="cat-dot" :style="{ background: option.color }" aria-hidden="true" />
                                {{ option.label }}
                            </span>
                        </template>
                    </Select>
                    <span v-else class="text-muted">—</span>
                </template>
            </Column>
            <Column field="operationsCount" header="Nb opérations" style="width: 10rem">
                <template #body="{ node }">{{ totalCount(node.data) }}</template>
            </Column>
            <Column header="Actions" style="width: 16rem">
                <template #body="{ node }">
                    <div class="row-actions">
                        <Button icon="pi pi-pencil" text rounded size="small" :aria-label="'Modifier ' + node.data.name" @click="openEdit(node.data)" />
                        <Button icon="pi pi-plus" text rounded size="small" :aria-label="'Ajouter une sous-catégorie à ' + node.data.name" @click="openCreate(node.data.id)" />
                        <Button
                            :icon="node.data.archived ? 'pi pi-eye' : 'pi pi-eye-slash'"
                            text
                            rounded
                            size="small"
                            :aria-label="(node.data.archived ? 'Désarchiver ' : 'Archiver ') + node.data.name"
                            @click="toggleArchived(node.data)"
                        />
                        <Button icon="pi pi-trash" text rounded size="small" severity="danger" :aria-label="'Supprimer ' + node.data.name" @click="remove(node.data)" />
                    </div>
                </template>
            </Column>
        </TreeTable>

        <Dialog
            v-model:visible="dialogVisible"
            modal
            :header="dialogMode === 'create' ? 'Nouvelle catégorie' : 'Modifier la catégorie'"
            :style="{ width: '30rem' }"
            :draggable="false"
        >
            <div class="form">
                <div class="field">
                    <label for="cat-name">Nom</label>
                    <InputText id="cat-name" v-model="form.name" class="w-full" required />
                </div>
                <div class="field">
                    <label for="cat-parent">Catégorie parente (vide = racine)</label>
                    <CategoryPicker id="cat-parent" v-model="form.parentId" placeholder="Racine" />
                </div>
                <div class="field">
                    <label for="cat-kind">Type</label>
                    <Select id="cat-kind" v-model="form.kind" :options="KIND_OPTIONS" option-label="label" option-value="value" class="w-full" />
                </div>
                <div class="field">
                    <label for="cat-color">Couleur</label>
                    <div class="color-row">
                        <ColorPicker id="cat-color" v-model="form.color" format="hex" />
                        <span class="text-muted">{{ form.color }}</span>
                    </div>
                </div>
                <div class="field field-check">
                    <Checkbox id="cat-vehicle" v-model="form.vehicleRelated" binary />
                    <label for="cat-vehicle">Liée à un véhicule (affectation auto quand un seul véhicule est actif à la date de l'opération)</label>
                </div>
                <div class="field field-check">
                    <Checkbox id="cat-fuel" v-model="form.fuel" binary />
                    <label for="cat-fuel">Catégorie carburant (volume, prix au litre, kilométrage)</label>
                </div>
                <div class="field field-check">
                    <Checkbox id="cat-financing" v-model="form.financing" binary />
                    <label for="cat-financing">Financement du véhicule (crédit, LOA… : compté dans le coût total, exclu du coût d'usage au km)</label>
                </div>
                <div class="field">
                    <label for="cat-icon">Icône (classe PrimeIcons)</label>
                    <InputText id="cat-icon" v-model="form.icon" placeholder="pi pi-shopping-cart" class="w-full" />
                    <div class="icon-preview">
                        <i v-if="form.icon" :class="form.icon" aria-hidden="true" />
                        <div class="icon-suggestions">
                            <button
                                v-for="ic in ICON_SUGGESTIONS"
                                :key="ic"
                                type="button"
                                class="icon-chip"
                                :aria-label="'Choisir l’icône ' + ic"
                                @click="form.icon = ic"
                            >
                                <i :class="ic" aria-hidden="true" />
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            <template #footer>
                <Button label="Annuler" severity="secondary" text @click="dialogVisible = false" />
                <Button :label="dialogMode === 'create' ? 'Créer' : 'Enregistrer'" icon="pi pi-check" :loading="saving" :disabled="!form.name.trim()" @click="submit" />
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
}
.cat-name {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
}
.cat-name.archived {
    opacity: 0.6;
}
.archived-tag {
    margin-left: 0.4rem;
    font-size: 0.75rem;
}
.vehicle-icon {
    color: var(--p-text-muted-color);
    font-size: 0.8rem;
}
.field-check {
    display: flex;
    align-items: center;
    gap: 0.6rem;
}
.field-check label {
    margin-bottom: 0;
    font-weight: 400;
}
.row-actions {
    display: flex;
    gap: 0.15rem;
}
.slot-chip {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
}
.form .field {
    margin-bottom: 1rem;
}
.form label {
    display: block;
    font-weight: 600;
    margin-bottom: 0.3rem;
}
.color-row {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}
.icon-preview {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    margin-top: 0.4rem;
    font-size: 1.2rem;
}
.icon-suggestions {
    display: flex;
    flex-wrap: wrap;
    gap: 0.25rem;
}
.icon-chip {
    border: 1px solid var(--p-content-border-color);
    background: transparent;
    border-radius: 0.4rem;
    padding: 0.25rem 0.4rem;
    cursor: pointer;
    color: inherit;
}
.icon-chip:hover {
    background: var(--p-highlight-background);
}
</style>
