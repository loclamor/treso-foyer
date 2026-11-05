<script setup>
import { computed, ref, watch } from 'vue';
import Dialog from 'primevue/dialog';
import Button from 'primevue/button';
import RadioButton from 'primevue/radiobutton';
import { useToast } from 'primevue/usetoast';
import CategoryPicker from '@/components/CategoryPicker.vue';
import MoneyAmount from '@/components/MoneyAmount.vue';
import { categorizeOperation } from '@/api/operations';
import { apiMessage } from '@/api/http';
import { formatDate } from '@/utils/format';

const props = defineProps({
    visible: { type: Boolean, default: false },
    operation: { type: Object, default: null }, // Operation à catégoriser
    defaultScope: { type: String, default: null }, // 'operation' | 'merchant' | null (auto)
});
const emit = defineEmits(['update:visible', 'done']);

const toast = useToast();

const categoryId = ref(null);
const scopeChoice = ref('operation');
const loading = ref(false);
const result = ref(null);

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
            result.value = null;
        }
    },
    { immediate: true },
);

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
