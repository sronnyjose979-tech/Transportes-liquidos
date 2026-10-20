<script setup lang="ts">
import SharedNavigation from '../System/SharedNavigation.vue';
import { computed, onMounted, provide, ref } from 'vue';
import { getSession, logout, refreshCsrf } from '../FleetAndDocuments/api';
import type { Session } from '../FleetAndDocuments/contracts';
import { ApiError, failure } from './api';
import { adminKey } from './context';
import FleetPanel from './components/FleetPanel.vue';
import PersonnelPanel from './components/PersonnelPanel.vue';
import CatalogPanel from './components/CatalogPanel.vue';
import PurchaseRequestsPanel from './components/PurchaseRequestsPanel.vue';
import { useOffice } from '../Office/context';
const officeHost = useOffice(() => busy.value || uncertain.value);
const root = document.getElementById('administration-app');
const section = officeHost ? 'flota' : root?.dataset.section ?? 'flota';
const titles: Record<string,string> = { flota: 'Administración de Flota', personal: 'Administración de Personal', catalogos: 'Catálogos operativos', compras:'Solicitudes de compra' };
const session = ref<Session | null>(null), checking = ref(true), busy = ref(false), uncertain = ref(false);
const errors = ref<Record<string,string[]>>({}), message = ref(''), success = ref(''), csrfExpired = ref(false), revision = ref(0);
const allowed = computed(() => session.value?.roles.includes('administrator') ?? false);
async function loadSession(): Promise<void> { checking.value = true; try { session.value = await getSession(); } catch (error) { const p = failure(error); session.value = null; if (p.status !== 401) message.value = p.message; } finally { checking.value = false; } }
async function report(error: unknown): Promise<void> {
    const problem = failure(error); message.value = problem.message; success.value = ''; errors.value = problem.fields;
    if (problem.status === 0 || problem.status >= 500 || problem.status === 409) uncertain.value = true;
    if (problem.status === 419) csrfExpired.value = true;
    if ([401,403].includes(problem.status)) { session.value = null; revision.value++; if(officeHost)officeHost.invalidate();else await loadSession(); }
}
async function mutate(title: string, work: () => Promise<void>): Promise<boolean> {
    if (busy.value || uncertain.value || !allowed.value || !window.confirm(`¿Confirmar ${title}?`)) return false;
    busy.value = true; errors.value = {}; message.value = ''; success.value = '';
    try {
        const previousActor = session.value?.id;
        const current = await getSession(); session.value = current;
        if (current.id !== previousActor || !current.roles.includes('administrator')) { revision.value++; throw new ApiError(403, 'La sesión o los permisos cambiaron. Revisa los registros antes de confirmar una nueva operación.'); }
        await work(); success.value = 'Operación confirmada y registros actualizados.'; return true;
    } catch (error) { await report(error); return false; } finally { busy.value = false; }
}
async function recover(): Promise<void> { if (busy.value) return; busy.value = true; try { await loadSession(); revision.value++; uncertain.value = false; errors.value = {}; message.value = 'Consulta los registros actualizados antes de volver a registrar una operación.'; } finally { busy.value = false; } }
async function csrf(): Promise<void> { busy.value = true; try { await refreshCsrf(); csrfExpired.value = false; message.value = 'Seguridad actualizada. Puedes revisar y reenviar el formulario.'; } catch (error) { await report(error); } finally { busy.value = false; } }
async function signOut(): Promise<void> { if (busy.value) return; busy.value = true; try { await logout(); session.value = null; revision.value++; uncertain.value = false; errors.value = {}; success.value = ''; message.value = ''; } catch (error) { await report(error); } finally { busy.value = false; } }
provide(adminKey, { busy, uncertain, errors, mutate, report });
onMounted(() => { if (officeHost?.context.value) { session.value = officeHost.context.value.session; checking.value = false; } else void loadSession(); });
</script>
<template>
<div class="administration-shell">
<a class="sr-only focus:not-sr-only" href="#admin-main">Ir al contenido principal</a>
<header v-if="!officeHost" class="border-b border-blue-200 bg-white">
<div class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-4 px-4 py-5">
<div><p class="font-semibold text-blue-950">Transportes Líquidos</p><p class="text-sm text-blue-700">{{titles[section]}}</p></div>
<nav class="flex flex-wrap items-center gap-4" aria-label="Navegación interna">
<SharedNavigation workspace :session="session" :disabled="busy||uncertain"/>
<button v-if="session" class="btn-secondary" :disabled="busy" @click="signOut">Cerrar sesión</button>
</nav></div>
</header>
<main id="admin-main" class="mx-auto max-w-7xl space-y-5 px-4 py-6">
<div v-if="message" class="notice-info" role="alert"><p>{{message}}</p><ul v-for="(values,key) in errors" :key="key"><li v-for="value in values" :key="value">{{value}}</li></ul><button v-if="uncertain&&allowed" class="btn-secondary mt-3" :disabled="busy" @click="recover">Consultar estado confirmado</button><button v-if="csrfExpired" class="btn-secondary mt-3" :disabled="busy" @click="csrf">Actualizar sesión de seguridad</button></div>
<p v-if="success" class="notice-success" role="status">{{success}}</p>
<p v-if="checking" class="panel" role="status">Comprobando sesión…</p>
<section v-else-if="!session" class="panel"><h1 class="text-xl font-semibold">Acceso a Administración</h1><p class="my-3">Inicia sesión con una cuenta administrativa en el acceso existente.</p><a class="btn-primary" href="/login">Iniciar sesión</a></section>
<section v-else-if="!allowed" class="panel"><h1 class="text-xl font-semibold">Acceso no autorizado</h1><p>Solo el administrador puede gestionar estos recursos.</p></section>
<template v-else>
<h1 class="text-2xl font-semibold text-blue-950">{{titles[section]}}</h1>
<nav class="flex flex-wrap gap-3" aria-label="Administración"><a v-for="(title,key) in titles" :key="key" class="btn-secondary" :href="`/administracion/${key}`" :aria-current="section===key?'page':undefined" :aria-disabled="busy||uncertain" @click="(busy||uncertain)&&$event.preventDefault()">{{title}}</a></nav>
<FleetPanel v-if="section==='flota'" :key="revision"/><PersonnelPanel v-else-if="section==='personal'" :key="revision"/><PurchaseRequestsPanel v-else-if="section==='compras'" :key="revision"/><CatalogPanel v-else :key="revision"/>
</template>
</main>
</div>
</template>
<style>
.administration-shell { min-height:100vh; background:#f4f8ff; color:#16325c; }
.administration-shell .btn-primary { background:#1d4ed8; }.administration-shell .btn-secondary { border-color:#bfdbfe; color:#1e40af; background:white; }
.administration-shell input:focus,.administration-shell select:focus,.administration-shell textarea:focus { outline:2px solid #2563eb; outline-offset:2px; }
.administration-shell input,.administration-shell select,.administration-shell textarea { max-width:100%; }
.administration-shell .data-table { width:100%; text-align:left; }.administration-shell .data-table th,.administration-shell .data-table td { padding:.6rem; }
.administration-shell .panel { background:white; }
.administration-shell .workshop-search-select input,.administration-shell .workshop-search-select select { width:100%; border:1px solid #bfdbfe; border-radius:.5rem; padding:.6rem; background:white; }
</style>
