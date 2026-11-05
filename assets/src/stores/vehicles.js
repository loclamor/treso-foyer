import { defineStore } from 'pinia';
import { computed, ref } from 'vue';
import { listVehicles } from '@/api/vehicles';

export const useVehiclesStore = defineStore('vehicles', () => {
    const list = ref([]);
    const loaded = ref(false);

    // includeArchived=true ⇒ GET /api/vehicles?archived=1 (sinon non archivés uniquement).
    async function refresh(includeArchived = false) {
        list.value = await listVehicles({ archived: includeArchived });
        loaded.value = true;
    }

    // Véhicules actifs : l'affectation automatique ne retient que ceux-là.
    const active = computed(() => list.value.filter((v) => !v.archived));

    return { list, loaded, active, refresh };
});
