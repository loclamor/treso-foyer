<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import Dialog from 'primevue/dialog';
import Button from 'primevue/button';
import InputText from 'primevue/inputtext';
import Select from 'primevue/select';
import SelectButton from 'primevue/selectbutton';
import DatePicker from 'primevue/datepicker';
import Textarea from 'primevue/textarea';
import { useToast } from 'primevue/usetoast';
import CategoryPicker from '@/components/CategoryPicker.vue';
import VehiclePicker from '@/components/VehiclePicker.vue';
import FuelFields from '@/components/FuelFields.vue';
import GasFields from '@/components/GasFields.vue';
import { createOperation, patchOperation, categorizeOperation, setOperationFuel, clearOperationFuel, setOperationGas, clearOperationGas } from '@/api/operations';
import { assignOperationVehicle } from '@/api/vehicles';
import { apiMessage } from '@/api/http';
import { useAccountsStore } from '@/stores/accounts';
import { useScopeStore } from '@/stores/scope';
import { useVehiclesStore } from '@/stores/vehicles';
import { useCategoriesStore } from '@/stores/categories';
import { centsToEuros, dateToIso, parseEuros } from '@/utils/format';

// Création ET modification d'une saisie manuelle (dépense saisie sur le moment, en attente de relevé).
// props.entry = null ⇒ création ; sinon édition de la saisie en attente (PATCH accountId/spentAt/amount/label/note,
// catégorie et véhicule repassés par leurs endpoints dédiés si changés).
const props = defineProps({
    visible: { type: Boolean, default: false },
    entry: { type: Object, default: null }, // Operation en attente (null = création)
});
const emit = defineEmits(['update:visible', 'saved']);

const toast = useToast();
const accountsStore = useAccountsStore();
const scope = useScopeStore();
const vehiclesStore = useVehiclesStore();
const categoriesStore = useCategoriesStore();

const accountId = ref(null);
const date = ref(new Date());
const amountEuros = ref('');
const direction = ref('debit'); // 'debit' (dépense, défaut) | 'credit' (revenu)
const label = ref('');
const categoryId = ref(null);
const vehicleId = ref(null);
const note = ref('');
const loading = ref(false);
// Plein de carburant (ADR 0015) : {volumeMl, fullTank, odometerKm}, volumeMl null = pas de plein.
const fuelModel = ref({ volumeMl: null, fullTank: true, odometerKm: null });
// Achat de gaz en bouteille (ADR 0017) : {massG}, massG null = pas d'achat de gaz.
const gasModel = ref({ massG: null });

const directionOptions = [
    { label: 'Dépense', value: 'debit' },
    { label: 'Revenu', value: 'credit' },
];

// Comptes non clos du foyer.
const accountOptions = computed(() =>
    accountsStore.list.filter((a) => !a.closedAt).map((a) => ({ label: a.alias || a.numberMasked, value: a.id })),
);

// Véhicule proposé dès qu'au moins un véhicule existe (saisie souvent liée à une voiture).
const showVehiclePicker = computed(() => vehiclesStore.list.length > 0);

// Catégorie carburant + dépense ⇒ champs du plein (le champ Montant est alors rendu par FuelFields).
// Catégorie gaz + dépense ⇒ champs de l'achat de gaz (masse, prix au kg ; montant rendu par GasFields).
const selectedCategory = computed(() => categoriesStore.byId.get(categoryId.value));
const showFuelFields = computed(() => !!selectedCategory.value?.fuel && direction.value === 'debit');
const showGasFields = computed(() => !!selectedCategory.value?.gas && !selectedCategory.value?.fuel && direction.value === 'debit');

// Présélection du compte du périmètre courant quand c'est un compte.
const defaultAccountId = computed(() => (scope.current.type === 'account' ? scope.current.id : null));

// Date max : aujourd'hui + 1 an (même règle que la validation API).
const maxDate = computed(() => {
    const d = new Date();
    return new Date(d.getFullYear() + 1, d.getMonth(), d.getDate());
});

const isEditing = computed(() => !!props.entry);
const spentAtIso = computed(() => dateToIso(date.value));
// Montant saisi en euros (positif) → centimes signés selon le sens choisi.
const signedAmount = computed(() => {
    const euros = parseEuros(amountEuros.value);
    if (euros === null || euros === 0) return null;
    return direction.value === 'debit' ? -Math.abs(euros) : Math.abs(euros);
});

const canSubmit = computed(
    () => !!accountId.value && !!spentAtIso.value && signedAmount.value !== null && label.value.trim().length > 0,
);

const visible = computed({
    get: () => props.visible,
    set: (v) => emit('update:visible', v),
});

