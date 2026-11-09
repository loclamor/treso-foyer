<script setup>
import { ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import Card from 'primevue/card';
import InputText from 'primevue/inputtext';
import Password from 'primevue/password';
import Button from 'primevue/button';
import Message from 'primevue/message';
import { useAuthStore } from '@/stores/auth';
import { apiMessage } from '@/api/http';

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();

const username = ref('');
const password = ref('');
const loading = ref(false);
const error = ref('');

async function submit() {
    error.value = '';
    loading.value = true;
    try {
        await auth.login(username.value, password.value);
        const redirect = route.query.redirect ? String(route.query.redirect) : null;
        router.push(redirect || { name: 'dashboard' });
    } catch (e) {
        error.value = e.response?.status === 401 ? 'Identifiants invalides.' : apiMessage(e, 'Connexion impossible.');
    } finally {
        loading.value = false;
    }
}
</script>

<template>
    <div class="login-page">
        <Card class="login-card">
            <template #title>
                <span class="login-title"><i class="pi pi-wallet" aria-hidden="true" /> Banque</span>
            </template>
            <template #content>
                <form class="login-form" @submit.prevent="submit">
                    <div class="field">
                        <label for="login-username">Identifiant</label>
                        <InputText id="login-username" v-model="username" autocomplete="username" class="w-full" required autofocus />
                    </div>
                    <div class="field">
                        <label for="login-password">Mot de passe</label>
                        <Password
                            input-id="login-password"
                            v-model="password"
                            :feedback="false"
                            toggle-mask
                            autocomplete="current-password"
                            input-class="w-full"
                            class="w-full"
                            required
                        />
                    </div>
                    <Message v-if="error" severity="error" :closable="false">{{ error }}</Message>
                    <Button type="submit" label="Se connecter" icon="pi pi-sign-in" class="w-full" :loading="loading" />
                </form>
            </template>
        </Card>
    </div>
</template>

<style scoped>
.login-page {
    min-height: calc(100vh - 8rem);
    display: flex;
    align-items: center;
    justify-content: center;
}
.login-card {
    width: 100%;
    max-width: 26rem;
}
.login-title {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
}
.login-form .field {
    margin-bottom: 1rem;
}
.login-form label {
    display: block;
    font-weight: 600;
    margin-bottom: 0.35rem;
}
.login-form .p-message {
    margin-bottom: 1rem;
}
</style>
