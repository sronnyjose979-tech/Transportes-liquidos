<script setup lang="ts">
import SharedNavigation from '../System/SharedNavigation.vue';
import { computed, onMounted, ref } from 'vue';
import { failure, getSession, login, logout, refreshCsrf } from './api';
import type { ApiError } from './api';
import type { FieldErrors, FleetAsset, Session } from './contracts';
import AssetPicker from './components/AssetPicker.vue';
import AssetDocuments from './components/AssetDocuments.vue';
import DocumentTypes from './components/DocumentTypes.vue';
import FieldError from './components/FieldError.vue';

const session = ref<Session | null>(null);
const initialLoading = ref(true);
const loginBusy = ref(false);
const locked = ref(false);
const username = ref('');
const password = ref('');
const loginErrors = ref<FieldErrors>({});
const message = ref('');
const csrfExpired = ref(false);
const csrfBusy = ref(false);
const view = ref<'documents' | 'types'>('documents');
const asset = ref<FleetAsset | null>(null);
const authorized = computed(() => session.value?.roles.some((role) => role === 'administrator' || role === 'secretary') ?? false);
const canManageTypes = computed(() => session.value?.roles.includes('administrator') ?? false);
function clearPrivateState(): void { asset.value = null; locked.value = false; view.value = 'documents'; password.value = ''; }
onMounted(async () => {
    try { session.value = await getSession(); }
    catch (error) { const problem = failure(error); if (problem.status !== 401) message.value = problem.message; }
    finally { initialLoading.value = false; }
});
async function report(error: ApiError): Promise<void> {
    message.value = error.message;
    if (error.status === 401) { session.value = null; clearPrivateState(); }
    if (error.status === 419) csrfExpired.value = true;
    if (error.status === 403) {
        // Remove privileged components before refreshing the server's current roles.
        session.value = null; clearPrivateState(); initialLoading.value = true;
        try { session.value = await getSession(); } catch (problem) { message.value = failure(problem).message; }
        finally { initialLoading.value = false; }
    }
}
async function signIn(): Promise<void> {
    if (loginBusy.value) return;
    loginBusy.value = true; message.value = ''; loginErrors.value = {};
    try { await refreshCsrf(); session.value = await login(username.value, password.value); password.value = ''; csrfExpired.value = false; clearPrivateState(); }
    catch (error) { const problem = failure(error); loginErrors.value = problem.fields; await report(problem); }
    finally { loginBusy.value = false; }
}
async function signOut(): Promise<void> {
    if (locked.value) return;
    locked.value = true;
    try { await logout(); session.value = null; clearPrivateState(); message.value = ''; }
    catch (error) { await report(failure(error)); }
    finally { locked.value = false; }
}
async function renewCsrf(): Promise<void> {
    csrfBusy.value = true;
    try { await refreshCsrf(); csrfExpired.value = false; message.value = 'Sesión de seguridad actualizada. Puedes volver a enviar el formulario.'; }
    catch (error) { await report(failure(error)); }
    finally { csrfBusy.value = false; }
}
</script>
<template>
    <a href="#main-content" class="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-50 focus:rounded-lg focus:bg-white focus:p-3">Ir al contenido principal</a>
    <header class="border-b border-slate-200 bg-white">
        <div class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-4 px-4 py-5 sm:px-6 lg:px-8">
            <div class="flex items-center gap-3"><span aria-hidden="true" class="flex h-11 w-11 items-center justify-center rounded-xl bg-teal-800 text-lg font-bold text-white">TL</span><div><p class="font-semibold">Transporte de Líquidos</p><p class="text-sm text-slate-500">Documentación de flota</p></div></div>
            <div v-if="session" class="flex flex-wrap items-center gap-3 text-sm"><SharedNavigation workspace :session="session" :disabled="locked"/><span class="max-w-48 break-words text-slate-600">{{ session.login ?? 'Cuenta activa' }} · {{ canManageTypes ? 'Administrador' : authorized ? 'Secretaria' : 'Sin acceso documental' }}</span><button class="btn-secondary" :disabled="locked" @click="signOut">Cerrar sesión</button></div>
        </div>
    </header>
    <main id="main-content" class="mx-auto max-w-7xl space-y-6 px-4 py-6 sm:px-6 lg:px-8 lg:py-8" tabindex="-1">
        <div v-if="message" role="alert" class="notice-error"><p>{{ message }}</p><button v-if="csrfExpired" class="btn-secondary mt-3" :disabled="csrfBusy" @click="renewCsrf">{{ csrfBusy ? 'Actualizando…' : 'Actualizar sesión de seguridad' }}</button><button v-else class="btn-text mt-2" aria-label="Cerrar aviso" @click="message = ''">Cerrar aviso</button></div>
        <p v-if="initialLoading" role="status" class="panel">Comprobando sesión…</p>
        <section v-else-if="!session" class="panel mx-auto max-w-md" aria-labelledby="login-title">
            <h1 id="login-title" class="text-2xl font-semibold">Acceso a documentación</h1><p class="mb-6 mt-2 text-sm text-slate-600">Ingresa con tu cuenta de administrador o secretaria.</p>
            <form :aria-busy="loginBusy" @submit.prevent="signIn"><fieldset :disabled="loginBusy" class="space-y-5">
                <div><label for="login-name" class="field-label">Usuario</label><input id="login-name" v-model="username" class="field" required autocomplete="username" :aria-invalid="!!loginErrors.login" aria-describedby="login-name-error"><FieldError id="login-name-error" :errors="loginErrors.login" /></div>
                <div><label for="login-password" class="field-label">Contraseña</label><input id="login-password" v-model="password" class="field" type="password" required autocomplete="current-password" :aria-invalid="!!loginErrors.password" aria-describedby="login-password-error"><FieldError id="login-password-error" :errors="loginErrors.password" /></div>
                <button class="btn-primary w-full" type="submit" :disabled="loginBusy">{{ loginBusy ? 'Ingresando…' : 'Iniciar sesión' }}</button>
            </fieldset></form>
        </section>
        <section v-else-if="!authorized" class="panel" aria-labelledby="denied-title"><h1 id="denied-title" class="text-xl font-semibold">Acceso no autorizado</h1><p class="mt-3 text-slate-600">Esta sección está disponible para administrador y secretaria.</p></section>
        <template v-else>
            <div><h1 class="text-3xl font-semibold tracking-tight">Documentación de flota</h1><p class="mt-2 text-slate-600">Documentos privados, vigencia e historial de cabezales y cisternas.</p></div>
            <nav class="flex flex-wrap gap-2 border-b border-slate-200 pb-4" aria-label="Gestión documental"><button class="btn-secondary" :class="view === 'documents' ? 'bg-teal-50 text-teal-900' : ''" :aria-pressed="view === 'documents'" :disabled="locked" @click="view = 'documents'; message = ''">Documentos por activo</button><button class="btn-secondary" :class="view === 'types' ? 'bg-teal-50 text-teal-900' : ''" :aria-pressed="view === 'types'" :disabled="locked" @click="view = 'types'; message = ''">Tipos documentales</button></nav>
            <template v-if="view === 'documents'"><AssetPicker :selected-id="asset?.id ?? null" :locked="locked" @select="asset = $event; message = ''" @error="report" /><AssetDocuments v-if="asset" :key="asset.id" :asset="asset" @error="report" @busy="locked = $event" /><p v-else class="panel text-slate-600">Selecciona un activo para consultar sus documentos y requisitos.</p></template>
            <DocumentTypes v-else :can-manage="canManageTypes" @error="report" @busy="locked = $event" />
        </template>
    </main>
    <footer class="mx-auto max-w-7xl px-4 pb-6 text-xs text-slate-500 sm:px-6 lg:px-8">Fechas de registro en America/Costa_Rica · Archivos privados</footer>
</template>
