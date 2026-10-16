<script setup lang="ts">
import { computed, onMounted, onBeforeUnmount, ref, watch } from 'vue';
import { getTrips, getRequests, write } from '../api';
import type { Page, Trip, TransportRequest } from '../contracts';
import { useTransport } from '../context';
import { date, labels } from '../presentation';
import CommandForm from './CommandForm.vue';
import Pagination from '../../FleetAndDocuments/components/Pagination.vue';
const props = defineProps<{
    initialRequest: string; filterRequest: string;
}>();
const emit = defineEmits<{
    'clear-filter': [];
    select: [
        id: string
    ];
}>();
const ctx = useTransport();
const result = ref<Page<Trip> | null>(null);
const requests = ref<Page<TransportRequest> | null>(null);
const loading = ref(false);
const state = ref('');
const closed = ref('');
const creating = ref(!!props.initialRequest);
let controller: AbortController | undefined;
const fields = computed(() => [{ key: 'transport_request_id', label: 'Solicitud de transporte', required: true, value: props.initialRequest, options: [...(props.initialRequest && !requests.value?.data.some(r => r.id === props.initialRequest) ? [{ value: props.initialRequest, label: `Solicitud ${props.initialRequest}` }] : []), ...(requests.value?.data ?? []).map(v => ({ value: v.id, label: `${v.id} · ${v.requested_liters} L` }))] }]);
async function load(page = 1): Promise<void> { controller?.abort(); const current = new AbortController(); controller = current; loading.value = true; try {
    result.value = await getTrips({ page, ...(props.filterRequest ? {transport_request_id: props.filterRequest} : {}), ...(state.value ? { execution_status: state.value } : {}), ...(closed.value ? { administrative_status: closed.value } : {}) }, current.signal);
}
catch (error) {
    if (!current.signal.aborted)
        await ctx.report(error);
}
finally {
    if (!current.signal.aborted)
        loading.value = false;
} }
async function loadRequests(page = 1): Promise<void> { try {
    requests.value = await getRequests(page);
}
catch (error) {
    await ctx.report(error);
} }
async function create(v: Record<string, string>): Promise<void> { const trip = await write('/trips', v); emit('select', trip.id); }
onMounted(() => { void load(); void loadRequests(); });
watch([state, closed,()=>props.filterRequest], () => load());
onBeforeUnmount(() => controller?.abort());
</script>
<template>
<section class="space-y-5">
<p v-if="filterRequest" class="notice-info">Viajes asociados a solicitud {{filterRequest}}. <button class="btn-text" :disabled="ctx.busy.value" @click="emit('clear-filter')">Quitar filtro de solicitud</button></p>
<div class="flex flex-wrap justify-between gap-3">
<h1 class="text-2xl font-semibold">Viajes</h1>
<button class="btn-primary" :disabled="ctx.busy.value" @click="creating=!creating">Crear viaje</button>
</div>
<template v-if="creating">
<CommandForm creation="trip" title="Crear viaje asociado" :fields="fields" :submit="create">
<p class="text-sm text-slate-500">El viaje conserva el producto de la solicitud. Después podrás programarlo y asignar recursos.</p>
</CommandForm>
<Pagination v-if="requests" :meta="requests.meta" :busy="ctx.busy.value" @change="loadRequests"label="Listado de viajes"/>
</template>
<div class="panel flex flex-wrap gap-4">
<label class="min-w-48">Ejecución<select v-model="state" class="field mt-2" :disabled="ctx.busy.value">
<option value="">Todos los estados</option>
<option v-for="s in ['pending','in_progress','completed','cancelled']" :key="s" :value="s">{{labels[s]}}</option>
</select>
</label>
<label class="min-w-48">Cierre administrativo<select v-model="closed" class="field mt-2" :disabled="ctx.busy.value">
<option value="">Todos</option>
<option value="open">Abierto</option>
<option value="closed">Cerrado</option>
</select>
</label>
</div>
<section class="panel">
<p v-if="loading" role="status">Consultando viajes…</p>
<template v-else-if="result">
<div class="overflow-x-auto">
<table class="data-table">
<thead>
<tr>
<th>Viaje</th>
<th>Producto histórico</th>
<th>Ejecución</th>
<th>Cierre</th>
<th>Programación</th>
<th>Detalle</th>
</tr>
</thead>
<tbody>
<tr v-for="trip in result.data" :key="trip.id">
<td>{{trip.id}}</td>
<td>{{trip.product_name_snapshot}}</td>
<td>{{labels[trip.execution_status]}}</td>
<td>{{labels[trip.administrative_status]}}</td>
<td>{{date(trip.scheduled_start_at)}}</td>
<td>
<button class="btn-text" :disabled="ctx.busy.value" @click="emit('select',trip.id)">Abrir viaje</button>
</td>
</tr>
</tbody>
</table>
</div>
<p v-if="!result.data.length" class="py-4">Sin viajes para estos filtros.</p>
<Pagination :meta="result.meta" :busy="loading||ctx.busy.value" @change="load"label="Listado de viajes"/>
</template>
<button class="btn-text" :disabled="ctx.busy.value||loading" @click="load(result?.meta.current_page??1)">Actualizar viajes</button>
</section>
</section>
</template>
