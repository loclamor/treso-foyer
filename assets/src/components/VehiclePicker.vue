<script setup>
import { computed, onMounted } from 'vue';
import Select from 'primevue/select';
import { useVehiclesStore } from '@/stores/vehicles';

// Sélecteur de véhicule : option « Aucun » (value null) = retrait de l'affectation.
const props = defineProps({
    modelValue: { type: Number, default: null }, // id de véhicule ou null
    placeholder: { type: String, default: 'Aucun véhicule' },
});
const emit = defineEmits(['update:modelValue']);

const vehiclesStore = useVehiclesStore();

const options = computed(() => [
    { label: props.placeholder, value: null },
    ...vehiclesStore.list.map((v) => ({
        label: v.archived ? `${v.name} (archivé)` : v.name,
        value: v.id,
    })),
]);

onMounted(() => {
    if (!vehiclesStore.loaded) vehiclesStore.refresh().catch(() => {});
});
</script>

<template>
    <Select
        :model-value="modelValue"
        :options="options"
        option-label="label"
        option-value="value"
        class="w-full"
        :placeholder="placeholder"
        :aria-label="placeholder"
        @update:model-value="(v) => emit('update:modelValue', v)"
    />
</template>
