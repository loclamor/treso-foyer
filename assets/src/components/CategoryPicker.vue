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

function findNode(list, id) {
    if (id === null || id === undefined) return null;
    for (const node of list ?? []) {
        if (Number(node.data) === Number(id)) return node;
        const found = findNode(node.children, id);
        if (found) return found;
    }
    return null;
}

// TreeSelect (selectionMode=single) travaille sur le nœud ; on expose l'id via data.
const selectedNode = computed({
    get: () => findNode(categoriesStore.options, props.modelValue),
    set: (node) => emit('update:modelValue', node?.data ?? null),
});
</script>

<template>
    <TreeSelect
        v-model="selectedNode"
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
