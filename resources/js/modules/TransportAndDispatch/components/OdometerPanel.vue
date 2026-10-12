<script setup lang="ts">
import { computed, onMounted, ref, shallowRef, watch } from 'vue';
import { all, distance, records, write } from '../api';
import { entry } from '../contracts';
import type { Entry, Page, Trip } from '../contracts';
import { useTransport } from '../context';
import { date, instant, localDate, number } from '../presentation';
import CommandForm from './CommandForm.vue';
import type { Field } from './CommandForm.vue';
import Pagination from '../../FleetAndDocuments/components/Pagination.vue';
const props = defineProps<{
    trip: Trip;
    refresh: () => Promise<void>;
}>();
const ctx = useTransport();
const result = shallowRef<Page<Entry> | null>(null);
const heads=ref<{value:string;label:string}[]>([]);
const correction = shallowRef<Entry | null>(null);
const measured = ref<string | null>(null);
const measuring = ref(false);
const loading = ref(false);
const assignment = ref(props.trip.current_asset_assignments?.find(v => v.asset_type === 'tractor_unit')?.id ?? '');
const fields = computed<Field[]>(() => [...(correction.value ? [] : [{ key: 'tractor_assignment_id', label: 'Asignación de cabezal', required: true, options: heads.value }, { key: 'reading_type', label: 'Tipo de lectura', required: true, options: [{ value: 'initial', label: 'Inicial' }, { value: 'final', label: 'Final' }] }]), { key: 'reading_value', label: 'Lectura de kilometraje', required: true, pattern: '[0-9]+([.][0-9]+)?', value: correction.value ? String(correction.value.reading_value) : '' }, { key: 'occurred_at', label: 'Fecha y hora · Costa Rica', type: 'datetime-local', step: '1', required: true, value: localDate(new Date().toISOString()) }, { key: 'reason', label: 'Motivo / información recibida', type: 'textarea', required: true }]);
async function load(page = 1): Promise<void> { loading.value = true; try {
    result.value = await records(`/trips/${props.trip.id}/odometer`, page);
    const operations=await all(`/trips/${props.trip.id}/assignments`,entry);
    const found=new Map<string,string>();
    for(const operation of operations){const assignment=operation.assetAssignment;if(assignment&&typeof assignment==='object'&&!Array.isArray(assignment)&&assignment.asset_type==='tractor_unit'&&typeof assignment.id==='string')found.set(assignment.id,`Cabezal ${ctx.lookups.value.assets.find(a=>a.id===assignment.fleet_asset_id)?.code??assignment.fleet_asset_id} · ${assignment.id}`);}
    for(const current of props.trip.current_asset_assignments??[])if(current.asset_type==='tractor_unit')found.set(current.id,`Cabezal ${ctx.lookups.value.assets.find(a=>a.id===current.fleet_asset_id)?.code??current.fleet_asset_id} · ${current.id}`);
    heads.value=Array.from(found,([value,label])=>({value,label}));
}
catch (error) {
    await ctx.report(error);
}
finally {
    loading.value = false;
} }
async function save(v: Record<string, string>): Promise<void> { await write(`/trips/${props.trip.id}/odometer${correction.value ? `/${correction.value.id}/corrections` : ''}`, { ...v, occurred_at: instant(v.occurred_at!) }); correction.value = null; measured.value = null; await props.refresh(); await load(); }
async function measure(): Promise<void> { measuring.value = true; try {
    measured.value = await distance(props.trip.id, assignment.value);
}
catch (error) {
    await ctx.report(error);
}
finally {
    measuring.value = false;
} }
const historical = computed(() => Array.from(new Set([...(result.value?.data ?? []).map(r => String(r.tractor_assignment_id)), ...heads.value.map(a=>a.value)])));
onMounted(() => load());
watch(() => props.trip.id, () => load());
</script>
<template>
<section class="space-y-5">
<CommandForm :key="correction?.id??'create'" :title="correction?'Corregir kilometraje':'Registrar kilometraje'" :fields="fields" :submit="save">
<p class="text-sm">Las correcciones crean una revisión y conservan la lectura anterior, incluso después del cierre.</p>
</CommandForm>
<button v-if="correction" class="btn-secondary" :disabled="ctx.busy.value" @click="correction=null">Cancelar corrección</button>
<section class="panel">
<h3 class="font-semibold">Lecturas e historial</h3>
<p v-if="loading" role="status">Consultando lecturas…</p>
<div v-if="result" class="overflow-x-auto">
<table class="data-table">
<thead>
<tr>
<th>Lectura</th>
<th>Kilometraje</th>
<th>Fecha</th>
<th>Responsable / revisión</th>
<th>Acción</th>
</tr>
</thead>
<tbody>
<tr v-for="row in result.data" :key="row.id">
<td>{{row.reading_type==='initial'?'Inicial':'Final'}}</td>
<td>{{number(String(row.reading_value))}}</td>
<td>{{date(String(row.occurred_at))}}</td>
<td>{{row.recorded_by_id}} · Revisión {{row.revision}}</td>
<td>
<button class="btn-text" :disabled="ctx.busy.value" @click="correction=row">Corregir</button>
</td>
</tr>
</tbody>
</table>
<Pagination :meta="result.meta" :busy="ctx.busy.value||loading" @change="load"label="Lecturas de kilometraje"/>
</div>
</section>
<section class="panel space-y-3">
<label>Tramo de cabezal<select aria-label="Tramo de cabezal" v-model="assignment" class="field mt-2" :disabled="ctx.busy.value||measuring">
<option value="">Seleccionar…</option>
<option v-for="id in historical" :key="id" :value="id">{{id}}</option>
</select>
</label>
<button class="btn-secondary" :disabled="!assignment||measuring||ctx.busy.value" @click="measure">Consultar distancia</button>
<p>{{measured===null?'Sin distancia confirmada; consulta el tramo. El servidor devuelve null si faltan datos o existe reinicio.':`Distancia calculada: ${number(measured)} km`}}</p>
</section>
</section>
</template>
