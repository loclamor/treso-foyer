<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import Button from 'primevue/button';
import Dialog from 'primevue/dialog';
import InputText from 'primevue/inputtext';
import InputNumber from 'primevue/inputnumber';
import Select from 'primevue/select';
import DatePicker from 'primevue/datepicker';
import Checkbox from 'primevue/checkbox';
import ToggleSwitch from 'primevue/toggleswitch';
import Tag from 'primevue/tag';
import { useToast } from 'primevue/usetoast';
import { useConfirm } from 'primevue/useconfirm';
import { useVehiclesStore } from '@/stores/vehicles';
import { useMembersStore } from '@/stores/members';
import { createVehicle, deleteVehicle, lookupStatus, lookupVehicle, updateVehicle } from '@/api/vehicles';
import { apiMessage } from '@/api/http';
import { centsToEuros, dateToIso, formatDate, parseEuros } from '@/utils/format';
import { VEHICLE_ENERGY_LABELS, VEHICLE_ENERGY_OPTIONS, VEHICLE_TYPE_ICONS, VEHICLE_TYPE_LABELS, VEHICLE_TYPE_OPTIONS } from '@/utils/vehicles';

const vehiclesStore = useVehiclesStore();
const membersStore = useMembersStore();
const toast = useToast();
const confirm = useConfirm();

const showArchived = ref(false);

// ---------- tableau ----------
function activityPeriod(v) {
    if (!v.acquiredAt && !v.disposedAt) return '—';
    return `${formatDate(v.acquiredAt)} → ${v.disposedAt ? formatDate(v.disposedAt) : 'en cours'}`;
}

async function toggleArchived(v) {
    try {
        await updateVehicle(v.id, { archived: !v.archived });
        toast.add({ severity: 'success', summary: v.archived ? 'Véhicule désarchivé' : 'Véhicule archivé', life: 3000 });
        await vehiclesStore.refresh(showArchived.value);
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Archivage impossible', detail: apiMessage(e), life: 6000 });
    }
}

function remove(v) {
    confirm.require({
        message: `Supprimer le véhicule « ${v.name} » ? Ses relevés kilométriques seront aussi supprimés.`,
        header: 'Suppression',
        icon: 'pi pi-exclamation-triangle',
        acceptLabel: 'Supprimer',
        rejectLabel: 'Annuler',
        acceptClass: 'p-button-danger',
        accept: async () => {
            try {
                await deleteVehicle(v.id);
                toast.add({ severity: 'success', summary: 'Véhicule supprimé', life: 3000 });
                await vehiclesStore.refresh(showArchived.value);
            } catch (e) {
                if (e.response?.status === 409) {
                    // vehicle_in_use : proposer l'archivage (le message API le suggère déjà).
                    confirm.require({
                        message: `${apiMessage(e)} Voulez-vous l’archiver à la place ?`,
                        header: 'Véhicule utilisé',
                        icon: 'pi pi-exclamation-triangle',
                        acceptLabel: 'Archiver',
                        rejectLabel: 'Annuler',
                        accept: async () => {
                            try {
                                await updateVehicle(v.id, { archived: true });
                                toast.add({ severity: 'success', summary: 'Véhicule archivé', life: 3000 });
                                await vehiclesStore.refresh(showArchived.value);
                            } catch (e2) {
                                toast.add({ severity: 'error', summary: 'Archivage impossible', detail: apiMessage(e2), life: 6000 });
                            }
                        },
                    });
                } else {
                    toast.add({ severity: 'error', summary: 'Suppression impossible', detail: apiMessage(e), life: 6000 });
                }
            }
        },
    });
}

