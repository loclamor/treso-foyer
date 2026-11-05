import { defineStore } from 'pinia';
import { computed, ref } from 'vue';
import { login as apiLogin, logout as apiLogout, me as apiMe } from '@/api/auth';

export const useAuthStore = defineStore('auth', () => {
    const user = ref(null);
    const loaded = ref(false);

    const isAuthenticated = computed(() => !!user.value);

    async function login(username, password) {
        user.value = await apiLogin(username, password);
        loaded.value = true;
    }

    async function logout() {
        await apiLogout();
        user.value = null;
        loaded.value = false;
    }

    async function fetchMe() {
        try {
            user.value = await apiMe();
        } catch {
            user.value = null;
        }
        loaded.value = true;
        return user.value;
    }

    return { user, loaded, isAuthenticated, login, logout, fetchMe };
});
