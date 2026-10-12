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
const existing = computed(() => kind.value === 'load' ? props.trip.load : props.trip.unload);
const loadingChoices = computed(() => (tanker.value?.compartments ?? []).filter(c => c.is_active && !props.trip.load?.items.some(i => i.compartment_id === c.id)).map(c => ({ id: c.id, label: `${c.code} · capacidad ${number(c.capacity_liters)} L` })));
const unloadingChoices = computed(() => (props.trip.load?.items ?? []).filter(i => !props.trip.unload?.items.some(u => u.load_item_id === i.id)).map(i => ({ id: i.id, label: `${i.compartment_code_snapshot ?? i.compartment_id} · cargado ${number(i.loaded_liters)} L` })));
const choices = computed(() => kind.value === 'load' ? loadingChoices.value : unloadingChoices.value);
watch([kind, () => props.trip.load, () => props.trip.unload], () => { if (kind.value === 'unload' && !unloadingChoices.value.length && loadingChoices.value.length) kind.value = 'load'; selected.value = kind.value === 'unload' ? unloadingChoices.value.map(i => i.id) : []; }, { immediate: true });
watch(() => props.trip.load?.id, () => { if (props.trip.load && !props.trip.unload) kind.value = 'unload'; });
const title = computed(() => existing.value ? `Añadir detalles de ${kind.value === 'load' ? 'carga' : 'descarga'}` : kind.value === 'load' ? 'Registrar carga' : 'Registrar descarga');
const fields = computed<Field[]>(() => [...(existing.value ? [] : [{ key: 'operation_site_id', label: kind.value === 'load' ? 'Sitio real de carga' : 'Sitio real de descarga', required: true, options: ctx.lookups.value.sites.map(s => ({ value: s.id, label: s.name ?? s.id })) }]), { key: 'occurred_at', label: 'Fecha y hora real · Costa Rica', type: 'datetime-local', step: '1', required: true, value: localDate(new Date().toISOString()) }, ...choices.value.filter(c => selected.value.includes(c.id)).map(c => ({ key: c.id, label: `Litros reales · ${c.label}`, required: true, pattern: '[0-9]+([.][0-9]{1,2})?' })), { key: 'reason', label: 'Motivo / información recibida', type: 'textarea', required: true }]);
async function save(v: Record<string, string>): Promise<void> { await write(`/trips/${props.trip.id}/${kind.value}${existing.value ? '/items' : ''}`, { ...(existing.value ? {} : { operation_site_id: v.operation_site_id }), occurred_at: instant(v.occurred_at!), reason: v.reason, items: selected.value.map(id => ({ [kind.value === 'load' ? 'compartment_id' : 'load_item_id']: id, liters: v[id] })) }); selected.value = []; await props.refresh(); }
</script>
<template>
<section class="space-y-5">
<div class="grid gap-4 sm:grid-cols-2">
<article v-for="operation in [trip.load,trip.unload].filter(Boolean)" :key="operation!.id" class="panel">
<h3 class="font-semibold">{{operation!.id===trip.load?.id?'Carga registrada':'Descarga registrada'}}</h3>
<p>{{date(operation!.occurred_at)}} · Responsable {{operation!.recorded_by_id}}</p>
<p>{{operation!.reason}}</p>
<ul class="mt-3 space-y-2">
<li v-for="item in operation!.items" :key="item.id">{{item.compartment_code_snapshot??trip.load?.items.find(i=>i.id===item.load_item_id)?.compartment_code_snapshot??item.id}}: {{number(item.loaded_liters??item.delivered_liters)}} L
<p v-if="item.recorded_by_id!==undefined" class="text-xs text-slate-600">Responsable {{item.recorded_by_id}} · Hecho {{date(item.occurred_at)}} · Registro {{date(item.registered_at)}} · {{item.reason}}</p>
</li>
</ul>
<p class="mt-3 font-semibold">Total: {{number(sum(operation!.items.map(i=>i.loaded_liters??i.delivered_liters)))}} L</p>
</article>
</div>
<template v-if="['pending','in_progress'].includes(trip.execution_status)&&(loadingChoices.length||unloadingChoices.length)">
<div class="panel space-y-4">
<label>Operación<select v-model="kind" class="field mt-2" :disabled="ctx.busy.value">
<option v-if="loadingChoices.length" value="load">{{trip.load?'Añadir detalles de carga':'Registrar carga'}}</option>
<option v-if="unloadingChoices.length" value="unload">{{trip.unload?'Añadir detalles de descarga':'Registrar descarga'}}</option>
</select>
</label>
<p v-if="existing" class="notice-info">Se conserva la operación original y sus detalles. Cada detalle añadido registra responsable, fecha y motivo propios.</p>
<p class="text-sm">Un producto por viaje: {{trip.product_name_snapshot}}. Cero es un valor real; no equivale a un detalle ausente.</p>
<fieldset :disabled="ctx.busy.value">
<legend class="field-label">Compartimentos / detalles a registrar</legend>
<label v-for="choice in choices" :key="choice.id" class="mb-2 block">
<input v-model="selected" type="checkbox" :value="choice.id" :disabled="kind==='unload'"> {{choice.label}}</label>
</fieldset>
<p v-if="!choices.length" class="notice-info">Primero asigna una cisterna con compartimentos disponibles.</p>
</div>
<CommandForm :key="`${kind}-${existing?.id??'new'}`" :title="title" :fields="fields" :submit="save" :disabled="!selected.length"/>
</template>
<p v-else class="notice-info">Los registros existentes son inmutables: no se editan ni eliminan. Solo pueden añadirse detalles omitidos durante la captura; las correcciones de cantidades históricas siguen sin procedimiento aprobado.</p>
</section>
</template>