// ---------- formulaire création / édition ----------
const dialogVisible = ref(false);
const dialogMode = ref('create'); // 'create' | 'edit'
const editingId = ref(null);
const saving = ref(false);
const form = reactive({
    name: '',
    type: 'car',
    plate: '',
    make: '',
    model: '',
    energy: null,
    fiscalPower: null,
    firstRegistrationAt: null,
    vin: '',
    acquiredAt: null,
    disposedAt: null,
    purchasePriceEuros: '',
    mainDriverId: null,
    archived: false,
});
// Erreurs par champ (422 violations + 409 name_exists/plate_exists).
const fieldErrors = reactive({});

function clearErrors() {
    for (const k of Object.keys(fieldErrors)) delete fieldErrors[k];
}

function applyErrors(e) {
    clearErrors();
    const data = e.response?.data;
    if (Array.isArray(data?.violations)) {
        for (const v of data.violations) {
            // le formulaire saisit le prix en euros, l'API valide « purchasePrice » en centimes
            fieldErrors[v.field === 'purchasePrice' ? 'purchasePriceEuros' : v.field] = v.message;
        }
    } else if (e.response?.status === 409 && data?.error) {
        if (data.error === 'plate_exists') fieldErrors.plate = data.message;
        else if (data.error === 'name_exists') fieldErrors.name = data.message;
    }
}

function isoToDate(iso) {
    if (!iso) return null;
    const [y, m, d] = String(iso).split('-').map(Number);
    return y && m && d ? new Date(y, m - 1, d) : null;
}

function openCreate() {
    dialogMode.value = 'create';
    editingId.value = null;
    Object.assign(form, {
        name: '', type: 'car', plate: '', make: '', model: '', energy: null, fiscalPower: null,
        firstRegistrationAt: null, vin: '', acquiredAt: null, disposedAt: null,
        purchasePriceEuros: '', mainDriverId: null, archived: false,
    });
    suggestion.value = null;
    clearErrors();
    dialogVisible.value = true;
}

function openEdit(v) {
    dialogMode.value = 'edit';
    editingId.value = v.id;
    Object.assign(form, {
        name: v.name, type: v.type, plate: v.plate ?? '', make: v.make ?? '', model: v.model ?? '',
        energy: v.energy ?? null, fiscalPower: v.fiscalPower ?? null,
        firstRegistrationAt: isoToDate(v.firstRegistrationAt), vin: v.vin ?? '',
        acquiredAt: isoToDate(v.acquiredAt), disposedAt: isoToDate(v.disposedAt),
        purchasePriceEuros: centsToEuros(v.purchasePrice), mainDriverId: v.mainDriver?.id ?? null,
        archived: !!v.archived,
    });
    suggestion.value = null;
    clearErrors();
    dialogVisible.value = true;
}

// Chaînes vides = remise à null côté back ; mainDriverId null explicite = retrait du conducteur.
function buildPayload() {
    const price = parseEuros(form.purchasePriceEuros);
    return {
        name: form.name.trim(),
        type: form.type,
        plate: form.plate.trim(),
        make: form.make.trim(),
        model: form.model.trim(),
        energy: form.energy || null, // null = inchangé (PATCH), ignoré (POST) : l'API ne permet pas de vider energy
        fiscalPower: form.fiscalPower ?? null,
        firstRegistrationAt: form.firstRegistrationAt ? dateToIso(form.firstRegistrationAt) : '',
        vin: form.vin.trim(),
        acquiredAt: form.acquiredAt ? dateToIso(form.acquiredAt) : '',
        disposedAt: form.disposedAt ? dateToIso(form.disposedAt) : '',
        purchasePrice: price === null ? null : Math.abs(price), // centimes
        mainDriverId: form.mainDriverId ?? null,
        archived: form.archived,
    };
}

