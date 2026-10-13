<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { getSession, login, logout, refreshCsrf } from '../FleetAndDocuments/api';
import type { Session } from '../FleetAndDocuments/contracts';
import { page, lookup } from '../TransportAndDispatch/contracts';
import type { Lookup } from '../TransportAndDispatch/contracts';
import { all, detail, failure, list, request, write } from './api';
import type { RecordRow } from './api';
import { instant, labels, localInstant, options, states, terminal, text } from './presentation';
import CommandForm from './components/CommandForm.vue';
import type { Field } from './components/CommandForm.vue';
import RecordTable from './components/RecordTable.vue';
import ScopeForm from './components/ScopeForm.vue';
import WorkshopIcon from './components/WorkshopIcon.vue';
import WorkshopDialog from './components/WorkshopDialog.vue';
import ContentSummary from './components/ContentSummary.vue';
import './workshop.css';
type View = 'impediment' | 'dashboard' | 'orders' | 'reports' | 'authorizations' | 'impediments' | 'order' | 'authorization' | 'report';
interface Command {
    title: string;
    path: string;
    method: string;
    fields: Field[];
    extra: Record<string, unknown>;
    key: string;
    actor: string;
    roles: string;
    scope?: boolean;
}
const session = ref<Session | null>(null), denied = ref(false), booting = ref(true), username = ref(''), password = ref('');
const view = ref<View>('dashboard'), loading = ref(false), busy = ref(false), error = ref(''), success = ref(''), fieldErrors = ref<Record<string, string[]>>({}), csrfExpired = ref(false);
const rows = ref<RecordRow[]>([]), selected = ref<RecordRow | null>(null), related = ref<Record<string, RecordRow[]>>({}), decisions = ref<RecordRow[]>([]), applications = ref<RecordRow[]>([]);
const counts = ref<Record<string, number | null>>({}), metricsError = ref(''), assets = ref<Lookup[]>([]), employees = ref<Lookup[]>([]);
const currentPage = ref(1), lastPage = ref(1), total = ref(0), search = ref(''), status = ref(''), priority = ref(''), assetFilter = ref(''), from = ref(''), to = ref(''), onlyPending = ref(true);
const originReport = ref<RecordRow | null>(null);
const command = ref<Command | null>(null), frozen = ref<Record<string, unknown> | null>(null), uncertain = ref(false), stale = ref(false);
let controller: AbortController | undefined, timer: ReturnType<typeof setInterval> | undefined, generation = 0;
const admin = computed(() => session.value?.roles.includes('administrator') ?? false);
const office = computed(() => session.value?.roles.some(r => ['administrator', 'secretary'].includes(r)) ?? false);
const diagnostician = computed(() => admin.value || session.value?.roles.includes('mechanic'));
const currentAssignments = computed(() => (related.value['mechanic-assignments'] ?? []).filter(a => a.is_current === true && (admin.value || a.is_current_actor === true)));
const canParticipate = computed(() => admin.value || currentAssignments.value.length > 0);
const titles: Record<View, string> = { dashboard: 'Inicio de Taller', orders: 'Órdenes de trabajo', reports: 'Reportes de averías', authorizations: 'Autorizaciones', impediments: 'Vehículos con impedimentos', order: 'Detalle de orden', authorization: 'Detalle de autorización', report: 'Detalle de avería', impediment: 'Detalle de impedimento' };
const visibleRows = computed(() => rows.value.filter(row => !search.value || [row.id, row.description, row.fleet_asset_id].some(v => String(v ?? '').toLocaleLowerCase().includes(search.value.toLocaleLowerCase()))).map(row => ({ ...row, status: labels[String(row.status)] ?? row.status, priority: labels[String(row.priority)] ?? row.priority, type: labels[String(row.type)] ?? row.type, fleet_asset_id: assetName(String(row.fleet_asset_id ?? '')), authorization_state: authorizationState(row) })));
function assetName(id: string): string { return assets.value.find(a => a.id === id)?.code ?? id; }
function decisionFor(row: RecordRow): RecordRow | undefined { return decisions.value.find(d => d.request_id === row.id); }
function applied(d: RecordRow): boolean { return applications.value.some(a => a.decision_id === d.id); }
function authorizationState(row: RecordRow): string { const d = decisionFor(row); return d ? applied(d) ? 'Aplicada' : labels[String(d.decision)] ?? text(d.decision) : 'Pendiente'; }
function clearPrivate(): void { controller?.abort(); generation++; rows.value = []; related.value = {}; selected.value = null; decisions.value = []; applications.value = []; assets.value = []; employees.value = []; counts.value = {}; command.value = null; frozen.value = null; uncertain.value = false; originReport.value = null; loading.value = false; }
async function handle(e: unknown): Promise<void> { const f = failure(e); error.value = f.message; fieldErrors.value = f.fields; csrfExpired.value = f.status === 419; if (f.status === 401) {
    clearPrivate();
    session.value = null;
} if (f.status === 403) {
    clearPrivate();
    denied.value = true;
} if (f.status === 404) {
    selected.value = null;
    related.value = {};
} if (f.status === 409 || f.fields.version || f.fields.request_key)
    stale.value = true; }
