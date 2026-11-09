<script setup>
import { computed, ref, useId, watch } from 'vue';
import InputText from 'primevue/inputtext';
import InputNumber from 'primevue/inputnumber';
import Checkbox from 'primevue/checkbox';
import { centsToEuros, mlToLiters, parseEuros, parseLiters, parsePricePerLiter } from '@/utils/format';

// Champs d'un plein de carburant (ADR 0015), réutilisables (saisie manuelle, catégorisation).
// - modelValue = {volumeMl, fullTank, odometerKm} (volumeMl null = pas de plein) ;
// - amountCents = montant de l'opération (centimes signés) ; amountEditable = true ⇒ le champ
//   Montant est rendu ici et toute modification dérivée est émise via update:amountCents.
// Liaison des trois grandeurs : on mémorise les deux derniers champs modifiés (montant, volume,
// prix au litre) et on recalcule le troisième — prix = |montant| / L, volume = |montant| / prix,
// montant = volume × prix arrondi au centime. Sans montant éditable (ligne de relevé), seuls
// volume ↔ prix au litre sont liés (le montant sert de pivot).

const props = defineProps({
    amountCents: { type: Number, default: null },
    amountEditable: { type: Boolean, default: false },
    modelValue: { type: Object, default: () => ({ volumeMl: null, fullTank: true, odometerKm: null }) },
});
const emit = defineEmits(['update:modelValue', 'update:amountCents']);

const uid = `fuel-${useId()}`;

const volumeMl = ref(null);
const fullTank = ref(true);
const odometerKm = ref(null);
const priceEur = ref(null); // €/L saisi ou dérivé (pas stocké côté API)
const amountLocal = ref(null); // montant dérivé quand amountEditable
const volumeText = ref('');
const priceText = ref('');
const amountText = ref('');
const lastEdited = ref([]); // deux derniers champs modifiés, plus récent d'abord

const amountEff = computed(() => amountLocal.value ?? props.amountCents);

function emitModel() {
    emit('update:modelValue', {
        volumeMl: volumeMl.value,
        fullTank: fullTank.value,
        // Sans volume, pas de kilométrage (422 API sinon).
        odometerKm: volumeMl.value ? odometerKm.value : null,
    });
}

function syncPriceFromAmountVolume() {
    const abs = amountEff.value !== null ? Math.abs(amountEff.value) : null;
    if (abs !== null && volumeMl.value) {
        priceEur.value = Number(((abs * 10) / volumeMl.value).toFixed(3));
    }
}

// Synchronisation depuis le parent (ouverture du dialogue / pré-remplissage). Un écho de notre
// propre émission (mêmes valeurs) ne réinitialise pas la mémoire des champs édités.
watch(
    () => props.modelValue,
    (mv) => {
        const nextVol = mv?.volumeMl ?? null;
        const nextFull = mv?.fullTank ?? true;
        const nextKm = mv?.odometerKm ?? null;
        if (nextVol === volumeMl.value && nextFull === fullTank.value && nextKm === odometerKm.value) return;
        volumeMl.value = nextVol;
        fullTank.value = nextFull;
        odometerKm.value = nextKm;
        lastEdited.value = [];
        amountLocal.value = null;
        priceEur.value = null;
        syncPriceFromAmountVolume();
    },
    { immediate: true, deep: true },
);

// Le montant change depuis le parent (ex. sens) : on oublie la dérivation locale du montant.
watch(
    () => props.amountCents,
    () => {
        amountLocal.value = null;
        if (!lastEdited.value.includes('price')) syncPriceFromAmountVolume();
    },
);

// Réécriture des champs texte quand la valeur dérivée change (sans écraser la saisie en cours).
// immediate : le pré-remplissage (watch modelValue ci-dessus) a lieu avant leur déclaration.
watch(
    volumeMl,
    (ml) => {
        if (parseLiters(volumeText.value) !== ml) volumeText.value = ml === null ? '' : mlToLiters(ml);
    },
    { immediate: true },
);
watch(
    priceEur,
    (p) => {
        if (parsePricePerLiter(priceText.value) !== p) priceText.value = p === null ? '' : p.toFixed(3).replace('.', ',');
    },
    { immediate: true },
);
watch(
    amountEff,
    (c) => {
        const parsed = parseEuros(amountText.value);
        const absParsed = parsed !== null ? Math.abs(parsed) : null;
        const absC = c !== null ? Math.abs(c) : null;
        if (absParsed !== absC) amountText.value = c !== null ? centsToEuros(Math.abs(c)) : '';
    },
    { immediate: true },
);

function derive(target) {
    const abs = amountEff.value !== null ? Math.abs(amountEff.value) : null;
    if (target === 'price' && abs !== null && volumeMl.value) {
        priceEur.value = Number(((abs * 10) / volumeMl.value).toFixed(3));
    } else if (target === 'volume' && abs !== null && priceEur.value) {
        volumeMl.value = Math.max(1, Math.round((abs * 10) / priceEur.value));
    } else if (target === 'amount' && volumeMl.value && priceEur.value) {
        const cents = Math.round((volumeMl.value * priceEur.value) / 10);
        const signed = props.amountCents !== null && props.amountCents > 0 ? cents : -cents;
        amountLocal.value = signed;
        emit('update:amountCents', signed);
    }
    emitModel();
}

