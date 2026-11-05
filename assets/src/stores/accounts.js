import { defineStore } from 'pinia';
import { ref } from 'vue';
import { listAccounts } from '@/api/accounts';

export const useAccountsStore = defineStore('accounts', () => {
    const list = ref([]);
    const loaded = ref(false);

    async function refresh() {
        list.value = await listAccounts();
        loaded.value = true;
    }

    return { list, loaded, refresh };
});
