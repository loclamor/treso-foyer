import { defineStore } from 'pinia';
import { ref } from 'vue';
import { listMembers } from '@/api/members';

export const useMembersStore = defineStore('members', () => {
    const list = ref([]);
    const loaded = ref(false);

    async function refresh() {
        list.value = await listMembers();
        loaded.value = true;
    }

    return { list, loaded, refresh };
});