async function signIn(): Promise<void> { if (busy.value)
    return; busy.value = true; error.value = ''; try {
    session.value = await login(username.value, password.value);
    password.value = '';
    denied.value = false;
    view.value = 'dashboard';
    await refresh();
}
catch (e) {
    await handle(e);
}
finally {
    busy.value = false;
} }
async function signOut(): Promise<void> { if (busy.value)
    return; busy.value = true; try {
    await logout();
    clearPrivate();
    session.value = null;
    view.value = 'dashboard';
    error.value = '';
    success.value = '';
}
catch (e) {
    await handle(e);
}
finally {
    busy.value = false;
} }
async function loadLookups(signal: AbortSignal): Promise<void> {
    if (!office.value)
        return;
    const path = admin.value ? '/fleet-assets' : '/transport/lookups/assets';
    const result: Lookup[] = [];
    for (let p = 1;; p++) {
        const batch = page(await request(`${path}?per_page=100&page=${p}`, 'GET', undefined, signal), lookup);
        result.push(...batch.data);
        if (p >= batch.meta.last_page)
            break;
    }
    if (!signal.aborted)
        assets.value = result;
    if (admin.value) {
        const result: Lookup[] = [];
        for (let p = 1;; p++) {
            const batch = page(await request(`/employees?per_page=100&page=${p}`, 'GET', undefined, signal), lookup);
            result.push(...batch.data);
            if (p >= batch.meta.last_page)
                break;
        }
        if (!signal.aborted)
            employees.value = result;
    }
}
async function dashboard(signal: AbortSignal): Promise<void> {
    const keys = Object.keys(states);
    const results = await Promise.allSettled([...keys.map(s => list('orders', { status: s, per_page: 1 }, signal)), list('authorization-requests', { pending: 1, per_page: 1 }, signal), all('impediments', { open: 1 }, signal)]);
    if (signal.aborted)
        return;
    const values: Record<string, number | null> = {};
    let unavailable = false;
    results.forEach((result, i) => { const key = keys[i] ?? (i === keys.length ? 'authorizations' : 'vehicles'); if (result.status === 'fulfilled')
        values[key] = Array.isArray(result.value) ? new Set(result.value.map(v => v.fleet_asset_id)).size : result.value.meta.total;
    else {
        values[key] = null;
        unavailable = true;
    } });
    counts.value = values;
    metricsError.value = unavailable ? 'Algunos indicadores no están disponibles. Los valores corresponden a tu acceso autorizado.' : '';
    const failed = results.find(r => r.status === 'rejected' && [401, 403].includes(failure(r.reason).status));
    if (failed?.status === 'rejected')
        throw failed.reason;
}
async function refresh(): Promise<void> {
    controller?.abort();
    controller = new AbortController();
    const signal = controller.signal, run = ++generation;
    loading.value = true;
    error.value = '';
    try {
        const nextSession = await getSession();
        if (signal.aborted || run !== generation)
            return;
        if (session.value && (session.value.id !== nextSession.id || [...session.value.roles].sort().join(',') !== [...nextSession.roles].sort().join(','))) {
            rows.value = [];
            related.value = {};
            selected.value = null;
            decisions.value = [];
            applications.value = [];
            assets.value = [];
            employees.value = [];
            counts.value = {};
            originReport.value = null;
            command.value = null;
            frozen.value = null;
            uncertain.value = false;
            success.value = '';
            view.value = 'dashboard';
        }
        session.value = nextSession;
        if (!session.value.roles.some(r => ['administrator', 'secretary', 'mechanic'].includes(r))) {
            clearPrivate();
            denied.value = true;
            return;
        }
        denied.value = false;
        if (office.value && assets.value.length === 0)
            await loadLookups(signal);
        if (view.value === 'dashboard')
            await dashboard(signal);
        else if (['order', 'authorization', 'report', 'impediment'].includes(view.value) && selected.value) {
            const kind = view.value === 'order' ? 'orders' : view.value === 'report' ? 'reports' : view.value === 'impediment' ? 'impediments' : 'authorization-requests';
            const row = await detail(kind, selected.value.id, signal);
            if (view.value === 'order') {
                const kinds = ['diagnoses', 'mechanic-assignments', 'scopes', 'report-links', 'authorization-requests', 'scope-tasks', 'scope-parts'];
                const values = await Promise.all(kinds.map(k => all(k, { work_order_id: row.id }, signal)));
                if (signal.aborted)
                    return;
                related.value = Object.fromEntries(kinds.map((k, i) => [k, values[i] ?? []]));
            }
            if (['order', 'authorization'].includes(view.value)) {
                const filter = view.value === 'order' ? { work_order_id: row.id } : {};
                const [d, a] = await Promise.all([all('authorization-decisions', filter, signal), all('authorization-applications', filter, signal)]);
                if (signal.aborted)
                    return;
                decisions.value = d;
                applications.value = a;
            }
            if (signal.aborted)
                return;
            selected.value = row;
        }
        else {
            const kind = view.value === 'orders' ? 'orders' : view.value === 'reports' ? 'reports' : view.value === 'impediments' ? 'impediments' : 'authorization-requests';
            const filters: Record<string, string | number> = { page: currentPage.value };
            if (assetFilter.value)
                filters.fleet_asset_id = assetFilter.value;
            if (view.value === 'orders') {
                if (status.value)
                    filters.status = status.value;
                if (priority.value)
                    filters.priority = priority.value;
                if (from.value)
                    filters.from = instant(from.value);
                if (to.value)
                    filters.to = instant(to.value);
            }
            if (view.value === 'impediments')
                filters.open = 1;
            if (view.value === 'authorizations' && onlyPending.value)
                filters.pending = 1;
            const result = await list(kind, filters, signal);
            if (view.value === 'authorizations') {
                const [d, a] = await Promise.all([all('authorization-decisions', {}, signal), all('authorization-applications', {}, signal)]);
                if (signal.aborted)
                    return;
                decisions.value = d;
                applications.value = a;
            }
            if (signal.aborted)
                return;
            rows.value = result.data;
            lastPage.value = result.meta.last_page;
            total.value = result.meta.total;
        }
    }
    catch (e) {
        if (!(e instanceof DOMException && e.name === 'AbortError') && run === generation)
            await handle(e);
    }
    finally {
        if (run === generation)
            loading.value = false;
    }
}
async function navigate(target: View, row?: RecordRow): Promise<void> { if (busy.value || uncertain.value)
    return; command.value = null; selected.value = row ?? null; view.value = target; rows.value = []; related.value = {}; search.value = ''; currentPage.value = 1; success.value = ''; await refresh(); }
