import { defineStore } from 'pinia';
import { computed, ref } from 'vue';
import { listCategories } from '@/api/categories';

function toTreeNodes(cats) {
    return (cats ?? []).map((c) => ({
        key: String(c.id),
        label: c.name,
        data: c.id,
        icon: c.icon || undefined,
        search: c.fullName || c.name,
        selectable: true,
        children: toTreeNodes(c.children),
    }));
}

export const useCategoriesStore = defineStore('categories', () => {
    const tree = ref([]);
    const loaded = ref(false);

    async function refresh() {
        tree.value = await listCategories();
        loaded.value = true;
    }

    // id → Category (avec fullName, color, icon…)
    const byId = computed(() => {
        const map = new Map();
        const walk = (cats) => {
            for (const c of cats ?? []) {
                map.set(c.id, c);
                walk(c.children);
            }
        };
        walk(tree.value);
        return map;
    });

    // Options au format TreeSelect PrimeVue (sélection des feuilles et des racines).
    const options = computed(() => toTreeNodes(tree.value));

    return { tree, loaded, byId, options, refresh };
});
