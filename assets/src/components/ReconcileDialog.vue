<script setup>
import { computed, ref, watch } from 'vue';
import Dialog from 'primevue/dialog';
import Button from 'primevue/button';
import Tag from 'primevue/tag';
import { useToast } from 'primevue/usetoast';
import MoneyAmount from '@/components/MoneyAmount.vue';
import { getOperation, reconciliationCandidates, reconcileOperation } from '@/api/operations';
import { apiMessage } from '@/api/http';
import { formatDate, formatMoney } from '@/utils/format';

// Appariement manuel d'une saisie en attente avec une ligne de relevé (les valeurs du relevé font foi).
const props = defineProps({
    visible: { type: Boolean, default: false },
    entry: { type: Object, default: null }, // saisie en attente (objet partiel accepté : rechargée via GET)
});
const emit = defineEmits(['update:visible', 'done']);

const toast = useToast();

const op = ref(null); // saisie rechargée (affichage à jour)
const candidates = ref([]);
const loading = ref(false);
const loadError = ref('');
const selectedId = ref(null);
const reconciling = ref(false);

const visible = computed({
    get: () => props.visible,
    set: (v) => emit('update:visible', v),
});

const selected = computed(() => candidates.value.find((c) => c.id === selectedId.value) ?? null);

// Avertissement si le candidat retenu n'a ni le même montant ni le même compte que la saisie.
const valuesDiffer = computed(
    () => !!selected.value && (selected.value.amount !== op.value?.amount || selected.value.account?.id !== op.value?.account?.id),
);

async function load() {
    if (!props.entry) return;
    loading.value = true;
    loadError.value = '';
    selectedId.value = null;
    candidates.value = [];
    try {
        const [full, raw] = await Promise.all([getOperation(props.entry.id), reconciliationCandidates(props.entry.id)]);
        op.value = full;
        candidates.value = Array.isArray(raw) ? raw : (raw.items ?? []);
        // Préselection seulement si le 1er candidat (tri serveur : montant exact d'abord) a le même
        // montant : sinon l'utilisateur choisit explicitement, pour éviter un rapprochement par réflexe.
        const first = candidates.value[0];
        if (first && first.amount === full.amount) selectedId.value = first.id;
    } catch (e) {
        loadError.value = apiMessage(e, 'Candidats indisponibles.');
    } finally {
        loading.value = false;
    }
}

watch(
    () => props.visible,
    (v) => {
        if (v) load();
    },
);

function select(id) {
    selectedId.value = id;
}

async function submit() {
    if (!op.value || !selectedId.value) return;
    reconciling.value = true;
    try {
        const res = await reconcileOperation(op.value.id, selectedId.value);
        toast.add({
            severity: 'success',
            summary: 'Rapprochement effectué',
            detail: `La saisie a été absorbée par la ligne de relevé #${res.operation?.id ?? selectedId.value}.`,
            life: 5000,
        });
        emit('done', res);
        visible.value = false;
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Rapprochement impossible', detail: apiMessage(e), life: 6000 });
    } finally {
        reconciling.value = false;
    }
}
</script>

<template>
    <Dialog
        v-model:visible="visible"
        modal
        header="Rapprocher la saisie avec le relevé"
        :style="{ width: 'min(36rem, 94vw)' }"
        :draggable="false"
    >
        <div v-if="op" class="reconcile-dialog">
            <p class="op-recap">
                <strong>Saisie :</strong>&#32;<MoneyAmount :amount="op.amount" />&#32;<span class="text-muted">le {{ formatDate(op.spentAt ?? op.date) }}</span>
                — <strong>{{ op.rawLabel }}</strong>&#32;<span class="text-muted">(compte {{ op.account?.alias ?? op.account?.numberMasked }})</span>
            </p>

            <p v-if="loadError" class="text-muted">{{ loadError }}</p>
            <p v-else-if="loading" class="text-muted">Recherche des lignes de relevé candidates…</p>
            <template v-else>
                <p v-if="!candidates.length" class="text-muted">
                    Aucune ligne de relevé candidate sur la période (de 5 jours avant à 30 jours après la saisie).
                </p>
                <ul v-else class="candidates" role="radiogroup" aria-label="Lignes de relevé candidates">
                    <li
                        v-for="c in candidates"
                        :key="c.id"
                        role="radio"
                        :aria-checked="c.id === selectedId"
                        tabindex="0"
                        class="candidate"
                        :class="{ selected: c.id === selectedId }"
                        @click="select(c.id)"
                        @keydown.enter.prevent="select(c.id)"
                        @keydown.space.prevent="select(c.id)"
                    >
                        <div class="candidate-main">
                            <span class="candidate-date">{{ formatDate(c.effectiveDate ?? c.date) }}</span>
                            <span class="candidate-account text-muted">{{ c.account?.alias ?? c.account?.numberMasked }}</span>
                            <MoneyAmount :amount="c.amount" />
                            <Tag
                                v-if="op && c.amount !== op.amount"
                                severity="warn"
                                :value="`écart ${formatMoney(c.amount - op.amount)}`"
                            />
                        </div>
                        <div class="candidate-label text-muted">
                            <strong v-if="c.merchant">{{ c.merchant }} — </strong>{{ c.rawLabel }}
                        </div>
                    </li>
                </ul>
                <p v-if="valuesDiffer" class="warn-line">
                    <i class="pi pi-exclamation-triangle" aria-hidden="true" />
                    Le montant ou le compte diffère : les valeurs du relevé seront conservées.
                </p>
            </template>
        </div>

        <template #footer>
            <Button label="Annuler" severity="secondary" text @click="visible = false" />
            <Button
                label="Rapprocher"
                icon="pi pi-link"
                :disabled="!selectedId || loading"
                :loading="reconciling"
                @click="submit"
            />
        </template>
    </Dialog>
</template>

<style scoped>
.op-recap {
    margin-top: 0;
}
.candidates {
    list-style: none;
    margin: 0.5rem 0;
    padding: 0;
    max-height: 50vh;
    overflow-y: auto;
    display: flex;
    flex-direction: column;
    gap: 0.4rem;
}
.candidate {
    border: 1px solid var(--p-content-border-color, var(--p-surface-border, #d4d4d8));
    border-radius: 0.5rem;
    padding: 0.5rem 0.75rem;
    cursor: pointer;
}
.candidate.selected {
    border-color: var(--p-primary-color);
    background: var(--p-primary-50, rgba(99, 102, 241, 0.08));
}
.app-dark .candidate.selected {
    background: var(--p-primary-950, rgba(99, 102, 241, 0.25));
}
.candidate-main {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    flex-wrap: wrap;
}
.candidate-date {
    font-weight: 600;
}
.candidate-label {
    font-size: 0.85rem;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.warn-line {
    color: var(--p-orange-600, #ea580c);
    font-size: 0.9rem;
}
.app-dark .warn-line {
    color: var(--p-orange-400, #fb923c);
}
</style>
