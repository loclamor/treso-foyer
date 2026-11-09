import axios from 'axios';

// Client HTTP unique vers l'API Symfony (même origine, cookie de session SameSite=Lax).
const http = axios.create({
    baseURL: '/api',
    withCredentials: true,
    headers: { Accept: 'application/json' },
});

// Handler 401 injecté par le router (évite un import circulaire http → router).
let onUnauthorized = null;
export function setUnauthorizedHandler(fn) {
    onUnauthorized = fn;
}

const AUTH_PATHS = new Set(['/login', '/me']);

// 401 ⇒ redirection vers /login?redirect=… (sauf /login et /me eux-mêmes).
http.interceptors.response.use(
    (response) => response,
    (error) => {
        // Comparaison exacte du chemin : includes('/me') attrapait aussi /members et /merchants.
        const path = (error.config?.url ?? '').split('?')[0];
        if (error.response?.status === 401 && !AUTH_PATHS.has(path) && onUnauthorized) {
            onUnauthorized();
        }
        return Promise.reject(error);
    },
);

export default http;

// Message FR du contrat d'erreur ({error, message, violations}).
export function apiMessage(error, fallback = 'Une erreur est survenue') {
    const data = error?.response?.data;
    if (data && typeof data === 'object') {
        if (data.message) return data.message;
        if (data.error) return data.error;
    }
    return fallback;
}