const reason: Field = { key: 'reason', label: 'Motivo / información recibida', type: 'textarea', required: true };
const description = (value = ''): Field => ({ key: 'description', label: 'Descripción de la reparación', type: 'textarea', required: true, value });
const time = (key: string, label: string): Field => ({ key, label, type: 'datetime-local', required: true, value: localInstant() });
const assetField = (value = ''): Field => ({ key: 'fleet_asset_id', label: 'Vehículo', required: true, value, ...(assets.value.length ? { options: assets.value.map(a => ({ value: a.id, label: a.code ?? a.id })) } : { type: 'text' }) });
const priorityField = (value = 'normal'): Field => ({ key: 'priority', label: 'Prioridad', required: true, value, options: options({ low: 'Baja', normal: 'Normal', high: 'Alta', urgent: 'Urgente' }) });
function open(title: string, path: string, fields: Field[], extra: Record<string, unknown> = {}, method = 'POST', scope = false): void { error.value = ''; success.value = ''; fieldErrors.value = {}; frozen.value = null; uncertain.value = false; stale.value = false; command.value = { title, path, fields, extra, method, key: crypto.randomUUID(), actor: session.value?.id ?? '', roles: [...(session.value?.roles ?? [])].sort().join(','), scope }; }
function newReport(): void { open('Registrar avería', 'reports', [assetField(), { ...description(), label: 'Descripción de la avería' }, time('occurred_at', 'Fecha de la avería (Costa Rica)'), reason]); }
function newOrder(report?: RecordRow): void { originReport.value = report ?? null; open('Crear orden', 'orders', [assetField(report ? String(report.fleet_asset_id) : ''), { key: 'kind', label: 'Tipo de mantenimiento', required: true, value: 'corrective', options: options({ corrective: 'Correctivo', preventive: 'Preventivo' }) }, description(report ? String(report.description) : ''), priorityField(), time('created_at', 'Fecha de creación (Costa Rica)'), reason]); }
function orderAction(action: string): void {
    const row = selected.value;
    if (!row)
        return;
    const base = `orders/${row.id}`;
    if (action === 'edit')
        open('Editar orden', base, [description(String(row.description)), priorityField(String(row.priority)), reason], { version: row.version }, 'PATCH');
    if (action === 'assign')
        open('Asignar mecánico', `${base}/mechanic-assignments`, [{ key: 'employee_id', label: 'Empleado mecánico (UUID)', required: true, ...(employees.value.length ? { options: employees.value.map(e => ({ value: e.id, label: e.name ?? e.id })) } : { type: 'text' }) }, time('starts_at', 'Inicio de asignación (Costa Rica)'), reason]);
    if (action === 'link')
        open('Vincular avería', `${base}/report-links`, [{ key: 'report_id', label: 'Identificador de avería (UUID)', required: true, value: originReport.value?.id ?? '' }, reason]);
    if (action === 'diagnosis')
        open('Registrar diagnóstico', `${base}/diagnoses`, [{ key: 'mechanic_assignment_id', label: 'Asignación mecánica vigente', required: !admin.value, options: (related.value['mechanic-assignments'] ?? []).filter(a => a.is_current === true && (admin.value || a.is_current_actor === true)).map(a => ({ value: a.id, label: String(a.employee_name ?? a.employee_id) })) }, { key: 'problem', label: 'Problema encontrado', type: 'textarea', required: true }, { key: 'probable_cause', label: 'Causa probable', type: 'textarea', required: true }, { key: 'proposed_repair', label: 'Reparación propuesta', type: 'textarea', required: true }, time('occurred_at', 'Fecha de diagnóstico (Costa Rica)'), reason]);
    if (action === 'scope')
        open('Solicitar autorización de alcance', `${base}/scopes`, [], {}, 'POST', true);
    if (['cancellation', 'reopening', 'rectification'].includes(action))
        open(`Solicitar ${labels[action]?.toLowerCase()}`, `${base}/authorization-requests`, [...(action === 'rectification' ? [description(String(row.description)), priorityField(String(row.priority))] : []), { key: 'previous_request_id', label: 'Solicitud anterior rechazada (UUID, opcional)' }, reason], { type: action, version: row.version });
}
async function submit(values: Record<string, unknown>): Promise<void> {
    if (busy.value || stale.value || !command.value)
        return;
    const c = command.value;
    const payload: Record<string, unknown> = { ...c.extra };
    for (const [key, value] of Object.entries(values))
        if (value !== '' && value !== undefined)
            payload[key] = typeof value === 'string' && ['occurred_at', 'created_at', 'starts_at'].includes(key) ? instant(value) : value;
    if (!frozen.value)
        frozen.value = { ...payload, request_key: c.key };
    busy.value = true;
    error.value = '';
    fieldErrors.value = {};
    try {
        const current = await getSession();
        if (current.id !== c.actor || [...current.roles].sort().join(',') !== c.roles) {
            clearPrivate();
            session.value = current;
            view.value = 'dashboard';
            await refresh();
            error.value = 'La identidad o los permisos cambiaron. Revisa los datos e inicia una nueva operación.';
            return;
        }
        const result = await write(c.path, c.method, frozen.value);
        uncertain.value = false;
        command.value = null;
        frozen.value = null;
        success.value = 'Operación confirmada y datos actualizados.';
        if (typeof result.order_id === 'string' && c.path === 'orders') {
            view.value = 'order';
            selected.value = { id: result.order_id };
        }
        if (c.path.endsWith('/report-links'))
            originReport.value = null;
        if (typeof result.report_id === 'string' && c.path === 'reports') {
            view.value = 'report';
            selected.value = { id: result.report_id };
        }
        await refresh();
    }
    catch (e) {
        const f = failure(e);
        uncertain.value = f.status === 0 || f.status >= 500;
        if (!uncertain.value)
            frozen.value = null;
        await handle(e);
    }
    finally {
        busy.value = false;
    }
}
async function retry(): Promise<void> { if (!frozen.value || !command.value)
    return; await submit({}); }