function reset() {
    if (props.entry) {
        const op = props.entry;
        accountId.value = op.account?.id ?? null;
        const d = String(op.spentAt ?? op.date ?? '');
        const [y, m, day] = d.split('-').map(Number);
        date.value = y && m && day ? new Date(y, m - 1, day) : new Date();
        amountEuros.value = centsToEuros(Math.abs(op.amount));
        direction.value = op.amount < 0 ? 'debit' : 'credit';
        label.value = op.rawLabel ?? '';
        categoryId.value = op.category?.id ?? null;
        vehicleId.value = op.vehicle?.id ?? null;
        note.value = op.note ?? '';
        fuelModel.value = {
            volumeMl: op.fuel?.volumeMl ?? null,
            fullTank: op.fuel?.fullTank ?? true,
            odometerKm: op.fuel?.odometerKm ?? null,
        };
        gasModel.value = { massG: op.gas?.massG ?? null };
    } else {
        accountId.value = defaultAccountId.value ?? accountOptions.value[0]?.value ?? null;
        date.value = new Date();
        amountEuros.value = '';
        direction.value = 'debit';
        label.value = '';
        categoryId.value = null;
        vehicleId.value = null;
        note.value = '';
        fuelModel.value = { volumeMl: null, fullTank: true, odometerKm: null };
        gasModel.value = { massG: null };
    }
}

// Montant dérivé par FuelFields (volume × prix au litre) ou GasFields (masse × prix au kg)
// ⇒ on met à jour le champ Montant.
function onDerivedAmount(cents) {
    if (cents === null) {
        amountEuros.value = '';
        return;
    }
    amountEuros.value = centsToEuros(Math.abs(cents));
    direction.value = cents > 0 ? 'credit' : 'debit';
}

watch(
    () => [props.visible, props.entry],
    ([v]) => {
        if (v) reset();
    },
);

onMounted(() => {
    if (!accountsStore.loaded) accountsStore.refresh().catch(() => {});
    if (!vehiclesStore.loaded) vehiclesStore.refresh().catch(() => {});
    if (!categoriesStore.loaded) categoriesStore.refresh().catch(() => {});
});

async function submit() {
    if (!canSubmit.value) return;
    loading.value = true;
    try {
        const noteValue = note.value.trim() ? note.value.trim() : null;
        if (isEditing.value) {
            // Contrat PATCH : seuls accountId, spentAt, amount, label (et note) sont modifiables sur une
            // saisie en attente. Catégorie et véhicule passent par leurs endpoints dédiés.
            let updated = await patchOperation(props.entry.id, {
                accountId: accountId.value,
                spentAt: spentAtIso.value,
                amount: signedAmount.value,
                label: label.value.trim(),
                note: noteValue,
            });
            if (categoryId.value !== (props.entry.category?.id ?? null)) {
                await categorizeOperation(props.entry.id, { categoryId: categoryId.value, scope: 'operation' });
            }
            if ((vehicleId.value ?? null) !== (props.entry.vehicle?.id ?? null)) {
                await assignOperationVehicle(props.entry.id, vehicleId.value ?? null);
            }
            // Plein (ADR 0015) : PUT/DELETE dédié si le volume a changé (les autres champs du plein
            // — plein complet, kilométrage — sont remplacés avec).
            const prevFuel = props.entry.fuel ?? null;
            const nextFuel = fuelModel.value.volumeMl
                ? { volumeMl: fuelModel.value.volumeMl, fullTank: fuelModel.value.fullTank, odometerKm: fuelModel.value.odometerKm ?? null }
                : null;
            const fuelChanged =
                (prevFuel?.volumeMl ?? null) !== (nextFuel?.volumeMl ?? null) ||
                (prevFuel?.fullTank ?? true) !== (nextFuel?.fullTank ?? true) ||
                (prevFuel?.odometerKm ?? null) !== (nextFuel?.odometerKm ?? null);
            if (fuelChanged) {
                const res = nextFuel
                    ? await setOperationFuel(props.entry.id, nextFuel)
                    : await clearOperationFuel(props.entry.id);
                updated = res.operation;
                // Kilométrage incohérent avec les points voisins : avertissement, pas une erreur.
                if (res.warning) {
                    toast.add({ severity: 'warn', summary: 'Kilométrage incohérent', detail: res.warning, life: 10000 });
                }
            }
            // Achat de gaz (ADR 0017) : PUT/DELETE dédié si la masse a changé.
            const prevGas = props.entry.gas ?? null;
            const nextGasMass = gasModel.value.massG;
            if ((prevGas?.massG ?? null) !== (nextGasMass ?? null)) {
                const res = nextGasMass
                    ? await setOperationGas(props.entry.id, { massG: nextGasMass })
                    : await clearOperationGas(props.entry.id);
                updated = res.operation;
            }
            toast.add({ severity: 'success', summary: 'Saisie mise à jour', detail: `Opération #${updated.id}.`, life: 4000 });
            emit('saved', { operation: updated, reconciled: false });
        } else {
            const payload = {
                accountId: accountId.value,
                spentAt: spentAtIso.value,
                amount: signedAmount.value,
                label: label.value.trim(),
                categoryId: categoryId.value ?? null,
                vehicleId: vehicleId.value ?? null,
                note: noteValue,
            };
            // Plein (ADR 0015) : envoyé avec la création si un volume est saisi.
            if (showFuelFields.value && fuelModel.value.volumeMl) {
                payload.fuelVolumeMl = fuelModel.value.volumeMl;
                payload.fuelFullTank = fuelModel.value.fullTank;
                if (fuelModel.value.odometerKm !== null) payload.odometerKm = fuelModel.value.odometerKm;
            }
            // Achat de gaz (ADR 0017) : envoyé avec la création si une masse est saisie.
            if (showGasFields.value && gasModel.value.massG) {
                payload.gasMassG = gasModel.value.massG;
            }
            const res = await createOperation(payload);
            if (res.reconciled) {
                toast.add({
                    severity: 'success',
                    summary: 'Rapprochée immédiatement du relevé',
                    detail: 'La saisie a été absorbée par une ligne du relevé importé.',
                    life: 6000,
                });
            } else {
                toast.add({
                    severity: 'success',
                    summary: direction.value === 'debit' ? 'Dépense saisie' : 'Revenu saisi',
                    detail: 'Elle sera rapprochée automatiquement à l’import du relevé correspondant.',
                    life: 5000,
                });
            }
            emit('saved', res);
        }
        visible.value = false;
    } catch (e) {
        toast.add({ severity: 'error', summary: isEditing.value ? 'Modification impossible' : 'Saisie impossible', detail: apiMessage(e), life: 6000 });
    } finally {
        loading.value = false;
    }
}
</script>

