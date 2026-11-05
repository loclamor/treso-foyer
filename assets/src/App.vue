<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import Menubar from 'primevue/menubar';
import Button from 'primevue/button';
import Menu from 'primevue/menu';
import Toast from 'primevue/toast';
import ConfirmDialog from 'primevue/confirmdialog';
import { useAuthStore } from '@/stores/auth';
import { useMembersStore } from '@/stores/members';
import { useAccountsStore } from '@/stores/accounts';
import { useCategoriesStore } from '@/stores/categories';
import ScopeSelector from '@/components/ScopeSelector.vue';

const router = useRouter();
const route = useRoute();
// Pas de barre de navigation sur les pages publiques (connexion).
const showChrome = computed(() => !route.meta?.public);
const auth = useAuthStore();
const members = useMembersStore();
const accounts = useAccountsStore();
const categories = useCategoriesStore();

const userMenu = ref(null);
const dark = ref(false);

const items = [
    { label: 'Accueil', icon: 'pi pi-home', command: () => router.push({ name: 'dashboard' }) },
    { label: 'Statistiques', icon: 'pi pi-chart-line', command: () => router.push({ name: 'stats' }) },
    { label: 'Opérations', icon: 'pi pi-list', command: () => router.push({ name: 'operations' }) },
    { label: 'À catégoriser', icon: 'pi pi-tags', command: () => router.push({ name: 'to-categorize' }) },
    { label: 'Imports', icon: 'pi pi-upload', command: () => router.push({ name: 'imports' }) },
    {
        // Paramétrage et contrôles regroupés : la barre tient sur une ligne avec le sélecteur de périmètre.
        label: 'Réglages',
        icon: 'pi pi-cog',
        items: [
            { label: 'Catégories', icon: 'pi pi-sitemap', command: () => router.push({ name: 'categories' }) },
            { label: 'Règles', icon: 'pi pi-filter', command: () => router.push({ name: 'rules' }) },
            { label: 'Comptes', icon: 'pi pi-credit-card', command: () => router.push({ name: 'accounts' }) },
            { separator: true },
            { label: 'Doublons', icon: 'pi pi-clone', command: () => router.push({ name: 'duplicates' }) },
            { label: 'Virements internes', icon: 'pi pi-arrow-right-arrow-left', command: () => router.push({ name: 'transfers' }) },
        ],
    },
];

const userMenuItems = [{ label: 'Déconnexion', icon: 'pi pi-sign-out', command: () => logout() }];

async function logout() {
    try {
        await auth.logout();
    } finally {
        router.push({ name: 'login' });
    }
}

function applyDark(value) {
    dark.value = value;
    document.documentElement.classList.toggle('app-dark', value);
    localStorage.setItem('banque.dark', value ? '1' : '0');
}

function toggleDark() {
    applyDark(!dark.value);
}

onMounted(async () => {
    const saved = localStorage.getItem('banque.dark');
    applyDark(saved === '1' || (saved === null && window.matchMedia?.('(prefers-color-scheme: dark)').matches === true));
});

// Référentiels chargés dès que l'utilisateur est connecté (démarrage ou connexion).
watch(
    () => auth.isAuthenticated,
    (authenticated) => {
        if (authenticated) {
            Promise.allSettled([members.refresh(), accounts.refresh(), categories.refresh()]);
        }
    },
    { immediate: true },
);
</script>

<template>
    <Menubar v-if="showChrome" :model="items">
        <template #start>
            <span class="brand"><i class="pi pi-wallet" aria-hidden="true" /> Banque</span>
        </template>
        <template #end>
            <div class="app-menubar-end">
                <ScopeSelector v-if="auth.isAuthenticated" />
                <Button
                    :icon="dark ? 'pi pi-sun' : 'pi pi-moon'"
                    text
                    rounded
                    severity="secondary"
                    :aria-label="dark ? 'Passer en mode clair' : 'Passer en mode sombre'"
                    @click="toggleDark"
                />
                <template v-if="auth.isAuthenticated">
                    <Button
                        :label="auth.user?.displayName ?? ''"
                        icon="pi pi-user"
                        outlined
                        severity="secondary"
                        aria-haspopup="true"
                        aria-label="Menu utilisateur"
                        @click="(event) => userMenu?.toggle(event)"
                    />
                    <Menu ref="userMenu" :model="userMenuItems" popup />
                </template>
            </div>
        </template>
    </Menubar>
    <main class="app-main">
        <RouterView />
    </main>
    <Toast position="top-right" />
    <ConfirmDialog />
</template>

<style scoped>
.brand {
    font-weight: 700;
    margin-right: 1.5rem;
    display: inline-flex;
    gap: 0.5rem;
    align-items: center;
}
.app-menubar-end {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    flex-wrap: wrap;
}
.scope-selector {
    min-width: 13rem;
    max-width: 20rem;
}
@media (max-width: 720px) {
    .scope-selector {
        display: none;
    }
}
</style>
