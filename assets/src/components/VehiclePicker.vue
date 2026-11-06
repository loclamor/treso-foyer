<script setup>
import { computed, onMounted } from 'vue';
import Select from 'primevue/select';
import { useVehiclesStore } from '@/stores/vehicles';
import { VEHICLE_IGNORED } from '@/utils/vehicles';

// Sélecteur de véhicule : option « Aucun » (value null) = retrait de l'affectation ;
// allowIgnore ajoute l'option « Ignorer » (value 'ignored').
const props = defineProps({
    modelValue: { type: [Number, String], default: null }, // id de véhicule, 'ignored' ou null
    placeholder: { type: String, default: 'Aucun véhicule' },
    allowIgnore: { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue']);

const vehiclesStore = useVehiclesStore();

const options = computed(() => [
    { label: props.placeholder, value: null },
    ...(props.allowIgnore ? [{ label: 'Ignorer (ne pas affecter)', value: VEHICLE_IGNORED }] : []),
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