async function submit() {
    if (!form.name.trim()) return;
    saving.value = true;
    clearErrors();
    try {
        if (dialogMode.value === 'create') {
            await createVehicle(buildPayload());
            toast.add({ severity: 'success', summary: 'Véhicule créé', life: 3000 });
        } else {
            await updateVehicle(editingId.value, buildPayload());
            toast.add({ severity: 'success', summary: 'Véhicule mis à jour', life: 3000 });
        }
        dialogVisible.value = false;
        await vehiclesStore.refresh(showArchived.value);
    } catch (e) {
        applyErrors(e);
        toast.add({
            severity: e.response?.status === 409 ? 'warn' : 'error',
            summary: 'Enregistrement impossible',
            detail: apiMessage(e),
            life: 8000,
        });
    } finally {
        saving.value = false;
    }
}

// ---------- pré-remplissage depuis la plaque (service externe) ----------
const lookupEnabled = ref(false);
const lookupLoading = ref(false);
const suggestion = ref(null); // suggestion de POST /api/vehicles/lookup

const suggestionRows = computed(() => {
    const s = suggestion.value;
    if (!s) return [];
    const rows = [];
    if (s.make) rows.push({ label: 'Marque', value: s.make });
    const model = [s.model, s.version].filter(Boolean).join(' ');
    if (model) rows.push({ label: 'Modèle', value: model });
    if (s.energy) rows.push({ label: 'Énergie', value: VEHICLE_ENERGY_LABELS[s.energy] ?? s.energy });
    if (s.fiscalPower != null) rows.push({ label: 'Puissance fiscale', value: `${s.fiscalPower} CV` });
    if (s.firstRegistrationAt) rows.push({ label: '1re mise en circulation', value: formatDate(s.firstRegistrationAt) });
    if (s.vin) rows.push({ label: 'VIN', value: s.vin });
    return rows;
});

function askLookup() {
    if (!form.plate.trim()) return;
    // Confirmation explicite : la plaque part chez un service externe (donnée privée, docs/adr/0007).
    confirm.require({
        header: 'Pré-remplir depuis la plaque',
        message: 'La plaque sera envoyée à un service externe de recherche de véhicules. Continuer ?',
        icon: 'pi pi-external-link',
        acceptLabel: 'Envoyer la plaque',
        rejectLabel: 'Annuler',
        accept: doLookup,
    });
}

async function doLookup() {
    lookupLoading.value = true;
    suggestion.value = null;
    try {
        const res = await lookupVehicle(form.plate.trim(), editingId.value);
        suggestion.value = res.suggestion;
        if (res.plate) form.plate = res.plate; // plaque renvoyée normalisée
        toast.add({ severity: 'success', summary: 'Véhicule trouvé', detail: 'Vérifiez la suggestion puis appliquez-la.', life: 5000 });
    } catch (e) {
        const status = e.response?.status;
        if (status === 422) {
            applyErrors(e); // format de plaque invalide
        } else {
            // 404 not_found, 429 quota, 502 unavailable/invalid_response, 503 lookup_disabled
            toast.add({ severity: 'error', summary: 'Recherche par plaque impossible', detail: apiMessage(e), life: 8000 });
        }
    } finally {
        lookupLoading.value = false;
    }
}

// Remplit les champs vides du formulaire ; une valeur déjà saisie n'est écrasée qu'après confirmation.
function applySuggestion() {
    const s = suggestion.value;
    if (!s) return;
    const targets = [
        { key: 'make', label: 'Marque', value: s.make || null },
        { key: 'model', label: 'Modèle', value: [s.model, s.version].filter(Boolean).join(' ') || null },
        { key: 'energy', label: 'Énergie', value: s.energy && VEHICLE_ENERGY_LABELS[s.energy] ? s.energy : null },
        { key: 'fiscalPower', label: 'Puissance fiscale', value: s.fiscalPower ?? null },
        { key: 'firstRegistrationAt', label: '1re mise en circulation', value: isoToDate(s.firstRegistrationAt) },
        { key: 'vin', label: 'VIN', value: s.vin || null },
    ].filter((t) => t.value !== null);
    const conflicts = [];
    const apply = (overwrite) => {
        for (const t of targets) {
            const current = form[t.key];
            const empty = current === null || current === undefined || current === '';
            if (empty || overwrite) form[t.key] = t.value;
            else conflicts.push(t.label);
        }
    };
    apply(false);
    if (conflicts.length) {
        confirm.require({
            header: 'Appliquer la suggestion',
            message: `Écraser aussi les champs déjà renseignés : ${conflicts.join(', ')} ?`,
            acceptLabel: 'Écraser',
            rejectLabel: 'Laisser tels quels',
            accept: () => apply(true),
        });
    }
    toast.add({ severity: 'success', summary: 'Suggestion appliquée', detail: 'Vérifiez puis enregistrez le véhicule.', life: 4000 });
}

