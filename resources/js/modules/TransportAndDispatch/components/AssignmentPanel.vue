<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { availability, write } from '../api';
import type { Authorization, Availability, Json, Trip } from '../contracts';
import { approvalMatches, labels } from '../presentation';
import { useTransport } from '../context';
import CommandForm from './CommandForm.vue';
const props = defineProps<{
    trip: Trip;
    authorizations: Authorization[];
    refresh: () => Promise<void>;
}>();
const ctx = useTransport();
const kind = ref('driver');
const resource = ref('');
const action = ref('assign');
const info = ref<Availability | null>(null);
const checking = ref(false);
const selected = ref<string[]>([]);
const requestType = ref('assignment_conflict');
const previous = computed(() => kind.value === 'driver' ? props.trip.current_driver_assignments?.[0] : props.trip.current_asset_assignments?.find(a => a.asset_type === kind.value));
const options = computed(() => (kind.value === 'driver' ? ctx.lookups.value.drivers : ctx.lookups.value.assets.filter(v => v.type === kind.value)).filter(v => action.value !== 'substitute' || v.id !== (previous.value?.employee_id ?? previous.value?.fleet_asset_id)));
const payload = computed<Json>(() => ({ action: action.value, resource_kind: kind.value, resource_id: resource.value, previous_assignment_id: previous.value?.id ?? null, conflicts: (info.value?.conflicts ?? []).map(v => v.id).sort() }));
const approvals = computed(() => props.authorizations.filter(a => approvalMatches(a, props.trip, payload.value, 'assignment_conflict') || approvalMatches(a, props.trip, payload.value, kind.value === 'driver' ? 'driver_substitution' : 'tractor_substitution')));
const reasonFields = [{ key: 'reason', label: 'Motivo de la operación', type: 'textarea', required: true }];
watch([kind, resource, action, () => props.trip], () => { info.value = null; selected.value = []; });
watch([kind, () => previous.value?.id], () => { action.value = previous.value && kind.value !== 'tanker' ? 'substitute' : previous.value ? 'release' : 'assign'; resource.value = ''; }, { immediate: true });
async function inspect(): Promise<void> { checking.value = true; const k = kind.value, r = resource.value; try {
    const result = await availability(k, r);
    if (kind.value === k && resource.value === r)
        info.value = result;
}
catch (error) {
    await ctx.report(error);
}
finally {
    checking.value = false;
} }
async function save(v: Record<string, string>): Promise<void> { await write(`/trips/${props.trip.id}/assignments/${action.value}`, { resource_kind: kind.value, reason: v.reason, ...(action.value === 'release' ? {} : { resource_id: resource.value, decisions: selected.value.filter(id => approvals.value.some(a => a.decision?.id === id)) }) }); await props.refresh(); info.value = null; selected.value = []; }
async function requestApproval(v: Record<string, string>): Promise<void> { await write(`/trips/${props.trip.id}/authorizations`, { type: requestType.value, reason: v.reason, content: { action: action.value, resource_kind: kind.value, resource_id: resource.value } }); await props.refresh(); }
</script>
<template>
<section class="space-y-5">
<p class="notice-info">Los recursos permanecen asignados después de finalizar, cancelar o cerrar. La disponibilidad consultada se revalida al confirmar. Sustituir cisterna sigue bloqueado por D05.</p>
<div class="panel grid gap-4 sm:grid-cols-3">
<label>Recurso<select aria-label="Recurso" v-model="kind" class="field mt-2" :disabled="ctx.busy.value||checking">
<option v-for="k in ['driver','tractor_unit','tanker']" :key="k" :value="k">{{labels[k]}}</option>
</select>
</label>
<label>Operación<select aria-label="Operación" v-model="action" class="field mt-2" :disabled="ctx.busy.value||checking">
<option v-if="!previous" value="assign">Asignar</option>
<option v-if="previous&&kind!=='tanker'" value="substitute">Sustituir</option>
<option v-if="previous&&trip.execution_status!=='in_progress'" value="release">Liberar manualmente</option>
</select>
</label>
<label v-if="action!=='release'">Seleccionar recurso<select aria-label="Seleccionar recurso" v-model="resource" class="field mt-2" :disabled="ctx.busy.value||checking">
<option value="">Seleccionar…</option>
<option v-for="option in options" :key="option.id" :value="option.id">{{option.name??option.code??option.id}}</option>
</select>
</label>
<p v-if="previous" class="text-sm sm:col-span-3">Asignación vigente: {{previous.employee_id??previous.fleet_asset_id}} · Registro {{previous.id}}</p>
<button v-if="action!=='release'" class="btn-secondary sm:col-span-3" :disabled="!resource||ctx.busy.value||checking" @click="inspect">{{checking?'Consultando…':'Consultar disponibilidad'}}</button>
</div>
<div v-if="info" :class="info.available?'notice-success':'notice-error'" role="status">
<p>{{!info.eligible?'Recurso no elegible':info.available?'Recurso disponible al consultar':'Recurso ocupado: requiere revisión y autorización administrativa.'}}</p>
<ul>
<li v-for="conflict in info.conflicts" :key="conflict.id">Conflicto con asignación {{conflict.id}}</li>
</ul>
</div>
<CommandForm :title="action==='release'?'Liberar recurso manualmente':action==='substitute'?'Sustituir recurso':'Asignar recurso'" :fields="reasonFields" :submit="save" :disabled="action==='release'?(!previous||trip.execution_status==='in_progress'):!info?.eligible">
<div v-if="approvals.length" class="space-y-2">
<p class="font-medium">Autorizaciones aplicables a estos datos y versión</p>
<label v-for="a in approvals" :key="a.id" class="block">
<input v-model="selected" type="checkbox" :value="a.decision!.id" :disabled="ctx.busy.value"> {{labels[a.type]}} · {{a.reason}}</label>
</div>
<p v-if="action==='release'&&trip.execution_status==='in_progress'" class="text-sm">Un viaje activo debe conservar sus recursos vigentes. Utiliza una sustitución autorizada.</p>
</CommandForm>
<template v-if="resource&&info?.eligible&&action!=='release'&&(!info.available||(action==='substitute'&&trip.execution_status==='in_progress'))">
<div class="panel">
<label>Tipo de autorización<select aria-label="Tipo de autorización" v-model="requestType" class="field mt-2" :disabled="ctx.busy.value">
<option value="assignment_conflict">Conflicto de asignación</option>
<option v-if="action==='substitute'" :value="kind==='driver'?'driver_substitution':'tractor_substitution'">Sustitución durante el viaje</option>
</select>
</label>
</div>
<CommandForm title="Solicitar autorización de recursos" :fields="[{key:'reason',label:'Justificación de la solicitud',type:'textarea',required:true}]" :submit="requestApproval">
<p class="text-sm">El servidor conservará los ocupantes, recurso, operación y versión exactos. El administrador decide en Autorizaciones.</p>
</CommandForm>
</template>
</section>
</template>
