<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import Dialog from 'primevue/dialog';
import Button from 'primevue/button';
import RadioButton from 'primevue/radiobutton';
import { useToast } from 'primevue/usetoast';
import CategoryPicker from '@/components/CategoryPicker.vue';
import VehiclePicker from '@/components/VehiclePicker.vue';
import MoneyAmount from '@/components/MoneyAmount.vue';
import FuelFields from '@/components/FuelFields.vue';
import { categorizeOperation, setOperationFuel, clearOperationFuel } from '@/api/operations';
import { assignOperationVehicle } from '@/api/vehicles';
import { apiMessage } from '@/api/http';
import { useCategoriesStore } from '@/stores/categories';
import { useVehiclesStore } from '@/stores/vehicles';
import { formatDate } from '@/utils/format';

const props = defineProps({
    visible: { type: Boolean, default: false },
    operation: { type: Object, default: null }, // Operation à catégoriser
    defaultScope: { type: String, default: null }, // 'operation' | 'merchant' | null (auto)
});
const emit = defineEmits(['update:visible', 'done']);

const toast = useToast();
const categoriesStore = useCategoriesStore();
const vehiclesStore = useVehiclesStore();

const categoryId = ref(null);
const scopeChoice = ref('operation');
const loading = ref(false);
const result = ref(null);
// Affectation manuelle du véhicule après catégorisation (catégorie « liée à un véhicule »).
const vehicleId = ref(null);
// Plein de carburant (ADR 0015) : {volumeMl, fullTank, odometerKm}, volumeMl null = pas de plein.
const fuelModel = ref({ volumeMl: null, fullTank: true, odometerKm: null });

// Proposition de véhicule seulement si la catégorie choisie est vehicleRelated et qu'au moins
// 2 véhicules sont actifs (avec un seul véhicule actif, l'affectation auto suffit).
const selectedCategory = computed(() => categoriesStore.byId.get(categoryId.value));
const showVehiclePicker = computed(() => !!selectedCategory.value?.vehicleRelated && vehiclesStore.active.length >= 2);
// Catégorie carburant + débit ⇒ champs du plein (montant imposé = celui de l'opération).
const showFuelFields = computed(() => !!selectedCategory.value?.fuel && (props.operation?.amount ?? 0) < 0);

const visible = computed({
    get: () => props.visible,
    set: (v) => emit('update:visible', v),
});

const hasMerchant = computed(() => !!props.operation?.merchant);

watch(
    () => props.operation,
    (op) => {
        if (op) {
            categoryId.value = op.category?.id ?? null;
            scopeChoice.value = props.defaultScope ?? (op.merchant ? 'merchant' : 'operation');
            vehicleId.value = op.vehicle?.id ?? null;
            fuelModel.value = {
                volumeMl: op.fuel?.volumeMl ?? null,
                fullTank: op.fuel?.fullTank ?? true,
                odometerKm: op.fuel?.odometerKm ?? null,
            };
            result.value = null;
        }
    },
    { immediate: true },
);

onMounted(() => {
    if (!vehiclesStore.loaded) vehiclesStore.refresh().catch(() => {});
});

function detailOf(res) {
    const parts = [`${res.updatedCount} opération(s) mise(s) à jour`];
    if (res.rule) parts.push('règle créée');
    return parts.join(', ');
}

