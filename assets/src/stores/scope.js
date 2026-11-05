import { defineStore } from 'pinia';
import { computed, ref } from 'vue';

const STORAGE_KEY = 'banque.scope';

function load() {
    try {
        const v = JSON.parse(localStorage.getItem(STORAGE_KEY));
        if (v && ['household', 'member', 'account'].includes(v.type)) {
            if (v.type === 'household' || Number.isInteger(v.id)) return v;
        }
    } catch {
        // valeur illisible ⇒ on retombe sur le foyer
    }
    return { type: 'household' };
}

// Périmètre global : {type:'household'} | {type:'member', id} | {type:'account', id}.
export const useScopeStore = defineStore('scope', () => {
    const current = ref(load());

    // Paramètres de requête du contrat : accountId[] (répétable) ou memberId.
    // axios sérialise un tableau en `accountId[]=1&accountId[]=2`.
    const params = computed(() => {
        const c = current.value;
        if (c.type === 'member') return { memberId: c.id };
        if (c.type === 'account') return { accountId: [c.id] };
        return {};
    });

    function set(scope) {
        current.value = scope;
        localStorage.setItem(STORAGE_KEY, JSON.stringify(scope));
    }

    return { current, params, set };
});
