<script setup>
import { computed, ref } from 'vue';
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

// Le filtre de PrimeVue ne déplie pas les parents des résultats : on déplie tous les nœuds
// ayant des enfants dans l'arbre filtré, et on replie quand la recherche est vidée.
const expandedKeys = ref({});

function parentKeys(nodes, keys = {}) {
    for (const node of nodes ?? []) {
        if (node.children?.length) {
            keys[node.key] = true;
            parentKeys(node.children, keys);
        }
    }
    return keys;
}

function onFilter({ value, filteredNodes }) {
    expandedKeys.value = value?.trim() ? parentKeys(filteredNodes) : {};
}
</script>

<template>
    <TreeSelect
        v-model="selectionKeys"
        v-model:expanded-keys="expandedKeys"
        :options="categoriesStore.options"
        selection-mode="single"
        filter
        filter-by="search"
        filter-placeholder="Rechercher une catégorie…"
        :placeholder="placeholder"
        show-clear
        @filter="onFilter"
        class="w-full"
    />
</template>
