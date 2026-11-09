import { afterEach, describe, expect, it, vi } from 'vitest';
import http, { apiMessage, setUnauthorizedHandler } from '@/api/http';

// Adaptateur axios factice : répond avec le statut demandé, sans réseau.
function respondWith(status, data = {}) {
    return (config) => {
        const response = { status, data, headers: {}, config, statusText: '' };
        if (status >= 400) {
            const error = new Error(`HTTP ${status}`);
            error.config = config;
            error.response = response;
            return Promise.reject(error);
        }
        return Promise.resolve(response);
    };
}

describe('client HTTP', () => {
    afterEach(() => setUnauthorizedHandler(null));

    it('envoie X-Requested-With (exigé par l’API sur les écritures)', async () => {
        let sent;
        await http.post('/rules', {}, { adapter: (config) => { sent = config; return respondWith(200)(config); } });
        expect(sent.headers['X-Requested-With']).toBe('XMLHttpRequest');
    });

    it.each(['/operations', '/members', '/merchants?q=x'])('401 sur %s ⇒ redirection vers le login', async (url) => {
        const onUnauthorized = vi.fn();
        setUnauthorizedHandler(onUnauthorized);
        await expect(http.get(url, { adapter: respondWith(401) })).rejects.toThrow();
        expect(onUnauthorized).toHaveBeenCalledOnce();
    });

    it.each(['/login', '/me'])('401 sur %s ⇒ pas de redirection', async (url) => {
        const onUnauthorized = vi.fn();
        setUnauthorizedHandler(onUnauthorized);
        await expect(http.get(url, { adapter: respondWith(401) })).rejects.toThrow();
        expect(onUnauthorized).not.toHaveBeenCalled();
    });

    it('apiMessage lit le contrat d’erreur de l’API', () => {
        expect(apiMessage({ response: { data: { error: 'not_found', message: 'Opération introuvable.' } } })).toBe('Opération introuvable.');
        expect(apiMessage({ response: { data: { error: 'rate_limited' } } })).toBe('rate_limited');
        expect(apiMessage(new Error('réseau'), 'Indisponible')).toBe('Indisponible');
    });
});