function cancel(): void { if (busy.value || uncertain.value)
    return; command.value = null; fieldErrors.value = {}; error.value = ''; }
function decide(): void { if (!selected.value)
    return; open('Registrar decisión', `authorization-requests/${selected.value.id}/decisions`, [{ key: 'decision', label: 'Decisión', required: true, options: options({ approved: 'Aprobar', rejected: 'Rechazar' }) }, reason]); }
function applicationPath(row: RecordRow): string {
    const content = row.content && typeof row.content === 'object' ? row.content as Record<string, unknown> : {};
    if (content.fact_table)
        return '';
    if (row.scope_id)
        return 'apply-scope';
    if (row.type === 'exit')
        return 'exit';
    return row.work_order_id && ['cancellation', 'reopening', 'rectification'].includes(String(row.type)) ? 'apply-order' : '';
}
function applyDecision(): void { const row = selected.value; if (!row)
    return; const d = decisionFor(row), path = applicationPath(row); if (d && path)
    open('Aplicar autorización', `decisions/${d.id}/${path}`, []); }
async function fixCsrf(): Promise<void> { try {
    await refreshCsrf();
    csrfExpired.value = false;
    error.value = 'Sesión de seguridad actualizada. Revisa y envía el formulario.';
}
catch (e) {
    await handle(e);
} }
onMounted(async () => { try {
    session.value = await getSession();
    await refresh();
}
catch (e) {
    if (failure(e).status !== 401)
        await handle(e);
}
finally {
    booting.value = false;
} timer = setInterval(() => { if (session.value && !denied.value && !command.value && !loading.value && !busy.value && !document.hidden)
    void refresh(); }, 30000); });
