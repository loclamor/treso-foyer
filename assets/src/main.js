import { createApp } from 'vue';
import { createPinia } from 'pinia';
import PrimeVue from 'primevue/config';
import ToastService from 'primevue/toastservice';
import ConfirmationService from 'primevue/confirmationservice';
import Tooltip from 'primevue/tooltip';
import Aura from '@primeuix/themes/aura';
import 'primeicons/primeicons.css';
import './styles/app.css';

import App from './App.vue';
import router from './router';
import { fr } from './locale/fr';

createApp(App)
    .use(createPinia())
    .use(router)
    .use(PrimeVue, {
        theme: { preset: Aura, options: { darkModeSelector: '.app-dark' } },
        locale: fr,
        // Clé PrimeUI Community (gratuite pour un particulier) : VITE_PRIMEUI_LICENSE dans .env.local
        license: import.meta.env.VITE_PRIMEUI_LICENSE || undefined,
    })
    .use(ToastService)
    .use(ConfirmationService)
    .directive('tooltip', Tooltip)
    .mount('#app');
