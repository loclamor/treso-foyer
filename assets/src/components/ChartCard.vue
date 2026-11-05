<script setup>
import { ref, watch } from 'vue';
import Card from 'primevue/card';
import Button from 'primevue/button';
import ProgressBar from 'primevue/progressbar';

// Carte graphique : titre, sous-titre, actions, bascule Graphique ↔ Tableau,
// états chargement / vide / erreur. Pendant un rechargement, l'ancien graphique reste
// affiché estompé (pas de squelette, pas de saut de layout).
const props = defineProps({
    title: { type: String, required: true },
    subtitle: { type: String, default: '' },
    ariaLabel: { type: String, default: '' },
    loading: { type: Boolean, default: false },
    error: { type: String, default: '' },
    empty: { type: Boolean, default: false },
    emptyText: { type: String, default: 'Aucune donnée sur la période.' },
    height: { type: Number, default: 340 }, // hauteur totale, axe X inclus
    tableAvailable: { type: Boolean, default: true },
});
const emit = defineEmits(['retry']);

const view = ref('chart'); // 'chart' | 'table'
const hasRendered = ref(false);

watch(
    () => [props.loading, props.error, props.empty],
    () => {
        if (!props.loading && !props.error && !props.empty) hasRendered.value = true;
    },
);
</script>

<template>
    <Card class="chart-card">
        <template #title>
            <div class="chart-head">
                <div class="chart-head-text">
                    <span class="chart-title">{{ title }}</span>
                    <span v-if="subtitle" class="chart-subtitle text-muted">{{ subtitle }}</span>
                </div>
                <div class="chart-actions">
                    <slot name="actions" />
                    <Button
                        v-if="tableAvailable"
                        :icon="view === 'chart' ? 'pi pi-table' : 'pi pi-chart-bar'"
                        text
                        rounded
                        size="small"
                        severity="secondary"
                        :aria-label="view === 'chart' ? 'Voir les données en tableau' : 'Voir le graphique'"
                        :title="view === 'chart' ? 'Tableau' : 'Graphique'"
                        @click="view = view === 'chart' ? 'table' : 'chart'"
                    />
                </div>
            </div>
        </template>
        <template #content>
            <div v-if="error" class="chart-state" role="alert">
                <p>{{ error }}</p>
                <Button label="Réessayer" icon="pi pi-refresh" size="small" outlined severity="secondary" @click="emit('retry')" />
            </div>
            <p v-else-if="empty" class="chart-state text-muted">{{ emptyText }}</p>
            <template v-else>
                <div
                    v-show="view === 'chart'"
                    class="chart-body"
                    :class="{ dimmed: loading }"
                    :style="{ height: `${height}px` }"
                    role="img"
                    :aria-label="ariaLabel"
                >
                    <slot v-if="hasRendered || !loading" />
                    <ProgressBar v-if="loading && !hasRendered" mode="indeterminate" class="chart-progress" aria-label="Chargement" />
                </div>
                <div v-show="view === 'table'" class="chart-table">
                    <slot name="table" />
                </div>
            </template>
        </template>
    </Card>
</template>

<style scoped>
.chart-card :deep(.p-card-body) {
    padding-top: 0.5rem;
}
.chart-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 0.5rem;
}
.chart-head-text {
    display: flex;
    flex-direction: column;
    gap: 0.15rem;
    min-width: 0;
}
.chart-title {
    font-weight: 600;
}
.chart-subtitle {
    font-size: 0.82rem;
    font-weight: 400;
}
.chart-actions {
    display: flex;
    align-items: center;
    gap: 0.25rem;
    flex: none;
}
.chart-body {
    position: relative;
    width: 100%;
}
.chart-body.dimmed {
    opacity: 0.45;
    pointer-events: none;
}
.chart-progress {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
}
.chart-state {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 0.5rem;
    padding: 2rem 0;
    margin: 0;
}
.chart-table {
    overflow-x: auto;
}
</style>