onUnmounted(() => { controller?.abort(); if (timer)
    clearInterval(timer); });
const orderColumns = [{ key: 'id', label: 'Orden' }, { key: 'fleet_asset_id', label: 'Vehículo' }, { key: 'description', label: 'Reparación' }, { key: 'status', label: 'Estado' }, { key: 'priority', label: 'Prioridad' }];
function openRow(row: RecordRow): void { void navigate(view.value === 'orders' ? 'order' : view.value === 'reports' ? 'report' : view.value === 'authorizations' ? 'authorization' : 'impediment', { id: row.id }); }
</script>

<template>
<div class="workshop">
    <div v-if="booting" class="workshop-login" role="status">Cargando Taller Mecánico…</div>
    <div v-else-if="!session" class="workshop-login">
        <form class="workshop-card" aria-label="Iniciar sesión" @submit.prevent="signIn">
<span class="workshop-eyebrow">TRANSPORTES LÍQUIDOS</span>
<h1>Acceso a Taller Mecánico</h1>
<p>Gestión de averías, órdenes y autorizaciones.</p>
<div v-if="error" role="alert">{{ error }}</div>
<label>Usuario<input v-model="username" required autocomplete="username">
</label>
<label>Contraseña<input v-model="password" type="password" required autocomplete="current-password">
</label>
<button :disabled="busy">{{ busy?'Ingresando…':'Iniciar sesión' }}</button>
</form>
    </div>
    <template v-else>
        <aside class="workshop-sidebar">
<a class="workshop-brand" href="/">TL <span>Transportes Líquidos<small>Gestión operativa</small>
</span>
</a>
<p class="workshop-eyebrow">TALLER MECÁNICO</p>
<nav aria-label="Navegación Taller">
<button v-for="item in (['dashboard','orders','reports','authorizations','impediments'] as const)" :key="item" :aria-current="view===item?'page':undefined" :disabled="busy || uncertain || denied" @click="navigate(item)">
<WorkshopIcon :name="item" /> {{ {dashboard:'Inicio',orders:'Órdenes',reports:'Averías',authorizations:'Autorizaciones',impediments:'Impedimentos'}[item] }}</button>
</nav>
<nav v-if="office" aria-label="Otros módulos">
<a href="/transporte">Transporte y despacho ↗</a>
<a href="/documentacion">Flota y documentos ↗</a>
</nav>
<small>Una reparación por orden.<br>Autorización antes de ejecución.</small>
</aside>
        <main class="workshop-main">
<header class="workshop-topbar">
<span>Taller Mecánico</span>
<div>
<span>{{ session.login ?? `Usuario ${session.id}` }}</span> <button :disabled="busy || uncertain" @click="signOut">Cerrar sesión</button>
</div>
</header>
        <div class="workshop-content">
<div class="workshop-page-heading">
<div>
<p class="workshop-eyebrow">OPERACIÓN Y CONTROL</p>
<h1>{{ denied?'Acceso no autorizado':titles[view] }}</h1>
<p>{{ office?'Coordinación de Taller':'Consulta de órdenes asignadas' }} · Información del servidor</p>
</div>
<button v-if="!denied" :disabled="loading || busy || !!command" @click="refresh">Actualizar datos</button>
</div>
        <div v-if="error" role="alert" class="workshop-alert">{{ error }}<button v-if="csrfExpired" @click="fixCsrf">Actualizar sesión de seguridad</button>
