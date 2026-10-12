<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { write } from '../api';
import type { Trip } from '../contracts';
import { useTransport } from '../context';
import { date, instant, localDate, number, sum } from '../presentation';
import CommandForm from './CommandForm.vue';
import type { Field } from './CommandForm.vue';
const props = defineProps<{
    trip: Trip;
    refresh: () => Promise<void>;
}>();
const ctx = useTransport();
const kind = ref<'load' | 'unload'>(props.trip.load ? 'unload' : 'load');
const selected = ref<string[]>([]);
const tanker = computed(() => ctx.lookups.value.assets.find(a => a.id === props.trip.current_asset_assignments?.find(v => v.asset_type === 'tanker')?.fleet_asset_id));
const choices = computed(() => kind.value === 'load' ? (tanker.value?.compartments ?? []).filter(c => c.is_active).map(c => ({ id: c.id, label: `${c.code} · capacidad ${number(c.capacity_liters)} L` })) : (props.trip.load?.items ?? []).map(i => ({ id: i.id, label: `${i.compartment_code_snapshot ?? i.compartment_id} · cargado ${number(i.loaded_liters)} L` })));
watch([kind, () => props.trip.load?.id], () => { if (props.trip.load && !props.trip.unload)
    kind.value = 'unload'; selected.value = kind.value === 'unload' ? (props.trip.load?.items ?? []).map(i => i.id) : []; }, { immediate: true });
const fields = computed<Field[]>(() => [{ key: 'operation_site_id', label: kind.value === 'load' ? 'Sitio real de carga' : 'Sitio real de descarga', required: true, options: ctx.lookups.value.sites.map(s => ({ value: s.id, label: s.name ?? s.id })) }, { key: 'occurred_at', label: 'Fecha y hora real · Costa Rica', type: 'datetime-local', step: '1', required: true, value: localDate(new Date().toISOString()) }, ...choices.value.filter(c => selected.value.includes(c.id)).map(c => ({ key: c.id, label: `Litros reales · ${c.label}`, required: true, pattern: '[0-9]+([.][0-9]{1,2})?' })), { key: 'reason', label: 'Motivo / información recibida', type: 'textarea', required: true }]);
async function save(v: Record<string, string>): Promise<void> { await write(`/trips/${props.trip.id}/${kind.value}`, { operation_site_id: v.operation_site_id, occurred_at: instant(v.occurred_at!), reason: v.reason, items: selected.value.map(id => ({ [kind.value === 'load' ? 'compartment_id' : 'load_item_id']: id, liters: v[id] })) }); selected.value = []; await props.refresh(); }
</script>
<template>
<section class="space-y-5">
<div class="grid gap-4 sm:grid-cols-2">
<article v-for="operation in [trip.load,trip.unload].filter(Boolean)" :key="operation!.id" class="panel">
<h3 class="font-semibold">{{operation!.id===trip.load?.id?'Carga registrada':'Descarga registrada'}}</h3>
<p>{{date(operation!.occurred_at)}} · Responsable {{operation!.recorded_by_id}}</p>
<p>{{operation!.reason}}</p>
<ul class="mt-3 space-y-2">
<li v-for="item in operation!.items" :key="item.id">{{item.compartment_code_snapshot??trip.load?.items.find(i=>i.id===item.load_item_id)?.compartment_code_snapshot??item.id}}: {{number(item.loaded_liters??item.delivered_liters)}} L</li>
</ul>
<p class="mt-3 font-semibold">Total: {{number(sum(operation!.items.map(i=>i.loaded_liters??i.delivered_liters)))}} L</p>
</article>
</div>
<template v-if="!trip.load||!trip.unload">
<div class="panel space-y-4">
<label>Operación<select v-model="kind" class="field mt-2" :disabled="ctx.busy.value">
<option v-if="!trip.load" value="load">Registrar carga</option>
<option v-if="trip.load&&!trip.unload" value="unload">Registrar descarga</option>
</select>
</label>
<p class="text-sm">Un producto por viaje: {{trip.product_name_snapshot}}. Cero es un valor real; no equivale a un detalle ausente.</p>
<fieldset :disabled="ctx.busy.value">
<legend class="field-label">Compartimentos / detalles a registrar</legend>
<label v-for="choice in choices" :key="choice.id" class="mb-2 block">
<input v-model="selected" type="checkbox" :value="choice.id" :disabled="kind==='unload'"> {{choice.label}}</label>
</fieldset>
<p v-if="!choices.length" class="notice-info">Primero asigna una cisterna con compartimentos disponibles.</p>
</div>
<CommandForm :title="kind==='load'?'Registrar carga':'Registrar descarga'" :fields="fields" :submit="save" :disabled="!selected.length||(kind==='load'?!!trip.load:!!trip.unload)"/>
</template>
<p v-else class="notice-info">Carga y descarga son registros históricos inmutables. No se editan ni eliminan. Sus correcciones no tienen endpoint en la API 5.4.</p>
</section>
</template>
