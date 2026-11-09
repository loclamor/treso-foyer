// ESLint (flat config) pour le front Vue 3 : règles recommandées JS + Vue.
import js from '@eslint/js';
import pluginVue from 'eslint-plugin-vue';
import globals from 'globals';

export default [
    { ignores: ['node_modules/**', '../public/build/**'] },
    js.configs.recommended,
    ...pluginVue.configs['flat/recommended'],
    {
        files: ['**/*.{js,vue}'],
        languageOptions: {
            ecmaVersion: 'latest',
            sourceType: 'module',
            globals: { ...globals.browser },
        },
        rules: {
            // Mise en forme : pas de formateur imposé au projet, on ne lint que le fond.
            'vue/html-indent': 'off',
            'vue/max-attributes-per-line': 'off',
            'vue/singleline-html-element-content-newline': 'off',
            'vue/multiline-html-element-content-newline': 'off',
            'vue/html-self-closing': 'off',
            'vue/html-closing-bracket-newline': 'off',
            'vue/first-attribute-linebreak': 'off',
            'vue/attributes-order': 'off',
        },
    },
    {
        files: ['vite.config.js', 'vitest.config.js', 'eslint.config.js'],
        languageOptions: { globals: { ...globals.node } },
    },
];
