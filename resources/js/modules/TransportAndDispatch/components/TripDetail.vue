<script setup lang="ts">
import { computed, onMounted, onBeforeUnmount, ref, shallowRef } from 'vue';
import { ApiError, differences, getAuthorizations, getTrip, write } from '../api';
import type { Authorization, Json, Trip } from '../contracts';
import { approvalMatches, captured, date, instant, labels, localDate, number, sum } from '../presentation';
import { useTransport } from '../context';
import CommandForm from './CommandForm.vue';
import type { Field } from './CommandForm.vue';
import AssignmentPanel from './AssignmentPanel.vue';
import OperationsPanel from './OperationsPanel.vue';
import OdometerPanel from './OdometerPanel.vue';
import AuthorizationPanel from './AuthorizationPanel.vue';
import HistoryList from './HistoryList.vue';
const props = defineProps<{
    id: string;
}>();
const emit = defineEmits<{
    back: [
    ];
}>();
const ctx = useTransport();
const trip = ref<Trip | null>(null);
const authorizations = shallowRef<Authorization[]>([]);
const difference = shallowRef<Json>(null);
const loading = ref(false);
const tab = ref('summary');
const action = ref('');
const revision = ref(0);
const historyKind = ref('history');
let alive = true;
let controller: AbortController | undefined;
async function refresh(): Promise<void> { controller?.abort(); const current = new AbortController(); controller = current; loading.value = true; try {
    const [t, a, d] = await Promise.all([getTrip(props.id, current.signal), getAuthorizations(props.id, current.signal), differences(props.id)]);
    if (alive && !current.signal.aborted) {
        trip.value = t;
        authorizations.value = a;
        difference.value = d;
        revision.value++;
    }
}
catch (error) {
    if (alive && !current.signal.aborted) {
        if (error instanceof ApiError && error.status === 404) {
            trip.value = null;
            authorizations.value = [];
            difference.value = null;
        }
        await ctx.report(error);
    }
}
finally {
    if (alive && !current.signal.aborted)
        loading.value = false;
} }
const volumeApprovals = computed(() => trip.value ? authorizations.value.filter(a => approvalMatches(a, trip.value!, difference.value, 'volume_difference')) : []);
const ready = computed(() => trip.value ? captured(trip.value) : false);
const assignmentsReady = computed(() => trip.value?.current_driver_assignments?.length === 1 && trip.value.current_asset_assignments?.filter(a => a.asset_type === 'tractor_unit').length === 1 && trip.value.current_asset_assignments?.filter(a => a.asset_type === 'tanker').length === 1);
const fields = computed<Field[]>(() => [{ key: 'reason', label: 'Motivo / confirmación', type: 'textarea', required: true }, ...(['schedule', 'reschedule'].includes(action.value) ? [{ key: 'scheduled_start_at', label: 'Inicio programado · Costa Rica', type: 'datetime-local', step: '1', required: true, value: localDate(trip.value?.scheduled_start_at ?? new Date().toISOString()) }, { key: 'scheduled_end_at', label: 'Fin programado · Costa Rica', type: 'datetime-local', step: '1', value: trip.value?.scheduled_end_at ? localDate(trip.value.scheduled_end_at) : '' }] : []), ...(action.value === 'finish' && difference.value !== null ? [{ key: 'decision_id', label: 'Autorización exacta de diferencia', required: true, options: volumeApprovals.value.map(a => ({ value: a.decision!.id, label: `${a.reason} · ${a.decision!.id}` })) }] : []), ...(action.value === 'close' ? [{ key: 'pending', label: 'Pendientes a conservar (uno por línea)', type: 'textarea' }] : [])]);
const actionLabels: Record<string, string> = { schedule: 'Programar viaje', reschedule: 'Reprogramar viaje', start: 'Iniciar viaje oficialmente', finish: 'Finalizar viaje oficialmente', cancel: 'Cancelar viaje', close: 'Cerrar administrativamente' };
async function save(v: Record<string, string>): Promise<void> { if (!trip.value)
    return; const body: Record<string, unknown> = { version: trip.value.version, reason: v.reason }; if (['schedule', 'reschedule'].includes(action.value)) {
    body.scheduled_start_at = instant(v.scheduled_start_at!);
    body.scheduled_end_at = v.scheduled_end_at ? instant(v.scheduled_end_at) : null;
} if (action.value === 'finish' && difference.value !== null)
    body.decision_id = v.decision_id; if (action.value === 'close' && v.pending?.trim())
    body.warnings = v.pending.split('\n').filter(line => line.trim()).map(line => ({ code: 'pending_information', evidence: { detail: line.trim() } })); await write(`/trips/${props.id}/${action.value}`, body); action.value = ''; await refresh(); }