async function submit() {
    if (!props.operation) return;
    loading.value = true;
    try {
        const res = await categorizeOperation(props.operation.id, { categoryId: categoryId.value, scope: scopeChoice.value });
        // Catégorie liée à un véhicule + choix explicite ⇒ affectation manuelle de l'opération.
        if (showVehiclePicker.value && vehicleId.value != null && res.operation?.vehicle?.id !== vehicleId.value) {
            res.operation = await assignOperationVehicle(props.operation.id, vehicleId.value);
        }
        // Plein (ADR 0015) : volume saisi et différent de l'existant ⇒ PUT ; volume vidé alors qu'il
        // en existait un ⇒ DELETE (le plein complet et le kilométrage suivent le volume).
        if (showFuelFields.value) {
            const prevFuel = props.operation.fuel ?? null;
            const nextFuel = fuelModel.value.volumeMl
                ? { volumeMl: fuelModel.value.volumeMl, fullTank: fuelModel.value.fullTank, odometerKm: fuelModel.value.odometerKm ?? null }
                : null;
            const fuelChanged =
                (prevFuel?.volumeMl ?? null) !== (nextFuel?.volumeMl ?? null) ||
                (prevFuel?.fullTank ?? true) !== (nextFuel?.fullTank ?? true) ||
                (prevFuel?.odometerKm ?? null) !== (nextFuel?.odometerKm ?? null);
            if (fuelChanged) {
                const fuelRes = nextFuel
                    ? await setOperationFuel(props.operation.id, nextFuel)
                    : await clearOperationFuel(props.operation.id);
                res.operation = fuelRes.operation;
                // Kilométrage incohérent avec les points voisins : avertissement, pas une erreur.
                if (fuelRes.warning) {
                    toast.add({ severity: 'warn', summary: 'Kilométrage incohérent', detail: fuelRes.warning, life: 10000 });
                }
            }
        }
        result.value = res;
        toast.add({ severity: 'success', summary: 'Catégorisation appliquée', detail: detailOf(res), life: 5000 });
        emit('done', res);
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Catégorisation impossible', detail: apiMessage(e), life: 6000 });
    } finally {
        loading.value = false;
    }
}

async function removeCategory() {
    categoryId.value = null;
    scopeChoice.value = 'operation';
    await submit();
}
</script>

<template>
    <Dialog v-model:visible="visible" modal :header="'Catégoriser l’opération'" :style="{ width: '34rem' }" :draggable="false">
        <div v-if="operation" class="categorize-dialog">
            <p class="op-recap">
                <MoneyAmount :amount="operation.amount" />
                <span class="text-muted">le {{ formatDate(operation.date) }}</span>
                <template v-if="operation.merchant"> — <strong>{{ operation.merchant }}</strong></template>
                <br />
                <span class="raw-label text-muted">{{ operation.rawLabel }}</span>
            </p>

            <div class="field">
                <label for="categorize-picker">Catégorie</label>
                <CategoryPicker id="categorize-picker" v-model="categoryId" />
            </div>

            <div v-if="showVehiclePicker" class="field">
                <label for="categorize-vehicle">Véhicule (catégorie liée à un véhicule)</label>
                <VehiclePicker id="categorize-vehicle" v-model="vehicleId" />
            </div>

            <!-- Catégorie carburant + débit : saisie du plein, montant imposé (ligne de relevé). -->
            <FuelFields
                v-if="showFuelFields"
                v-model="fuelModel"
                :amount-cents="operation.amount"
                :amount-editable="false"
            />

            <div v-if="hasMerchant" class="field">
                <span id="scope-label" class="field-label">Appliquer à</span>
                <div class="scope-choices" role="radiogroup" aria-labelledby="scope-label">
                    <RadioButton v-model="scopeChoice" input-id="scope-op" value="operation" />
                    <label for="scope-op">Seulement cette opération</label>
                    <RadioButton v-model="scopeChoice" input-id="scope-merchant" value="merchant" />
                    <label for="scope-merchant">Toutes les opérations de ce commerçant (crée une règle)</label>
                </div>
            </div>

            <p v-if="result" class="result-line">
                <i class="pi pi-check" aria-hidden="true" /> {{ detailOf(result) }}
            </p>
        </div>

        <template #footer>
            <Button label="Annuler" severity="secondary" text @click="visible = false" />
            <Button
                v-if="operation?.category"
                label="Retirer la catégorie"
                severity="danger"
                text
                :loading="loading"
                @click="removeCategory"
            />
            <Button label="Appliquer" icon="pi pi-check" :loading="loading" @click="submit" />
        </template>
    </Dialog>
</template>

<style scoped>
.field {
    margin-bottom: 1rem;
}
.field label,
.field-label {
    display: block;
    font-weight: 600;
    margin-bottom: 0.35rem;
}
.scope-choices {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.4rem 0.6rem;
}
.op-recap .raw-label {
    font-size: 0.85rem;
}
.result-line {
    color: var(--p-green-600);
}
.app-dark .result-line {
    color: var(--p-green-400);
}
</style>
