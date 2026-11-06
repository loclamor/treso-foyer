import { defineStore } from 'pinia';
import { ref } from 'vue';
import { listRecurrences } from '@/api/recurrences';

// Séries récurrentes : liste courante (selon filtres) + compteurs par statut (toujours complets).
export const useRecurrencesStore = defineStore('recurrences', () => {
    const items = ref([]);
    const counts = ref({ detected: 0, confirmed: 0, ignored: 0, ended: 0 });
    const loaded = ref(false);

    // params : périmètre (scope) + filtres {status?, direction?}.
    async function refresh(params = {}) {
        const res = await listRecurrences(params);
        items.value = res.items ?? [];
        counts.value = res.counts ?? { detected: 0, confirmed: 0, ignored: 0, ended: 0 };
        loaded.value = true;
    }

    // Compteurs seuls (sans toucher à la liste filtrée courante).
    async function refreshCounts(scopeParams = {}) {
        const res = await listRecurrences(scopeParams);
        counts.value = res.counts ?? counts.value;
    }

    return { items, counts, loaded, refresh, refreshCounts };
});
