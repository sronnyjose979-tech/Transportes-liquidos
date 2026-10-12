<script setup lang="ts">
import { computed, onMounted, onBeforeUnmount, ref } from 'vue';
import { getRequests, write } from '../api';
import type { Page, TransportRequest } from '../contracts';
import { useTransport } from '../context';
import { date, instant, localDate, number } from '../presentation';
import Pagination from '../../FleetAndDocuments/components/Pagination.vue';
import CommandForm from './CommandForm.vue';
import type { Field } from './CommandForm.vue';
import HistoryList from './HistoryList.vue';
const emit = defineEmits<{
    'view-trips': [id: string];
    'create-trip': [
        id: string
    ];
}>();
const ctx = useTransport();
const result = ref<Page<TransportRequest> | null>(null);
const editing = ref<TransportRequest | null>(null);
const creating = ref(false);
const history = ref('');
const revision = ref(0);
const loading = ref(false);
let controller: AbortController | undefined;
const options = (kind: 'customers' | 'products' | 'routes') => ctx.lookups.value[kind].map(v => ({ value: v.id, label: v.name ?? v.id }));
const fields = computed<Field[]>(() => [...(editing.value ? [] : [{ key: 'customer_id', label: 'Cliente', required: true, options: options('customers') }, { key: 'liquid_product_id', label: 'Producto líquido', required: true, options: options('products') }, { key: 'route_id', label: 'Ruta', required: true, options: options('routes') }]), { key: 'requested_liters', label: 'Litros solicitados', required: true, pattern: '[0-9]+([.][0-9]{1,2})?', value: editing.value?.requested_liters ?? '' }, { key: 'requested_at', label: 'Fecha solicitada · Costa Rica', required: true, type: 'datetime-local', step: '1', value: localDate(editing.value?.requested_at ?? new Date().toISOString()) }, ...(editing.value ? [{ key: 'reason', label: 'Motivo del cambio', type: 'textarea', required: true }] : [])]);
async function load(page = 1): Promise<void> { controller?.abort(); const current = new AbortController(); controller = current; loading.value = true; try {
    result.value = await getRequests(page, current.signal);
}
catch (error) {
    if (!current.signal.aborted)
        await ctx.report(error);
}
finally {
    if (!current.signal.aborted)
        loading.value = false;
} }
async function save(v: Record<string, string>): Promise<void> { const body = { ...v, requested_at: instant(v.requested_at!) }; if (editing.value)
    await write(`/requests/${editing.value.id}`, { requested_liters: v.requested_liters, requested_at: body.requested_at, version: editing.value.version, reason: v.reason }, 'PATCH');
else
    await write('/requests', body); creating.value = false; editing.value = null; revision.value++; await load(result.value?.meta.current_page ?? 1); }
const name = (kind: 'customers' | 'products', id: string) => ctx.lookups.value[kind].find(v => v.id === id)?.name ?? id;
onMounted(() => load());
onBeforeUnmount(() => controller?.abort());
</script>
<template>
<section class="space-y-5">
<div class="flex flex-wrap items-center justify-between gap-3">
<h1 class="text-2xl font-semibold">Solicitudes de transporte</h1>
<button class="btn-primary" :disabled="ctx.busy.value" @click="creating=true;editing=null">Registrar solicitud</button>
</div>
<template v-if="creating||editing">
<CommandForm :key="editing?.id??'create'" :title="editing?'Editar solicitud':'Registrar solicitud'" :fields="fields" :submit="save"/>
<p class="notice-info">El producto histórico no se cambia. Las rutas deben existir previamente; esta API permite seleccionarlas, no crearlas.</p>
<button class="btn-secondary" :disabled="ctx.busy.value" @click="creating=false;editing=null">Cerrar formulario</button>
</template>
<section class="panel">
<p v-if="loading" role="status">Consultando solicitudes…</p>
<template v-else-if="result">
<div class="overflow-x-auto">
<table class="data-table">
<thead>
<tr>
<th>Solicitud / cliente</th>
<th>Producto</th>
<th>Litros</th>
<th>Fecha solicitada</th>
<th>Viajes</th>
<th>Acciones</th>
</tr>
</thead>
<tbody>
<tr v-for="row in result.data" :key="row.id">
<td>
<span class="block font-medium">{{name('customers',row.customer_id)}}</span>
<small>{{row.id}}</small>
</td>
<td>{{name('products',row.liquid_product_id)}}</td>
<td>{{number(row.requested_liters)}}</td>
<td>{{date(row.requested_at)}}</td>
<td>{{row.trips_count??'—'}}</td>
<td>
<div class="flex flex-wrap gap-2">
<button class="btn-text" :disabled="ctx.busy.value" @click="editing=row;creating=false">Editar</button>
<button class="btn-text" :disabled="ctx.busy.value" @click="emit('create-trip',row.id)">Crear viaje</button>
<button class="btn-text" :disabled="ctx.busy.value" @click="emit('view-trips',row.id)">Consultar viajes</button>
<button class="btn-text" :disabled="ctx.busy.value" @click="history=row.id">Historial</button>
</div>
</td>
</tr>
</tbody>
</table>
</div>
<p v-if="!result.data.length" class="py-4 text-slate-500">No hay solicitudes registradas.</p>
<Pagination :meta="result.meta" :busy="loading||ctx.busy.value" @change="load"label="Listado de solicitudes"/>
</template>
<button class="btn-text" :disabled="loading||ctx.busy.value" @click="load(result?.meta.current_page??1)">Actualizar solicitudes</button>
</section>
<HistoryList v-if="history" :path="`/requests/${history}/history`" title="Historial de la solicitud" :revision="revision"/>
</section>
</template>
