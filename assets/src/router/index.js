import { createRouter, createWebHistory } from 'vue-router';
import { useAuthStore } from '@/stores/auth';
import { setUnauthorizedHandler } from '@/api/http';

const routes = [
    { path: '/login', name: 'login', component: () => import('@/views/LoginView.vue'), meta: { public: true } },
    { path: '/', name: 'dashboard', component: () => import('@/views/DashboardView.vue') },
    { path: '/statistiques', name: 'stats', component: () => import('@/views/StatsView.vue') },
    { path: '/operations', name: 'operations', component: () => import('@/views/OperationsView.vue') },
    { path: '/a-categoriser', name: 'to-categorize', component: () => import('@/views/ToCategorizeView.vue') },
    { path: '/imports', name: 'imports', component: () => import('@/views/ImportsView.vue') },
    { path: '/parametrage/categories', name: 'categories', component: () => import('@/views/CategoriesView.vue') },
    { path: '/parametrage/regles', name: 'rules', component: () => import('@/views/RulesView.vue') },
    { path: '/parametrage/comptes', name: 'accounts', component: () => import('@/views/AccountsView.vue') },
    { path: '/parametrage/vehicules', name: 'vehicles', component: () => import('@/views/VehiclesView.vue') },
    { path: '/vehicules/:id(\\d+)', name: 'vehicle', component: () => import('@/views/VehicleView.vue') },
    { path: '/controles/doublons', name: 'duplicates', component: () => import('@/views/DuplicatesView.vue') },
    { path: '/controles/virements', name: 'transfers', component: () => import('@/views/TransfersView.vue') },
    { path: '/:pathMatch(.*)*', name: 'not-found', component: () => import('@/views/NotFoundView.vue') },
];

const router = createRouter({
    history: createWebHistory(),
    routes,
});

// Garde globale : routes meta.public (login) sinon fetchMe() puis redirection /login?redirect=…
router.beforeEach(async (to) => {
    const auth = useAuthStore();
    if (to.meta.public) {
        return auth.isAuthenticated && to.name === 'login' ? { name: 'dashboard' } : true;
    }
    if (!auth.loaded) await auth.fetchMe();
    if (!auth.isAuthenticated) {
        return { name: 'login', query: { redirect: to.fullPath } };
    }
    return true;
});

// 401 API ⇒ redirection /login?redirect=… (délégué à http.js via handler injecté).
setUnauthorizedHandler(() => {
    const current = router.currentRoute.value;
    if (current.name !== 'login') {
        router.push({ name: 'login', query: { redirect: current.fullPath } });
    }
});

export default router;
