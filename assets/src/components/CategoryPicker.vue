<script setup>
import { computed } from 'vue';
import TreeSelect from 'primevue/treeselect';
import { useCategoriesStore } from '@/stores/categories';

const props = defineProps({
    modelValue: { type: [Number, String], default: null }, // id de catégorie
    placeholder: { type: String, default: 'Choisir une catégorie' },
});
const emit = defineEmits(['update:modelValue']);

const categoriesStore = useCategoriesStore();

// TreeSelect (PrimeVue 4+) a pour modèle un objet de clés sélectionnées : { "<key>": true }.
// Les clés des nœuds sont les ids de catégorie (cf. toTreeNodes) ; on expose l'id numérique.
const selectionKeys = computed({
    get: () => (props.modelValue === null || props.modelValue === undefined || props.modelValue === '' ? null : { [String(props.modelValue)]: true }),
    set: (keys) => {
        const key = Object.keys(keys ?? {}).find((k) => keys[k]);
        emit('update:modelValue', key === undefined ? null : Number(key));
    },
});
</script>

<template>
    <TreeSelect
        v-model="selectionKeys"
        :options="categoriesStore.options"
        selection-mode="single"
        filter
        filter-by="search"
        filter-placeholder="Rechercher une catégorie…"
        :placeholder="placeholder"
        show-clear
        class="w-full"
    />
</template>
