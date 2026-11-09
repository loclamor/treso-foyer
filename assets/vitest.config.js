// Tests front (Vitest) : config séparée de vite.config.js pour ne pas charger le plugin Symfony.
import { defineConfig } from 'vitest/config';
import vue from '@vitejs/plugin-vue';
import { fileURLToPath, URL } from 'node:url';

export default defineConfig({
    plugins: [vue()],
    resolve: {
        alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) },
    },
    test: {
        environment: 'jsdom',
        include: ['tests/**/*.test.js'],
    },
});