// Deux derniers champs modifiés = source de vérité ; le troisième est recalculé.
function onEdited(field) {
    lastEdited.value = [field, ...lastEdited.value.filter((f) => f !== field)].slice(0, 2);
    const known = {
        amount: amountEff.value !== null,
        volume: volumeMl.value !== null,
        price: priceEur.value !== null,
    };
    const sources = [...lastEdited.value];
    for (const f of ['amount', 'volume', 'price']) {
        if (sources.length >= 2) break;
        if (!sources.includes(f) && known[f]) sources.push(f);
    }
    if (sources.length < 2) return;
    let target = ['amount', 'volume', 'price'].find((f) => !sources.includes(f));
    if (target === 'amount' && !props.amountEditable) {
        // Montant imposé (ligne de relevé) : la saisie la plus récente l'emporte, l'autre est déduite.
        target = lastEdited.value[1] ?? (sources.includes('volume') ? 'price' : 'volume');
    }
    derive(target);
}

function onAmountInput(v) {
    amountText.value = v;
    if (v.trim() === '') {
        amountLocal.value = null;
        emit('update:amountCents', null);
        return;
    }
    const parsed = parseEuros(v);
    if (parsed === null || parsed === 0) return;
    const signed = props.amountCents !== null && props.amountCents > 0 ? Math.abs(parsed) : -Math.abs(parsed);
    amountLocal.value = signed;
    emit('update:amountCents', signed);
    onEdited('amount');
}

function onVolumeInput(v) {
    volumeText.value = v;
    if (v.trim() === '') {
        volumeMl.value = null;
        emitModel();
        return;
    }
    const ml = parseLiters(v);
    if (ml === null || ml === volumeMl.value) return;
    volumeMl.value = ml;
    onEdited('volume');
    emitModel();
}

function onPriceInput(v) {
    priceText.value = v;
    if (v.trim() === '') {
        priceEur.value = null;
        return;
    }
    const p = parsePricePerLiter(v);
    if (p === null || p === priceEur.value) return;
    priceEur.value = p;
    onEdited('price');
}

function onOdometer(v) {
    odometerKm.value = v;
    emitModel();
}
</script>

<template>
    <div class="fuel-fields">
        <div v-if="amountEditable" class="field">
            <label :for="`${uid}-amount`">Montant (€)</label>
            <InputText
                :id="`${uid}-amount`"
                :model-value="amountText"
                inputmode="decimal"
                placeholder="0,00"
                class="w-full"
                @update:model-value="onAmountInput"
            />
        </div>
        <div class="fuel-grid">
            <div class="field">
                <label :for="`${uid}-volume`">Volume (L)</label>
                <InputText
                    :id="`${uid}-volume`"
                    :model-value="volumeText"
                    inputmode="decimal"
                    placeholder="0,00"
                    class="w-full"
                    @update:model-value="onVolumeInput"
                />
            </div>
            <div class="field">
                <label :for="`${uid}-price`">Prix au litre (€/L)</label>
                <InputText
                    :id="`${uid}-price`"
                    :model-value="priceText"
                    inputmode="decimal"
                    placeholder="0,000"
                    class="w-full"
                    @update:model-value="onPriceInput"
                />
            </div>
        </div>
        <div class="field field-check">
            <Checkbox :id="`${uid}-full`" v-model="fullTank" binary @update:model-value="emitModel" />
            <label :for="`${uid}-full`">Plein complet</label>
        </div>
        <small class="field-hint text-muted">Décochez si le plein a été limité (plafond de carte).</small>
        <div class="field">
            <label :for="`${uid}-km`">Kilométrage au compteur (facultatif)</label>
            <InputNumber
                :id="`${uid}-km`"
                :model-value="odometerKm"
                :use-grouping="true"
                :min="0"
                :max-fraction-digits="0"
                suffix=" km"
                class="w-full"
                :disabled="!volumeMl"
                @update:model-value="onOdometer"
            />
        </div>
    </div>
</template>

<style scoped>
.fuel-fields {
    border: 1px solid var(--p-content-border-color);
    border-radius: 0.5rem;
    padding: 0.75rem 0.9rem 0.25rem;
    margin-bottom: 1rem;
}
.fuel-fields .field {
    margin-bottom: 0.75rem;
}
.fuel-fields label {
    display: block;
    font-weight: 600;
    margin-bottom: 0.3rem;
    font-size: 0.9rem;
}
.fuel-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.75rem;
}
.field-check {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}
.field-check label {
    margin-bottom: 0;
    font-weight: 400;
}
.field-hint {
    display: block;
    margin: -0.45rem 0 0.75rem;
    font-size: 0.78rem;
}
</style>
