<script setup>
import { computed } from 'vue';
import Card from 'primevue/card';

// Tuile KPI (« hero number ») : valeur, libellé, variation vs période précédente
// (icône ▲/▼ + texte en encre — jamais la couleur seule), sous-texte.
const props = defineProps({
    label: { type: String, required: true },
    value: { type: String, required: true },
    subtext: { type: String, default: '' },
    deltaText: { type: String, default: '' }, // ex. « +12 % vs période précédente »
    deltaUp: { type: Boolean, default: true },
    favorable: { type: Boolean, default: null }, // true/false = sens favorable, null = neutre
});

// L'icône porte la couleur de statut ; le texte reste en encre.
const deltaIconStyle = computed(() => {
    if (props.favorable === null) return { color: 'var(--p-text-muted-color)' };
    return { color: props.favorable ? 'var(--p-green-500)' : 'var(--p-red-500)' };
});
</script>

<template>
    <Card class="kpi-tile">
        <template #content>
            <p class="kpi-label text-muted">{{ label }}</p>
            <p class="kpi-value">{{ value }}</p>
            <p v-if="deltaText" class="kpi-delta">
                <i :class="deltaUp ? 'pi pi-arrow-up' : 'pi pi-arrow-down'" :style="deltaIconStyle" aria-hidden="true" />
                <span>{{ deltaText }}</span>
            </p>
            <p v-if="subtext" class="kpi-sub text-muted">{{ subtext }}</p>
        </template>
    </Card>
</template>

<style scoped>
.kpi-tile :deep(.p-card-body) {
    padding: 1rem 1.25rem;
}
.kpi-label {
    margin: 0 0 0.25rem;
    font-size: 0.85rem;
}
.kpi-value {
    margin: 0;
    font-size: 1.7rem;
    font-weight: 700;
}
.kpi-delta {
    display: flex;
    align-items: center;
    gap: 0.35rem;
    margin: 0.25rem 0 0;
    font-size: 0.85rem;
}
.kpi-sub {
    margin: 0.15rem 0 0;
    font-size: 0.78rem;
}
</style>