</div>
<div v-if="success" role="status" class="workshop-success">{{ success }}</div>
<p v-if="loading" role="status">Cargando información…</p>
        <p v-if="denied">Tu cuenta no tiene acceso vigente a esta operación. Contacta al administrador o cierra sesión.</p>
        <template v-else-if="view==='dashboard'">
            <p v-if="metricsError" role="status">{{ metricsError }}</p>
<div class="workshop-grid workshop-metrics">
<article v-for="(label,key) in states" :key="key" class="workshop-card">
<span>{{ label }}</span>
<strong>{{ counts[key] ?? '—' }}</strong>
<button @click="status=key; navigate('orders')">Consultar órdenes</button>
</article>
<article class="workshop-card">
<span>Autorizaciones pendientes</span>
<strong>{{ counts.authorizations ?? '—' }}</strong>
<button @click="onlyPending=true;navigate('authorizations')">Gestionar autorizaciones</button>
</article>
<article class="workshop-card">
<span>Vehículos con impedimentos abiertos</span>
<strong>{{ counts.vehicles ?? '—' }}</strong>
<button @click="navigate('impediments')">Consultar impedimentos</button>
</article>
</div>
            <section class="workshop-card">
<h2>Accesos rápidos</h2>
<div class="workshop-actions">
<button @click="newReport">Registrar avería</button>
<button @click="navigate('orders')">Consultar órdenes</button>
<button @click="navigate('authorizations')">Gestionar autorizaciones</button>
<button v-if="office" @click="newOrder()">Crear orden</button>
</div>
</section>
<section class="workshop-card">
<h2>Actividad reciente</h2>
<p>La API no expone un historial consolidado de actividad. Consulta los registros y las fechas en el detalle de cada orden.</p>
<button @click="navigate('reports')">Consultar averías registradas</button>
</section>
        </template>
        <template v-else-if="['orders','reports','authorizations','impediments'].includes(view)">
            <div class="workshop-actions">
<button v-if="view==='reports'" @click="newReport">Registrar avería</button>
<button v-if="view==='orders' && office" @click="newOrder()">Crear orden</button>
</div>
            <form class="workshop-card workshop-filters" aria-label="Filtros" @submit.prevent="currentPage=1;refresh()">
<label v-if="view==='orders'">Estado<select v-model="status">
<option value="">Todos</option>
<option v-for="(label,key) in states" :key="key" :value="key">{{ label }}</option>
</select>
</label>
<label v-if="view==='orders'">Prioridad<select v-model="priority">
<option value="">Todas</option>
<option v-for="item in options({low:'Baja',normal:'Normal',high:'Alta',urgent:'Urgente'})" :key="item.value" :value="item.value">{{ item.label }}</option>
</select>
</label>
<label>Vehículo (UUID)<input v-model="assetFilter" placeholder="Filtrar por identificador">
</label>
<label v-if="view==='orders'">Desde (Costa Rica)<input v-model="from" type="datetime-local">
</label>
<label v-if="view==='orders'">Hasta (Costa Rica)<input v-model="to" type="datetime-local">
</label>
<label v-if="view==='authorizations'">
<input v-model="onlyPending" type="checkbox"> Solo pendientes</label>
<button :disabled="loading">Aplicar filtros</button>
</form>
            <label>Buscar en esta página<input v-model="search" type="search" placeholder="Identificador, vehículo o descripción">
</label>
<p>{{ total }} registros accesibles · Página {{ currentPage }} de {{ lastPage }}</p>
<RecordTable selectable :rows="visibleRows" :columns="view==='orders'?orderColumns:view==='reports'?[{key:'id',label:'Avería'},{key:'fleet_asset_id',label:'Vehículo'},{key:'description',label:'Descripción'},{key:'occurred_at',label:'Fecha'}]:view==='authorizations'?[{key:'type',label:'Tipo'},{key:'reason',label:'Motivo'},{key:'authorization_state',label:'Estado'},{key:'registered_at',label:'Solicitada'}]:[{key:'fleet_asset_id',label:'Vehículo'},{key:'description',label:'Impedimento'},{key:'effect',label:'Restricción'}]" @open="openRow"/>
<div class="workshop-actions">
<button :disabled="loading || currentPage<=1" @click="currentPage--;refresh()">Anterior</button>
<button :disabled="loading || currentPage>=lastPage" @click="currentPage++;refresh()">Siguiente</button>
</div>
        </template>
        <template v-else-if="selected && view==='order'">
            <section v-if="originReport && office" class="workshop-card">