async function requestDifference(v: Record<string, string>): Promise<void> { await write(`/trips/${props.id}/authorizations`, { type: 'volume_difference', reason: v.reason }); await refresh(); }
onMounted(() => refresh());
onBeforeUnmount(() => { alive = false; controller?.abort(); });
</script>
<template>
<section class="space-y-5">
<div class="flex flex-wrap justify-between gap-3">
<button class="btn-secondary" :disabled="ctx.busy.value" @click="emit('back')">Volver a viajes</button>
<button class="btn-secondary" :disabled="loading||ctx.busy.value" @click="refresh">Actualizar viaje</button>
</div>
<p v-if="loading" class="notice-info" role="status">Consultando detalle actualizado…</p>
<template v-if="trip">
<section class="panel">
<h1 class="text-xl font-semibold">Viaje · {{trip.product_name_snapshot}}</h1>
<p class="mt-1 break-all text-xs text-slate-500">{{trip.id}} · Solicitud {{trip.transport_request_id}} · Versión {{trip.version}}</p>
<div class="mt-3 flex flex-wrap gap-3">
<span class="rounded-full bg-slate-100 px-3 py-1 text-sm">Planificación: {{labels[trip.planning_status]}}</span>
<span class="rounded-full bg-teal-50 px-3 py-1 text-sm text-teal-900">Ejecución: {{labels[trip.execution_status]}}</span>
<span class="rounded-full bg-slate-100 px-3 py-1 text-sm">Administración: {{labels[trip.administrative_status]}}</span>
</div>
</section>
<nav aria-label="Secciones del viaje" class="flex flex-wrap gap-2">
<button v-for="section in [{id:'summary',name:'Resumen y estados'},{id:'assignments',name:'Recursos'},{id:'operations',name:'Carga y descarga'},{id:'odometer',name:'Kilometraje'},{id:'authorizations',name:'Autorizaciones'},{id:'history',name:'Historial y reportes'}]" :key="section.id" class="btn-secondary" :aria-pressed="tab===section.id" :disabled="ctx.busy.value||loading" @click="tab=section.id;action=''">{{section.name}}</button>
</nav>
<section v-if="tab==='summary'" class="space-y-5">
<div class="panel grid gap-4 sm:grid-cols-2">
<p v-if="trip.request">Cliente: {{ctx.lookups.value.customers.find(c=>c.id===trip!.request?.customer_id)?.name??trip.request.customer_id}}</p>
<p v-if="trip.request">Ruta: {{ctx.lookups.value.routes.find(r=>r.id===trip!.request?.route_id)?.name??trip.request.route_id}}</p>
<p v-if="trip.request">Origen previsto: {{ctx.lookups.value.sites.find(s=>s.id===ctx.lookups.value.routes.find(r=>r.id===trip!.request?.route_id)?.origin_site_id)?.name??'Consultar ruta'}}</p>
<p v-if="trip.request">Destino previsto: {{ctx.lookups.value.sites.find(s=>s.id===ctx.lookups.value.routes.find(r=>r.id===trip!.request?.route_id)?.destination_site_id)?.name??'Consultar ruta'}}</p>
<p>Programado: {{date(trip.scheduled_start_at)}}<br>Fin previsto: {{date(trip.scheduled_end_at)}}</p>
<p>Inicio oficial: {{date(trip.officially_started_at)}}<br>Fin oficial: {{date(trip.officially_completed_at)}}</p>
<p>Cancelación: {{date(trip.cancelled_at)}}<br>{{trip.cancellation_reason}}</p>
<p>Cierre administrativo: {{date(trip.closed_at)}}</p>
<p>Cargado: {{trip.load?`${number(sum(trip.load.items.map(i=>i.loaded_liters)))} L`:'Sin registrar'}}</p>
<p>Descargado: {{trip.unload?`${number(sum(trip.unload.items.map(i=>i.delivered_liters)))} L`:'Sin registrar'}}</p>
</div>
<p class="notice-info">Confirmar finalización no libera recursos. El reporte del conductor es informativo y no es obligatorio. Los permisos de salida pendientes de RN-TR-17 no se resuelven aquí.</p>
<p v-if="difference!==null" class="notice-error">Hay diferencias de litros por compartimento, aunque los totales puedan coincidir. Requieren autorización administrativa exacta antes de finalizar.</p>
<details v-if="difference!==null" class="panel">
<summary class="btn-text">Revisar diferencias registradas</summary>
<pre class="whitespace-pre-wrap break-words text-xs">{{JSON.stringify(difference,null,2)}}</pre>
</details>
<div class="panel flex flex-wrap gap-3">
<template v-if="trip.execution_status==='pending'&&!trip.officially_started_at">
<button class="btn-secondary" :disabled="ctx.busy.value||loading" @click="action=trip.scheduled_start_at?'reschedule':'schedule'">{{trip.scheduled_start_at?'Reprogramar viaje':'Programar viaje'}}</button>
<button class="btn-primary" :disabled="ctx.busy.value||loading||!assignmentsReady" @click="action='start'">Iniciar oficialmente</button>
<p v-if="!assignmentsReady" class="w-full text-sm text-amber-800">Asigna conductor, cabezal y cisterna antes del inicio.</p>
</template>
<template v-if="trip.execution_status==='in_progress'">
<button class="btn-primary" :disabled="ctx.busy.value||loading||!ready||(difference!==null&&!volumeApprovals.length)" @click="action='finish'">Finalizar oficialmente</button>
<p v-if="!ready" class="w-full text-sm text-amber-800">La finalización requiere carga, descarga y un registro descargado por cada detalle de carga, incluso cuando es cero.</p>
</template>
<button v-if="['pending','in_progress'].includes(trip.execution_status)" class="btn-secondary" :disabled="ctx.busy.value||loading" @click="action='cancel'">Cancelar viaje</button>
<button v-if="['completed','cancelled'].includes(trip.execution_status)&&trip.administrative_status==='open'" class="btn-primary" :disabled="ctx.busy.value||loading" @click="action='close'">Cerrar administrativamente</button>
</div>
<CommandForm :key="action" v-if="action" :title="actionLabels[action]!" :fields="fields" :submit="save" :disabled="loading"/>
<CommandForm v-if="difference!==null&&ready&&trip.execution_status==='in_progress'" title="Solicitar autorización de diferencia" :fields="[{key:'reason',label:'Justificación de la diferencia',type:'textarea',required:true}]" :submit="requestDifference" :disabled="loading"/>
<p v-if="trip.load&&trip.unload&&!ready" class="notice-error">La operación registrada está incompleta. La API 5.4 no permite agregar detalles a una carga o descarga ya registrada. No puede finalizarse desde esta interfaz.</p>
</section>
<AssignmentPanel v-else-if="tab==='assignments'" :trip="trip" :authorizations="authorizations" :refresh="refresh"/>
<OperationsPanel v-else-if="tab==='operations'" :trip="trip" :refresh="refresh"/>
<OdometerPanel v-else-if="tab==='odometer'" :trip="trip" :refresh="refresh"/>
<AuthorizationPanel v-else-if="tab==='authorizations'" :authorizations="authorizations" :refresh="refresh"/>
<section v-else class="space-y-4">
<label>Consultar historial<select v-model="historyKind" class="field mt-2" :disabled="ctx.busy.value">
<option value="history">Cambios y estados</option>
<option value="assignments">Asignaciones y liberaciones</option>
<option value="reports">Reportes informativos del conductor</option>
<option value="warnings">Advertencias y resoluciones</option>
</select>
</label>
<HistoryList :path="`/trips/${id}/${historyKind}`" title="Historial del viaje" :revision="revision"/>
</section>
</template>
</section>
</template>
