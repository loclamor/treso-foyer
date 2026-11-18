<script setup>
import { computed, ref, watch } from 'vue';
import Dialog from 'primevue/dialog';
import Button from 'primevue/button';
import InputText from 'primevue/inputtext';
import Select from 'primevue/select';
import DatePicker from 'primevue/datepicker';
import Checkbox from 'primevue/checkbox';
import VehiclePicker from '@/components/VehiclePicker.vue';
import { useConfirm } from 'primevue/useconfirm';
import { createTaggedPeriod, updateTaggedPeriod, deleteTaggedPeriod } from '@/api/taggedPeriods';
import { apiMessage } from '@/api/http';
import { dateToIso, isoToDate } from '@/utils/format';
import { TAGGED_PERIOD_TYPES } from '@/charts/palette';

// Création ET modification d'une période taguée (vacances, week-end, sortie, autre).
// props.period = null ⇒ création (pré-remplie avec props.initialDate 'AAAA-MM-JJ') ;
// sinon édition de la période existante. Suppression en édition via ConfirmDialog (monté dans App.vue).
const props = defineProps({
    visible: { type: Boolean, default: false },
    period: { type: Object, default: null }, // TaggedPeriod existant (null = création)
    initialDate: { type: String, default: null }, // 'AAAA-MM-JJ' pour pré-remplir une création
});
const emit = defineEmits(['update:visible', 'saved', 'deleted']);

const confirm = useConfirm();

// [Date, Date|null] — un seul jour sélectionné ⇒ endDate = startDate au submit.
const range = ref(null);
const type = ref('other');
const label = ref('');
// Véhicule par défaut des dépenses de transport de la période (id ou null) et priorité sur les règles.
const vehicleId = ref(null);
const vehicleOverridesRules = ref(false);
const saving = ref(false);
const deleting = ref(false);
const error = ref('');

const isEditing = computed(() => !!props.period);

const visible = computed({
    get: () => props.visible,
    set: (v) => emit('update:visible', v),
});

const canSubmit = computed(() => {
    const start = range.value?.[0];
    return start instanceof Date && !Number.isNaN(start.getTime()) && !!type.value && label.value.trim().length > 0;
});

function reset() {
    error.value = '';
    if (props.period) {
        type.value = props.period.type ?? 'other';
        label.value = props.period.label ?? '';
        const start = isoToDate(props.period.startDate);
        const end = isoToDate(props.period.endDate);
        range.value = start ? [start, end ?? start] : null;
        vehicleId.value = props.period.vehicle?.id ?? null;
        vehicleOverridesRules.value = !!props.period.vehicleOverridesRules;
    } else {
        type.value = 'other';
        label.value = '';
        vehicleId.value = null;
        vehicleOverridesRules.value = false;
        const d = isoToDate(props.initialDate);
        range.value = d ? [d, null] : null;
    }
}

watch(
    () => [props.visible, props.period, props.initialDate],
    ([v]) => {
        if (v) reset();
    },
);

async function submit() {
    if (!canSubmit.value) return;
    saving.value = true;
    error.value = '';
    try {
        const start = range.value[0];
        const end = range.value[1] ?? start; // un seul jour cliqué = période d'un jour
        // dateToIso construit la chaîne depuis getFullYear/getMonth/getDate : pas de décalage de fuseau.
        const payload = {
            type: type.value,
            label: label.value.trim(),
            startDate: dateToIso(start),
            endDate: dateToIso(end),
            vehicleOverridesRules: vehicleId.value !== null && vehicleOverridesRules.value,
        };
        // PATCH : « vehicleId absent » = inchangé ⇒ retrait explicite via clearVehicle.
        if (vehicleId.value !== null) payload.vehicleId = vehicleId.value;
        else if (isEditing.value) payload.clearVehicle = true;
        const saved = isEditing.value ? await updateTaggedPeriod(props.period.id, payload) : await createTaggedPeriod(payload);
        emit('saved', saved);
        visible.value = false;
    } catch (e) {
        error.value = apiMessage(e, isEditing.value ? 'Modification impossible' : 'Création impossible');
    } finally {
        saving.value = false;
    }
}

function askDelete() {
    confirm.require({
        header: 'Supprimer la période',
        message: `Supprimer la période « ${label.value.trim() || 'sans libellé'} » ?`,
        icon: 'pi pi-exclamation-triangle',
        acceptLabel: 'Supprimer',
        rejectLabel: 'Annuler',
        acceptClass: 'p-button-danger',
        accept: async () => {
            deleting.value = true;
            error.value = '';
            try {
                await deleteTaggedPeriod(props.period.id);
                emit('deleted', props.period);
                visible.value = false;
            } catch (e) {
                error.value = apiMessage(e, 'Suppression impossible');
            } finally {
                deleting.value = false;
            }
        },
    });
}
</script>

<template>
    <Dialog
        v-model:visible="visible"
        modal
        :header="isEditing ? 'Modifier la période' : 'Ajouter une période'"
        :style="{ width: 'min(30rem, 94vw)' }"
        :draggable="false"
    >
        <div class="period-dialog">
            <div class="field">
                <label for="period-range">Dates</label>
                <DatePicker
                    id="period-range"
                    v-model="range"
                    selection-mode="range"
                    date-format="dd/mm/yy"
                    show-icon
                    class="w-full"
                    placeholder="Du → au"
                />
            </div>

            <div class="field">
                <label for="period-type">Type</label>
                <Select
                    id="period-type"
                    v-model="type"
                    :options="TAGGED_PERIOD_TYPES"
                    option-label="label"
                    option-value="value"
                    class="w-full"
                />
            </div>

            <div class="field">
                <label for="period-label">Libellé</label>
                <InputText id="period-label" v-model="label" maxlength="100" class="w-full" placeholder="Ex. vacances à la mer…" />
            </div>

            <div class="field">
                <label for="period-vehicle">Véhicule</label>
                <VehiclePicker id="period-vehicle" v-model="vehicleId" placeholder="Aucun véhicule" />
                <small class="help-text">
                    Les dépenses de transport de la période seront affectées à ce véhicule (sauf affectations manuelles
                    et crédits véhicule).
                </small>
                <div v-if="vehicleId !== null" class="checkbox-row">
                    <Checkbox v-model="vehicleOverridesRules" input-id="period-vehicle-overrides" binary />
                    <label for="period-vehicle-overrides">Prioritaire sur les règles de catégorisation</label>
                </div>
            </div>

            <p v-if="error" class="error-text" role="alert">{{ error }}</p>
        </div>

        <template #footer>
            <Button
                v-if="isEditing"
                label="Supprimer"
                icon="pi pi-trash"
                severity="danger"
                text
                :loading="deleting"
                @click="askDelete"
            />
            <Button label="Annuler" severity="secondary" text @click="visible = false" />
            <Button
                label="Enregistrer"
                icon="pi pi-check"
                :disabled="!canSubmit"
                :loading="saving"
                @click="submit"
            />
        </template>
    </Dialog>
</template>

<style scoped>
.field {
    margin-bottom: 1rem;
}
.field label {
    display: block;
    font-weight: 600;
    margin-bottom: 0.35rem;
}
.help-text {
    display: block;
    color: var(--p-text-muted-color);
    margin-top: 0.35rem;
}
.checkbox-row {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin-top: 0.6rem;
}
.field .checkbox-row label {
    display: inline;
    font-weight: normal;
    margin: 0;
}
.error-text {
    color: var(--p-red-500);
    margin: 0;
}
</style>