<h2>Avería de origen</h2>
<p>{{ originReport.description }}</p>
<button @click="orderAction('link')">Vincular esta avería a la orden</button>
</section>
<section class="workshop-card">
<h2>{{ selected.description }}</h2>
<p>{{ assetName(String(selected.fleet_asset_id)) }} · <span class="workshop-badge" :class="String(selected.status).startsWith('pending')?'pending':['finished','closed'].includes(String(selected.status))?'complete':selected.status==='cancelled'?'blocked':''">{{ labels[String(selected.status)] }}</span> · Prioridad {{ labels[String(selected.priority)] }} · Versión {{ selected.version }}</p>
<p>Orden {{ selected.id }} · {{ selected.created_at }}</p>
<div class="workshop-actions">
<button v-if="office && !terminal(selected)" @click="orderAction('edit')">Editar orden</button>
<button v-if="office && !terminal(selected)" @click="orderAction('assign')">Asignar mecánico</button>
<button v-if="office" @click="orderAction('link')">Vincular avería</button>
<button v-if="diagnostician && canParticipate && !terminal(selected)" @click="orderAction('diagnosis')">Registrar diagnóstico</button>
<button v-if="(office || canParticipate) && !terminal(selected) && (related.diagnoses ?? []).length" @click="orderAction('scope')">Solicitar autorización de alcance</button>
<button v-if="office && !terminal(selected)" @click="orderAction('cancellation')">Solicitar cancelación</button>
<button v-if="office && terminal(selected)" @click="orderAction('reopening')">Solicitar reapertura</button>
<button v-if="office" @click="orderAction('rectification')">Solicitar rectificación</button>
</div>
</section>
            <section class="workshop-card">
<h2>Mecánicos asignados</h2>
<p v-if="!admin && !office">El servidor comprueba tu participación vigente al registrar diagnósticos y propuestas.</p>
<RecordTable :rows="related['mechanic-assignments'] ?? []" :columns="[{key:'employee_name',label:'Empleado'},{key:'starts_at',label:'Desde'},{key:'is_released',label:'Liberado'}]"/>
</section>
            <section class="workshop-card">
<h2>Diagnósticos</h2>
<p v-if="!(related.diagnoses ?? []).length">No hay diagnósticos. Se requiere un diagnóstico antes de proponer el alcance.</p>
<article v-for="d in related.diagnoses" :key="d.id">
<h3>{{ d.problem }}</h3>
<p>Causa probable: {{ d.probable_cause }}</p>
<p>Reparación propuesta: {{ d.proposed_repair }}</p>
<small>{{ d.occurred_at }} · Revisión {{ d.revision }} · {{ d.is_effective?'Vigente':'Histórica' }}</small>
</article>
</section>
            <section class="workshop-card">
<h2>Alcances propuestos y autorizados</h2>
<p v-if="!(related.scopes ?? []).length">Sin alcances registrados.</p>
<article v-for="s in related.scopes" :key="s.id">
<h3>{{ s.description }}</h3>
<p>{{ labels[String(s.scope_kind)] }} · {{ s.is_applied?'Aplicado':'Sin aplicar' }} · {{ s.is_effective?'Vigente':'Histórico' }}</p>
<ul>
<li v-for="t in (related['scope-tasks'] ?? []).filter(t=>t.scope_id===s.id)" :key="t.id">{{ t.line_number }}. {{ t.description }}</li>
</ul>
<p v-for="p in (related['scope-parts'] ?? []).filter(p=>p.scope_id===s.id)" :key="p.id">{{ p.description_snapshot }}: {{ p.quantity }} {{ p.unit_snapshot }}</p>
</article>
</section>
            <section class="workshop-card">
<h2>Autorizaciones de la orden</h2>
<p v-if="!(related['authorization-requests'] ?? []).length">Sin solicitudes.</p>
<article v-for="a in related['authorization-requests']" :key="a.id">
<h3>{{ labels[String(a.type)] }} · {{ authorizationState(a) }}</h3>
<p>{{ a.reason }}</p>
<button @click="navigate('authorization',a)">Revisar autorización</button>
</article>
</section>
<section class="workshop-card">
<h2>Averías vinculadas</h2>
<p v-if="!(related['report-links'] ?? []).length">Sin averías vinculadas.</p>
<p v-for="l in related['report-links']" :key="l.id">{{ l.report_id }} <button @click="navigate('report',{id:String(l.report_id)})">Consultar avería</button>
</p>
</section>
            <p>La ejecución de reparaciones, tiempos, inventario, costos y adjuntos corresponde a la segunda entrega. La orden conserva su estado confirmado.</p>
        </template>
        <template v-else-if="selected && view==='impediment'">