// ---------- init ----------
watch(showArchived, (v) => vehiclesStore.refresh(v).catch(() => {}));

onMounted(async () => {
    try {
        await Promise.all([vehiclesStore.refresh(showArchived.value), membersStore.refresh()]);
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Véhicules illisibles', detail: apiMessage(e), life: 6000 });
    }
    try {
        const status = await lookupStatus();
        lookupEnabled.value = !!status.enabled;
    } catch {
        lookupEnabled.value = false; // bouton de lookup masqué si le statut est illisible
    }
});
</script>

<template>
    <div>
        <div class="page-header">
            <h1>Véhicules</h1>
            <div class="header-tools">
                <div class="field-check">
                    <ToggleSwitch id="show-archived" v-model="showArchived" />
                    <label for="show-archived">Afficher les archivés</label>
                </div>
                <Button label="Nouveau véhicule" icon="pi pi-plus" @click="openCreate" />
            </div>
        </div>

        <DataTable :value="vehiclesStore.list" data-key="id">
            <template #empty>Aucun véhicule</template>
            <Column header="Nom">
                <template #body="{ data }">
                    <RouterLink :to="{ name: 'vehicle', params: { id: data.id } }" class="vehicle-link">
                        <i :class="VEHICLE_TYPE_ICONS[data.type] ?? 'pi pi-car'" aria-hidden="true" /> {{ data.name }}
                    </RouterLink>
                    <Tag v-if="data.archived" value="Archivé" severity="secondary" class="archived-tag" />
                </template>
            </Column>
            <Column header="Type" style="width: 8rem">
                <template #body="{ data }">{{ VEHICLE_TYPE_LABELS[data.type] ?? data.type }}</template>
            </Column>
            <Column header="Marque / modèle" style="width: 14rem">
                <template #body="{ data }">
                    <span v-if="data.make || data.model">{{ [data.make, data.model].filter(Boolean).join(' ') }}</span>
                    <span v-else class="text-muted">—</span>
                </template>
            </Column>
            <Column header="Énergie" style="width: 9rem">
                <template #body="{ data }">
                    <span v-if="data.energy">{{ VEHICLE_ENERGY_LABELS[data.energy] ?? data.energy }}</span>
                    <span v-else class="text-muted">—</span>
                </template>
            </Column>
            <Column header="Période d'activité" style="width: 14rem">
                <template #body="{ data }">{{ activityPeriod(data) }}</template>
            </Column>
            <Column header="Dernier relevé" style="width: 12rem">
                <template #body="{ data }">
                    <span v-if="data.lastOdometer">
                        {{ data.lastOdometer.km.toLocaleString('fr-FR') }} km
                        <span class="text-muted">au {{ formatDate(data.lastOdometer.date) }}</span>
                    </span>
                    <span v-else class="text-muted">—</span>
                </template>
            </Column>
            <Column header="Nb opérations" style="width: 8rem">
                <template #body="{ data }">
                    <RouterLink :to="{ name: 'operations', query: { vehicleId: data.id } }">{{ data.operationsCount }}</RouterLink>
                </template>
            </Column>
            <Column header="Actions" style="width: 13rem">
                <template #body="{ data }">
                    <div class="row-actions">
                        <Button icon="pi pi-eye" text rounded size="small" :aria-label="'Voir la fiche de ' + data.name" @click="$router.push({ name: 'vehicle', params: { id: data.id } })" />
                        <Button icon="pi pi-pencil" text rounded size="small" :aria-label="'Modifier ' + data.name" @click="openEdit(data)" />
                        <Button
                            :icon="data.archived ? 'pi pi-eye' : 'pi pi-eye-slash'"
                            text
                            rounded
                            size="small"
                            :aria-label="(data.archived ? 'Désarchiver ' : 'Archiver ') + data.name"
                            @click="toggleArchived(data)"
                        />
                        <Button icon="pi pi-trash" text rounded size="small" severity="danger" :aria-label="'Supprimer ' + data.name" @click="remove(data)" />
                    </div>
                </template>
            </Column>
        </DataTable>

        <Dialog
            v-model:visible="dialogVisible"
            modal
            :header="dialogMode === 'create' ? 'Nouveau véhicule' : 'Modifier le véhicule'"
            :style="{ width: '36rem' }"
            :draggable="false"
        >
            <div class="form">
                <div class="field">
                    <label for="veh-name">Nom</label>
                    <InputText id="veh-name" v-model="form.name" class="w-full" :invalid="!!fieldErrors.name" required />
                    <small v-if="fieldErrors.name" class="field-error">{{ fieldErrors.name }}</small>
                </div>
                <div class="form-row">
                    <div class="field">
                        <label for="veh-type">Type</label>
                        <Select id="veh-type" v-model="form.type" :options="VEHICLE_TYPE_OPTIONS" option-label="label" option-value="value" class="w-full" />
                    </div>
                    <div class="field">
                        <label for="veh-energy">Énergie</label>
                        <Select
                            id="veh-energy"
                            v-model="form.energy"
                            :options="VEHICLE_ENERGY_OPTIONS"
                            option-label="label"
                            option-value="value"
                            show-clear
                            placeholder="Non renseignée"
                            class="w-full"
                        />
                    </div>
                </div>
                <div class="field">
                    <label for="veh-plate">Plaque d'immatriculation</label>
                    <div class="plate-row">
                        <InputText id="veh-plate" v-model="form.plate" class="w-full" placeholder="AA-123-AA" :invalid="!!fieldErrors.plate" />
                        <Button
                            v-if="lookupEnabled"
                            label="Pré-remplir depuis la plaque"
                            icon="pi pi-search"
                            size="small"
                            outlined
                            severity="secondary"
                            :loading="lookupLoading"
                            :disabled="!form.plate.trim()"
                            @click="askLookup"
                        />
                    </div>
                    <small v-if="fieldErrors.plate" class="field-error">{{ fieldErrors.plate }}</small>
                    <small v-else class="text-muted">Normalisée automatiquement (SIV « AA-123-AA », FNI sans séparateurs). Jamais journalisée.</small>
                </div>

                <div v-if="suggestion" class="lookup-result">
                    <h3>Suggestion du service externe</h3>
                    <dl>
                        <div v-for="row in suggestionRows" :key="row.label" class="lookup-row">
                            <dt class="text-muted">{{ row.label }}</dt>
                            <dd>{{ row.value }}</dd>
                        </div>
                    </dl>
                    <Button label="Appliquer la suggestion" icon="pi pi-check" size="small" outlined @click="applySuggestion" />
                </div>

                <div class="form-row">
                    <div class="field">
                        <label for="veh-make">Marque</label>
                        <InputText id="veh-make" v-model="form.make" class="w-full" />
                    </div>
                    <div class="field">
                        <label for="veh-model">Modèle</label>
                        <InputText id="veh-model" v-model="form.model" class="w-full" />
                    </div>
                </div>
                <div class="form-row">
                    <div class="field">
                        <label for="veh-fiscal">Puissance fiscale (CV)</label>
                        <InputNumber id="veh-fiscal" v-model="form.fiscalPower" :use-grouping="false" :min="0" :max="99" class="w-full" />
                    </div>
                    <div class="field">
                        <label for="veh-price">Prix d'achat (€)</label>
                        <InputText id="veh-price" v-model="form.purchasePriceEuros" inputmode="decimal" placeholder="0,00" class="w-full" :invalid="!!fieldErrors.purchasePriceEuros" />
                        <small v-if="fieldErrors.purchasePriceEuros" class="field-error">{{ fieldErrors.purchasePriceEuros }}</small>
                    </div>
                </div>
                <div class="field">
                    <label for="veh-first-reg">1re mise en circulation</label>
                    <DatePicker
                        input-id="veh-first-reg"
                        v-model="form.firstRegistrationAt"
                        date-format="dd/mm/yy"
                        show-icon
                        show-button-bar
                        class="w-full"
                    />
                </div>
                <div class="field">
                    <label for="veh-vin">VIN</label>
                    <InputText id="veh-vin" v-model="form.vin" class="w-full" maxlength="17" />
                </div>
                <div class="form-row">
                    <div class="field">
                        <label for="veh-acquired">Date d'acquisition</label>
                        <DatePicker
                            input-id="veh-acquired"
                            v-model="form.acquiredAt"
                            date-format="dd/mm/yy"
                            show-icon
                            show-button-bar
                            class="w-full"
                        />
                    </div>
                    <div class="field">
                        <label for="veh-disposed">Date de cession</label>
                        <DatePicker
                            input-id="veh-disposed"
                            v-model="form.disposedAt"
                            date-format="dd/mm/yy"
                            show-icon
                            show-button-bar
                            class="w-full"
                        />
                    </div>
                </div>
                <div class="field">
                    <label for="veh-driver">Conducteur principal</label>
                    <Select
                        id="veh-driver"
                        v-model="form.mainDriverId"
                        :options="membersStore.list"
                        option-label="displayName"
                        option-value="id"
                        show-clear
                        placeholder="Aucun"
                        class="w-full"
                    />
                </div>
                <div class="field field-check">
                    <Checkbox id="veh-archived" v-model="form.archived" binary />
                    <label for="veh-archived">Véhicule archivé (n'est plus proposé à l'affectation automatique)</label>
                </div>
            </div>
            <template #footer>
                <Button label="Annuler" severity="secondary" text @click="dialogVisible = false" />
                <Button
                    :label="dialogMode === 'create' ? 'Créer' : 'Enregistrer'"
                    icon="pi pi-check"
                    :loading="saving"
                    :disabled="!form.name.trim()"
                    @click="submit"
                />
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
    flex-wrap: wrap;
}
.header-tools {
    display: flex;
    align-items: center;
    gap: 1rem;
    flex-wrap: wrap;
}
.field-check {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}
.field-check label {
    font-weight: 400;
}
.vehicle-link {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    font-weight: 600;
}
.archived-tag {
    margin-left: 0.4rem;
    font-size: 0.75rem;
}
.row-actions {
    display: flex;
    gap: 0.15rem;
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
.plate-row {
    display: flex;
    gap: 0.5rem;
    align-items: center;
}
.plate-row .p-button {
    flex: none;
    white-space: nowrap;
}
.field-error {
    display: block;
    margin-top: 0.3rem;
    color: var(--p-red-500);
}
.lookup-result {
    border: 1px solid var(--p-content-border-color);
    border-radius: var(--p-border-radius);
    background: var(--p-content-background);
    padding: 0.75rem 1rem;
    margin-bottom: 1rem;
}
.lookup-result h3 {
    margin: 0 0 0.5rem;
    font-size: 0.95rem;
}
.lookup-row {
    display: flex;
    gap: 0.75rem;
    font-size: 0.9rem;
}
.lookup-row dt {
    min-width: 11rem;
}
.lookup-row dd {
    margin: 0;
}
</style>