<template>
    <Dialog
        v-model:visible="visible"
        modal
        :header="isEditing ? 'Modifier la saisie' : 'Saisir une dépense'"
        :style="{ width: 'min(34rem, 94vw)' }"
        :draggable="false"
    >
        <div class="entry-dialog">
            <div class="field">
                <label for="entry-account">Compte</label>
                <Select
                    id="entry-account"
                    v-model="accountId"
                    :options="accountOptions"
                    option-label="label"
                    option-value="value"
                    class="w-full"
                    placeholder="Choisir un compte"
                />
            </div>

            <div class="field">
                <label for="entry-date">Date de la dépense</label>
                <DatePicker
                    id="entry-date"
                    v-model="date"
                    date-format="dd/mm/yy"
                    show-icon
                    :max-date="maxDate"
                    class="w-full"
                />
            </div>

            <div class="field">
                <span id="entry-direction-label" class="field-label">Sens</span>
                <SelectButton
                    v-model="direction"
                    :options="directionOptions"
                    option-label="label"
                    option-value="value"
                    aria-labelledby="entry-direction-label"
                />
            </div>

            <div v-if="!showFuelFields && !showGasFields" class="field">
                <label for="entry-amount">Montant (€)</label>
                <InputText
                    id="entry-amount"
                    v-model="amountEuros"
                    inputmode="decimal"
                    placeholder="0,00"
                    class="w-full"
                />
            </div>

            <!-- Catégorie carburant + dépense : le plein (volume, prix au litre, kilométrage) ;
                 FuelFields rend alors aussi le champ Montant, lié aux deux autres grandeurs. -->
            <FuelFields
                v-if="showFuelFields"
                v-model="fuelModel"
                :amount-cents="signedAmount"
                amount-editable
                @update:amount-cents="onDerivedAmount"
            />

            <!-- Catégorie gaz + dépense : la bonbonne (masse en kg, prix au kg) ;
                 GasFields rend alors aussi le champ Montant, lié aux deux autres grandeurs. -->
            <GasFields
                v-if="showGasFields"
                v-model="gasModel"
                :amount-cents="signedAmount"
                amount-editable
                @update:amount-cents="onDerivedAmount"
            />

            <div class="field">
                <label for="entry-label">Libellé</label>
                <InputText id="entry-label" v-model="label" maxlength="255" class="w-full" placeholder="Ex. courses, essence…" />
            </div>

            <div class="field">
                <label for="entry-category">Catégorie</label>
                <CategoryPicker id="entry-category" v-model="categoryId" placeholder="Aucune catégorie" />
            </div>

            <div v-if="showVehiclePicker" class="field">
                <label for="entry-vehicle">Véhicule</label>
                <VehiclePicker id="entry-vehicle" v-model="vehicleId" />
            </div>

            <div class="field">
                <label for="entry-note">Note (facultative)</label>
                <Textarea id="entry-note" v-model="note" rows="2" auto-resize class="w-full" />
            </div>
        </div>

        <template #footer>
            <Button label="Annuler" severity="secondary" text @click="visible = false" />
            <Button
                :label="isEditing ? 'Enregistrer' : 'Saisir'"
                icon="pi pi-check"
                :disabled="!canSubmit"
                :loading="loading"
                @click="submit"
            />
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
</style>
