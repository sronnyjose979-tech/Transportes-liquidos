<script setup lang="ts">
import ExpirationIndicators from '../DocumentAlerts/ExpirationIndicators.vue';
import SharedNavigation from '../System/SharedNavigation.vue';
import WorkOverview from '../System/WorkOverview.vue';
import { computed, nextTick, onBeforeUnmount, onMounted, provide, ref, watch } from 'vue';
import { getSession, login, logout, refreshCsrf } from '../FleetAndDocuments/api';
import type { Session } from '../FleetAndDocuments/contracts';
import { ApiError, failure, getLookups, getRequests, getTrips } from './api';
import type { Lookups } from './contracts';
import { contextKey } from './context';
import RequestBoard from './components/RequestBoard.vue';
import TripBoard from './components/TripBoard.vue';
import TripDetail from './components/TripDetail.vue';
import {useOffice} from '../Office/context';
import {useRoute} from 'vue-router';
const officeHost=useOffice(()=>busy.value||uncertain.value);
const officeRoute=officeHost?useRoute():null;
const session = ref<Session | null>(null);
const initial = ref(true);
const busy = ref(false);
const uncertain = ref(false);
const reload = ref(0);
const message = ref('');
const success = ref('');
const fields = ref<Record<string, string[]>>({});
const csrfExpired = ref(false);
const username = ref('');
const password = ref('');
function locationView(): {view:string;trip:string} { const params = new URLSearchParams(window.location.search); const trip = params.get('viaje') ?? ''; const tab = params.get('vista'); return {view:trip ? 'trips' : tab && ['dashboard','requests','trips'].includes(tab) ? tab : 'dashboard',trip}; }
const initialLocation = locationView();
const view = ref(initialLocation.view);
const selectedTrip = ref(initialLocation.trip);
const initialRequest = ref('');
const requestFilter = ref('');
const counts = ref<{
    requests: number;
    trips: number;
} | null>(null);
const lookups = ref<Lookups>({ customers: [], products: [], sites: [], routes: [], drivers: [], assets: [] });
const admin = computed(() => session.value?.roles.includes('administrator') ?? false);
const office = computed(() => session.value?.roles.some(r => ['administrator', 'secretary'].includes(r)) ?? false);
let sessionGeneration = 0;
let restoringLocation = false;
function writeLocation(replace = false): void {
    const url = new URL(window.location.href);
    url.searchParams.set('vista', view.value);
    if (selectedTrip.value) url.searchParams.set('viaje', selectedTrip.value); else url.searchParams.delete('viaje');
    if(officeHost){ if(url.href!==window.location.href)officeHost.navigate(url.pathname+url.search,replace); }
    else if (replace) history.replaceState(null, '', url); else if (url.href !== window.location.href) history.pushState(null, '', url);
}
watch([view, selectedTrip], () => { if (!restoringLocation && !initial.value && office.value) writeLocation(); });
function previousLocation(): void {
    if (busy.value || uncertain.value) { writeLocation(true); return; }
    restoringLocation = true;
    const state = locationView(); view.value = state.view; selectedTrip.value = state.trip;
    initialRequest.value = ''; requestFilter.value = '';
    void nextTick(() => { restoringLocation = false; });
}
if(officeRoute)watch(()=>officeRoute.fullPath,()=>{
    if(officeRoute.path!=='/transporte')return;
    const target=locationView();
    // Our own query update must not erase the selected request or list filter.
    if(target.view!==view.value||target.trip!==selectedTrip.value)previousLocation();
});
else window.addEventListener('popstate', previousLocation);
onBeforeUnmount(() => { sessionGeneration++; window.removeEventListener('popstate', previousLocation); });
function clear(): void {
    sessionGeneration++;
    uncertain.value = false;
    fields.value = {};
    success.value = '';
    selectedTrip.value = '';
    initialRequest.value = '';
    requestFilter.value = '';
    lookups.value = { customers: [], products: [], sites: [], routes: [], drivers: [], assets: [] };
    counts.value = null;
    password.value = '';
    view.value = 'dashboard';
}
async function load(): Promise<void> { if (!office.value)
    return; const generation = sessionGeneration; const kinds = ['customers', 'products', 'sites', 'routes', 'drivers', 'assets'] as const; const [values, r, t] = await Promise.all([Promise.all(kinds.map(k => getLookups(k))), getRequests(), getTrips({ page: 1 })]); if (generation !== sessionGeneration || !office.value)
    return; lookups.value = Object.fromEntries(kinds.map((k, i) => [k, values[i]!])) as Lookups; counts.value = { requests: r.meta.total, trips: t.meta.total }; }
