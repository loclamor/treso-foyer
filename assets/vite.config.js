import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import symfonyPlugin from 'vite-plugin-symfony';
import { fileURLToPath, URL } from 'node:url';

// Le front vit dans assets/ ; le build sort dans public/build, lu par pentatrion/vite-bundle.
export default defineConfig({
    root: '.',
    base: '/build/',
    // .env / .env.local de la racine du projet (seules les variables VITE_* sont exposées au front)
    envDir: '..',
    plugins: [vue(), symfonyPlugin()],
    resolve: {
        alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) },
    },
    build: {
        manifest: true,
        emptyOutDir: true,
        outDir: '../public/build',
        rollupOptions: { input: { app: './src/main.js' } },
    },
    server: {
        host: '0.0.0.0',
        port: 5173,
        strictPort: true,
        cors: true,
        // origine vue par le navigateur (page servie en https://localhost:8543)
        origin: 'http://localhost:5173',
    },
});
