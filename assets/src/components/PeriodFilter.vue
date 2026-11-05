<script setup>
import { computed, onMounted, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import Select from 'primevue/select';
import DatePicker from 'primevue/datepicker';
import { usePeriodStore } from '@/stores/period';
import { dateToIso } from '@/utils/format';

// Ligne de filtres unique au-dessus des graphiques : préréglages de période + plage personnalisée.
// Synchronisée avec la query string (?period=… ou ?from=…&to=…).

const PRESETS = [
    { label: 'Ce mois', value: 'month' },
    { label: 'Mois dernier', value: 'lastMonth' },
    { label: '3 derniers mois', value: 'last3' },
    { label: '6 derniers mois', value: 'last6' },
    { label: '12 derniers mois', value: 'last12' },
    { label: 'Année en cours', value: 'year' },
    { label: 'Année précédente', value: 'lastYear' },
    { label: "Tout l'historique", value: 'all' },
    { label: 'Personnalisé', value: 'custom' },
];

const route = useRoute();
const router = useRouter();
const period = usePeriodStore();

const selectedPreset = computed({
    get: () => period.preset,
    set: (v) => period.setPreset(v),
});

function toDate(isoStr) {
    if (!isoStr) return null;
    const [y, m, d] = String(isoStr).split('-').map(Number);
    return y && m && d ? new Date(y, m - 1, d) : null;
}

// DatePicker en plage : [Date, Date] ↔ {from, to} ISO.
const rangeModel = computed({
    get: () => [toDate(period.custom.from), toDate(period.custom.to)],
    set: (v) => {
        const from = Array.isArray(v) ? dateToIso(v[0]) : null;
        const to = Array.isArray(v) && v[1] ? dateToIso(v[1]) : null;
        if (from && to) period.setCustom(from, to);
    },
});

onMounted(() => {
    period.applyQuery(route.query);
});

// Query string ← store (sans boucler : on ne ré-applique pas la route au montage suivant).
watch(
    () => period.query,
    (q) => {
        const query = { ...route.query };
        delete query.period;
        delete query.from;
        delete query.to;
        router.replace({ query: { ...query, ...q } });
    },
    { deep: true },
);
</script>

<template>
    <div class="period-filter">
        <Select
            v-model="selectedPreset"
            :options="PRESETS"
            option-label="label"
            option-value="value"
            aria-label="Période"
            class="period-select"
        />
        <DatePicker
            v-if="period.preset === 'custom'"
            v-model="rangeModel"
            selection-mode="range"
            :manual-input="false"
            date-format="dd/mm/yy"
            placeholder="Du — au"
            aria-label="Période personnalisée"
            class="period-range"
            show-icon
        />
    </div>
</template>

<style scoped>
.period-filter {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    flex-wrap: wrap;
    margin-bottom: 1rem;
}
.period-select {
    min-width: 12rem;
}
.period-range {
    max-width: 18rem;
}
</style>