<section class="workshop-card">
<h2>{{ selected.description }}</h2>
<p>Vehículo: {{ assetName(String(selected.fleet_asset_id)) }}</p>
<p>Restricción: {{ labels[String(selected.effect)] }} · {{ selected.is_open?'Abierto':'Resuelto' }}</p>
<p>{{ selected.reason }} · {{ selected.occurred_at }}</p>
<button v-if="selected.work_order_id" @click="navigate('order',{id:String(selected.work_order_id)})">Consultar orden vinculada</button>
<p>El estado de la orden no libera el vehículo. La salida y la resolución de impedimentos requieren los procesos administrativos del backend.</p>
</section>
</template>
        <template v-else-if="selected && view==='report'">
<section class="workshop-card">
<h2>{{ selected.description }}</h2>
<p>Identificador: {{ selected.id }}</p>
<p>Vehículo: {{ assetName(String(selected.fleet_asset_id)) }} · {{ selected.occurred_at }}</p>
<p>{{ selected.reason }}</p>
<p>Revisión {{ selected.revision }} · {{ selected.is_effective?'Vigente':'Histórica' }}</p>
<button v-if="office" @click="newOrder(selected)">Crear orden para esta avería</button>
<p v-if="office">Después de crear la orden, vincula el identificador de esta avería en su detalle. Son operaciones independientes.</p>
<p v-else>La oficina debe crear la orden oficial y asignar sus mecánicos.</p>
</section>
</template>
        <template v-else-if="selected && view==='authorization'">
<section class="workshop-card">
<h2>{{ labels[String(selected.type)] }} · {{ authorizationState(selected) }}</h2>
<p>Solicitud {{ selected.id }}</p>
<p>{{ selected.reason }}</p>
<p>Fecha {{ selected.registered_at }} · Solicitante {{ selected.requested_by_id }} · Versión objetivo {{ selected.target_version ?? 'No aplica' }}</p>
<button v-if="selected.work_order_id" @click="navigate('order',{id:String(selected.work_order_id)})">Consultar orden</button>
<h3>Contenido solicitado</h3>
<ContentSummary :value="selected.content" />
<button v-if="admin && !decisionFor(selected)" @click="decide">Registrar decisión</button>
<p v-else-if="!decisionFor(selected)">Pendiente de decisión exclusiva del administrador.</p>
<template v-if="decisionFor(selected)">
<h3>Historial de decisión</h3>
<p>{{ labels[String(decisionFor(selected)?.decision)] }} · {{ decisionFor(selected)?.registered_at }}</p>
<p>{{ decisionFor(selected)?.reason }}</p>
<p v-if="applied(decisionFor(selected)!)">Autorización aplicada.</p>
<button v-else-if="admin && decisionFor(selected)?.decision==='approved' && applicationPath(selected)" @click="applyDecision">Aplicar autorización</button>
<p v-if="decisionFor(selected)?.decision==='approved' && !applicationPath(selected)">La aplicación requiere el proceso específico de ejecución, materiales o evidencias de la segunda entrega. La aprobación conserva su contenido y no modifica la orden por esta pantalla.</p>
</template>
</section>
</template>
        </div>
</main>
    </template>
    <WorkshopDialog v-if="command && session && !denied" :title="command.title" @cancel="cancel">
<div v-if="error" role="alert" class="workshop-alert">{{ error }}<p v-for="(items,key) in fieldErrors" :key="key">{{ key }}: {{ items.join(' ') }}</p>
</div>
<p v-if="uncertain">La respuesta no confirmó el resultado. Se conserva la operación exacta y su clave para evitar duplicados.</p>
<button v-if="uncertain" :disabled="busy" @click="retry">Reintentar la misma operación</button>
<button v-if="csrfExpired" @click="fixCsrf">Actualizar sesión de seguridad</button>
<p v-if="stale">El estado cambió. Cierra este formulario, actualiza los datos y revisa una nueva operación.</p>
<ScopeForm v-if="command.scope" :diagnoses="related.diagnoses ?? []" :scopes="related.scopes ?? []" :busy="busy || uncertain || stale" :errors="fieldErrors" @submit="submit" @cancel="cancel"/>
<CommandForm v-else :title="command.title" :fields="command.fields" :busy="busy || uncertain || stale" :errors="fieldErrors" @submit="submit" @cancel="cancel"/>
<button v-if="stale" @click="cancel">Cerrar y revisar estado</button>
</WorkshopDialog>
</div>
</template>