async function report(error: unknown): Promise<void> { const problem = failure(error); message.value = problem.message; success.value = ''; fields.value = problem.fields; if (problem.status === 0 || problem.status >= 500 || problem.status === 409)
    uncertain.value = true; if (problem.status === 419)
    csrfExpired.value = true; if ([401, 403].includes(problem.status)) {
    session.value = null;
    clear();
    if(officeHost){officeHost.invalidate();return;}
    if (problem.status === 403) {
        initial.value = true;
        try {
            session.value = await getSession();
            if (office.value)
                await load();
        }
        catch (problem) {
            message.value = failure(problem).message;
        }
        finally {
            initial.value = false;
        }
    }
} }
async function mutate(description: string, work: () => Promise<void>): Promise<boolean> { if (busy.value || uncertain.value)
    return false; if (!window.confirm(`¿Confirmar: ${description}?`))
    return false; busy.value = true; message.value = ''; success.value = ''; fields.value = {}; try {
    await work();
    await load();
    success.value = 'Operación confirmada y datos actualizados.';
    return true;
}
catch (error) {
    await report(error);
    return false;
}
finally {
    busy.value = false;
} }
provide(contextKey, { actor: computed(() => session.value?.id ?? ''), lookups, admin, busy, uncertain, errors: fields, mutate, report });
async function signIn(): Promise<void> { if (busy.value)
    return; busy.value = true; message.value = ''; fields.value = {}; try {
    await refreshCsrf();
    session.value = await login(username.value, password.value);
    clear();
    csrfExpired.value = false;
    await load();
}
catch (error) {
    await report(error);
}
finally {
    password.value = '';
    busy.value = false;
} }
async function signOut(): Promise<void> { if (busy.value)
    return; busy.value = true; try {
    await logout();
    session.value = null;
    clear();
    message.value = '';
    success.value = '';
}
catch (error) {
    await report(error);
}
finally {
    busy.value = false;
} }
async function csrf(): Promise<void> { if (busy.value)
    return; busy.value = true; try {
    await refreshCsrf();
    csrfExpired.value = false;
    message.value = 'Seguridad actualizada. Consulta el estado antes de reenviar.';
}
catch (error) {
    await report(error);
}
finally {
    busy.value = false;
} }
async function recover(): Promise<void> { if (busy.value)
    return; busy.value = true; try {
    await load();
    reload.value++;
    uncertain.value = false;
    message.value = 'Estado actualizado. Revisa los registros antes de confirmar otra operación.';
    fields.value = {};
}
catch (error) {
    await report(error);
}
finally {
    busy.value = false;
} }
function navigate(target: string): void {
    if (busy.value) return;
    view.value = target;
    selectedTrip.value = '';
    initialRequest.value = '';
    requestFilter.value = '';
    if (!uncertain.value) { message.value = ''; fields.value = {}; }
    success.value = '';
}
onMounted(async () => { try {
    session.value = officeHost?.context.value?.session ?? await getSession();
    await load();
}
catch (error) {
    if (!(error instanceof ApiError && error.status === 401))
        await report(error);
}
finally {
    initial.value = false;
} });
</script>
<template>
<a href="#transport-main" class="sr-only focus:not-sr-only">Ir al contenido principal</a>
<header v-if="!officeHost" class="border-b border-slate-200 bg-white">
<div class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-4 px-4 py-5 sm:px-6">
<div>
<p class="font-semibold">Transporte de Líquidos</p>
<p class="text-sm text-slate-500">Transporte y Despacho</p>
</div>
<nav v-if="session&&office" aria-label="Navegación interna" class="flex flex-wrap gap-3">
<SharedNavigation workspace :session="session" :disabled="busy||uncertain"/>
<span class="self-center text-sm">{{session.login}} · {{admin?'Administrador':'Secretaria'}}</span>
<button class="btn-secondary" :disabled="busy" @click="signOut">Cerrar sesión</button>
</nav>
<button v-else-if="session" class="btn-secondary" :disabled="busy" @click="signOut">Cerrar sesión</button>
</div>
</header><ExpirationIndicators v-if="view==='dashboard'" :session="session"/>
<main id="transport-main" class="mx-auto max-w-7xl space-y-6 px-4 py-6 sm:px-6" tabindex="-1">
<section v-if="message" class="notice-error" role="alert">
<p>{{message}}</p>
<ul class="mt-2">
<li v-for="(errors,key) in fields" :key="key">{{key}}: {{errors.join(' ')}}</li>
</ul>
<p v-if="!Object.keys(fields).length" class="mt-2">No reenvíes automáticamente una operación sin confirmar su estado.</p>
<button v-if="uncertain&&office" class="btn-secondary mt-3" :disabled="busy" @click="recover">Consultar estado confirmado</button>
<button v-if="csrfExpired" class="btn-secondary mt-3" :disabled="busy" @click="csrf">Actualizar sesión de seguridad</button>
<button class="btn-text" :disabled="busy" @click="message='';fields={}" v-if="!uncertain">Cerrar aviso</button>
</section>
<p v-if="success" class="notice-success" role="status">{{success}}</p>
<p v-if="initial" class="panel" role="status">Comprobando sesión y datos…</p>
<section v-else-if="!session" class="panel mx-auto max-w-md">
<h1 class="text-2xl font-semibold">Acceso a Transporte y Despacho</h1>
<form class="mt-5 space-y-4" :aria-busy="busy" @submit.prevent="signIn">
<fieldset :disabled="busy" class="space-y-4">
<label class="block">Usuario<input v-model="username" class="field mt-2" required autocomplete="username">
</label>
<label class="block">Contraseña<input v-model="password" class="field mt-2" type="password" required autocomplete="current-password">
</label>
<button class="btn-primary" type="submit">{{busy?'Ingresando…':'Iniciar sesión'}}</button>
</fieldset>
</form>
</section>
<section v-else-if="!office" class="panel">
<h1 class="text-xl font-semibold">Acceso no autorizado</h1>
<p class="mt-3">Esta sección está disponible para Administración y Secretaría. Si eres chofer, accede a Mis viajes desde la navegación autorizada.</p>
</section>
<template v-else>
<nav aria-label="Transporte y Despacho" class="flex flex-wrap gap-2">
<button v-for="item in [{id:'dashboard',label:'Panel general'},{id:'requests',label:'Solicitudes'},{id:'trips',label:'Viajes'}]" :key="item.id" class="btn-secondary" :aria-pressed="view===item.id" :disabled="busy" @click="navigate(item.id)">{{item.label}}</button>
</nav>
<section v-if="view==='dashboard'" class="space-y-5">
<h1 class="text-2xl font-semibold">Panel general</h1>
<WorkOverview v-if="!admin" :session="session" office :disabled="busy||uncertain"/>
<div v-if="counts" class="grid gap-4 sm:grid-cols-2">
<article class="panel">
<h2 class="text-sm text-slate-500">Solicitudes registradas</h2>
<p class="mt-2 text-3xl font-semibold">{{counts.requests}}</p>
<button class="btn-text" :disabled="busy" @click="navigate('requests')">Consultar solicitudes</button>
</article>
<article class="panel">
<h2 class="text-sm text-slate-500">Viajes registrados</h2>
<p class="mt-2 text-3xl font-semibold">{{counts.trips}}</p>
<button class="btn-text" :disabled="busy" @click="navigate('trips')">Consultar viajes</button>
</article>
</div>
<p class="notice-info">Planificación, ejecución y cierre administrativo son procesos independientes. Los recursos se liberan manualmente.</p>
<button class="btn-secondary" :disabled="busy" @click="recover">Actualizar resumen</button>
</section>
<RequestBoard :key="reload" v-else-if="view==='requests'" @create-trip="initialRequest=$event;requestFilter='';view='trips'" @view-trips="requestFilter=$event;initialRequest='';view='trips'"/>
<TripDetail v-else-if="selectedTrip" :key="`${selectedTrip}-${reload}`" :id="selectedTrip" @back="selectedTrip='';initialRequest=''"/>
<TripBoard :key="reload" v-else :initial-request="initialRequest" :filter-request="requestFilter" @clear-filter="requestFilter=''" @select="selectedTrip=$event"/>
</template>
</main>
<footer class="mx-auto max-w-7xl px-4 pb-6 text-xs text-slate-500">Fechas y horas en America/Costa_Rica · Litros y kilómetros · Sin almacenamiento offline</footer>
</template>
