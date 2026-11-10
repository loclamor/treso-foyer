<script setup>
import { computed, ref, useId, watch } from 'vue';
import InputText from 'primevue/inputtext';
import { centsToEuros, gToKg, parseEuros, parseKg, parsePricePerKg } from '@/utils/format';

// Champs d'un achat de gaz en bouteille (ADR 0017), réutilisables (saisie manuelle, catégorisation).
// - modelValue = {massG} (masse en grammes ; null = pas d'achat de gaz) ;
// - amountCents = montant de l'opération (centimes signés) ; amountEditable = true ⇒ le champ
//   Montant est rendu ici et toute modification dérivée est émise via update:amountCents.
// Liaison des trois grandeurs : on mémorise les deux derniers champs modifiés (montant, masse,
// prix au kg) et on recalcule le troisième — prix = |montant| / kg, masse = |montant| / prix,
// montant = masse × prix arrondi au centime. Sans montant éditable (ligne de relevé), seuls
// masse ↔ prix au kg sont liés (le montant sert de pivot).

const props = defineProps({
    amountCents: { type: Number, default: null },
    amountEditable: { type: Boolean, default: false },
    modelValue: { type: Object, default: () => ({ massG: null }) },
});
const emit = defineEmits(['update:modelValue', 'update:amountCents']);

const uid = `gas-${useId()}`;

const massG = ref(null);
const priceEur = ref(null); // €/kg saisi ou dérivé (pas stocké côté API)
const amountLocal = ref(null); // montant dérivé quand amountEditable
const massText = ref('');
const priceText = ref('');
const amountText = ref('');
const lastEdited = ref([]); // deux derniers champs modifiés, plus récent d'abord

const amountEff = computed(() => amountLocal.value ?? props.amountCents);

function emitModel() {
    emit('update:modelValue', { massG: massG.value });
}

function syncPriceFromAmountMass() {
    const abs = amountEff.value !== null ? Math.abs(amountEff.value) : null;
    if (abs !== null && massG.value) {
        priceEur.value = Number(((abs * 10) / massG.value).toFixed(3));
    }
}

// Synchronisation depuis le parent (ouverture du dialogue / pré-remplissage). Un écho de notre
// propre émission (mêmes valeurs) ne réinitialise pas la mémoire des champs édités.
watch(
    () => props.modelValue,
    (mv) => {
        const nextMass = mv?.massG ?? null;
        if (nextMass === massG.value) return;
        massG.value = nextMass;
        lastEdited.value = [];
        amountLocal.value = null;
        priceEur.value = null;
        syncPriceFromAmountMass();
    },
    { immediate: true, deep: true },
);

// Le montant change depuis le parent (ex. sens) : on oublie la dérivation locale du montant.
watch(
    () => props.amountCents,
    () => {
        amountLocal.value = null;
        if (!lastEdited.value.includes('price')) syncPriceFromAmountMass();
    },
);

// Réécriture des champs texte quand la valeur dérivée change (sans écraser la saisie en cours).
watch(
    massG,
    (g) => {
        if (parseKg(massText.value) !== g) massText.value = g === null ? '' : gToKg(g);
    },
    { immediate: true },
);
watch(
    priceEur,
    (p) => {
        if (parsePricePerKg(priceText.value) !== p) priceText.value = p === null ? '' : p.toFixed(3).replace('.', ',');
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
    if (target === 'price' && abs !== null && massG.value) {
        priceEur.value = Number(((abs * 10) / massG.value).toFixed(3));
    } else if (target === 'mass' && abs !== null && priceEur.value) {
        massG.value = Math.max(1, Math.round((abs * 10) / priceEur.value));
    } else if (target === 'amount' && massG.value && priceEur.value) {
        const cents = Math.round((massG.value * priceEur.value) / 10);
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
        mass: massG.value !== null,
        price: priceEur.value !== null,
    };
    const sources = [...lastEdited.value];
    for (const f of ['amount', 'mass', 'price']) {
        if (sources.length >= 2) break;
        if (!sources.includes(f) && known[f]) sources.push(f);
    }
    if (sources.length < 2) return;
    let target = ['amount', 'mass', 'price'].find((f) => !sources.includes(f));
    if (target === 'amount' && !props.amountEditable) {
        // Montant imposé (ligne de relevé) : la saisie la plus récente l'emporte, l'autre est déduite.
        target = lastEdited.value[1] ?? (sources.includes('mass') ? 'price' : 'mass');
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

function onMassInput(v) {
    massText.value = v;
    if (v.trim() === '') {
        massG.value = null;
        emitModel();
        return;
    }
    const g = parseKg(v);
    if (g === null || g === massG.value) return;
    massG.value = g;
    onEdited('mass');
    emitModel();
}

function onPriceInput(v) {
    priceText.value = v;
    if (v.trim() === '') {
        priceEur.value = null;
        return;
    }
    const p = parsePricePerKg(v);
    if (p === null || p === priceEur.value) return;
    priceEur.value = p;
    onEdited('price');
}
</script>

<template>
    <div class="gas-fields">
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
        <div class="gas-grid">
            <div class="field">
                <label :for="`${uid}-mass`">Masse (kg)</label>
                <InputText
                    :id="`${uid}-mass`"
                    :model-value="massText"
                    inputmode="decimal"
                    placeholder="0,00"
                    class="w-full"
                    @update:model-value="onMassInput"
                />
            </div>
            <div class="field">
                <label :for="`${uid}-price`">Prix au kg (€/kg)</label>
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
        <small class="field-hint text-muted">Bonbonne de gaz : ex. 13 kg pour une bouteille standard.</small>
    </div>
</template>

<style scoped>
.gas-fields {
    border: 1px solid var(--p-content-border-color);
    border-radius: 0.5rem;
    padding: 0.75rem 0.9rem 0.25rem;
    margin-bottom: 1rem;
}
.gas-fields .field {
    margin-bottom: 0.75rem;
}
.gas-fields label {
    display: block;
    font-weight: 600;
    margin-bottom: 0.3rem;
    font-size: 0.9rem;
}
.gas-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.75rem;
}
.field-hint {
    display: block;
    margin: -0.45rem 0 0.75rem;
    font-size: 0.78rem;
}
</style>
