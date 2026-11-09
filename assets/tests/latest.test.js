import { describe, expect, it } from 'vitest';
import { latestGuard } from '@/utils/latest';

describe('latestGuard', () => {
    it('seul le dernier appel reste « le plus récent »', () => {
        const guard = latestGuard();
        const first = guard();
        expect(first()).toBe(true);
        const second = guard();
        expect(first()).toBe(false);
        expect(second()).toBe(true);
    });

    it('ignore une réponse lente arrivée après une plus récente', async () => {
        const guard = latestGuard();
        let shown = null;
        const load = async (value, delay) => {
            const isLatest = guard();
            await new Promise((resolve) => setTimeout(resolve, delay));
            if (isLatest()) shown = value;
        };

        // Filtre « a » lent puis « ab » rapide : l'écran doit finir sur « ab ».
        await Promise.all([load('a', 30), load('ab', 5)]);

        expect(shown).toBe('ab');
    });

    it('deux gardes sont indépendantes', () => {
        const a = latestGuard();
        const b = latestGuard();
        const isA = a();
        b();
        expect(isA()).toBe(true);
    });
});
