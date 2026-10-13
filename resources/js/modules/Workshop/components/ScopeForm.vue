<script setup lang="ts">
import { ref, useId, onMounted } from 'vue';
import type { RecordRow } from '../api';
import SearchSelect from './SearchSelect.vue';
const props = withDefaults(defineProps<{
    diagnoses: RecordRow[];
    scopes: RecordRow[];
    parts?: { value: string; label: string }[];
    suppliers?: { value: string; label: string }[];
    previousRequests?: { value: string; label: string }[];
    busy: boolean;
    cancelDisabled?: boolean;
    errors: Record<string, string[]>;
}>(), { parts: () => [], suppliers: () => [], previousRequests: () => [] });
const emit = defineEmits<{
    submit: [
        values: Record<string, unknown>
    ];
    cancel: [
    ];
}>();
const id = useId(), diagnosis = ref(''), description = ref(''), reason = ref(''), kind = ref('initial'), previous = ref(''), previousRequest = ref('');
const tasks = ref([{ description: '', supplier: '' }]);
const parts = ref<{
    part: string;
    quantity: string;
    task: string;
}[]>([]);
function removeTask(index: number): void { if (parts.value.some(p => p.task === String(index + 1)))
    return; tasks.value.splice(index, 1); for (const p of parts.value)
    if (Number(p.task) > index + 1)
        p.task = String(Number(p.task) - 1); }
function submit(): void { if (props.busy)
    return; emit('submit', { diagnosis_id: diagnosis.value, description: description.value, reason: reason.value, scope_kind: kind.value, ...(kind.value === 'revision' ? { previous_scope_id: previous.value } : {}), ...(previousRequest.value ? { previous_request_id: previousRequest.value } : {}), tasks: tasks.value.map((t, i) => ({ line_number: i + 1, description: t.description, ...(t.supplier ? { supplier_id: t.supplier } : {}) })), parts: parts.value.map((p, i) => ({ line_number: i + 1, spare_part_id: p.part, quantity: p.quantity, ...(p.task ? { task_line_number: Number(p.task) } : {}) })) }); }
onMounted(() => document.getElementById(`${id}-diagnosis`)?.focus());
</script>
<template>
<form aria-label="Solicitar autorización de alcance" @submit.prevent="submit">
<h2>Solicitar autorización de alcance</h2>
<p>El alcance registra las tareas y repuestos concretos de esta reparación. El servidor crea la solicitud de autorización.</p>
<ul v-if="Object.keys(errors).length" class="workshop-field-errors" role="alert">
<li v-for="(messages, field) in errors" :key="field"><span v-for="message in messages" :key="message">{{ message }} </span></li>
</ul>
<fieldset :disabled="busy">
<SearchSelect :id="`${id}-diagnosis`" v-model="diagnosis" label="Diagnóstico" :options="diagnoses.filter(d => d.is_effective !== false).map(d => ({ value: d.id, label: String(d.problem) }))" required :errors="errors.diagnosis_id" />
<label>Tipo de alcance<select aria-label="Tipo de alcance" v-model="kind">
<option value="initial">Inicial</option>
<option value="extension">Ampliación de la misma reparación</option>
<option value="revision">Revisión de alcance anterior</option>
</select>
</label>
<SearchSelect v-if="kind==='revision'" v-model="previous" label="Alcance anterior" :options="scopes.map(s => ({ value: s.id, label: `${s.description} · revisión ${s.revision}` }))" required :errors="errors.previous_scope_id" />
<SearchSelect v-model="previousRequest" label="Solicitud anterior rechazada (opcional)" :options="previousRequests" :errors="errors.previous_request_id" />
<label>Descripción del alcance<textarea v-model="description" :aria-invalid="Boolean(errors.description?.length)" required maxlength="10000">
</textarea>
</label>
<h3>Trabajos propuestos</h3>
<div v-for="(t,i) in tasks" :key="i" class="workshop-card">
<label>Trabajo {{ i+1 }}<textarea v-model="t.description" :aria-invalid="Boolean(errors[`tasks.${i}.description`]?.length)" required maxlength="10000">
</textarea>
</label>
<SearchSelect v-model="t.supplier" :label="`Proveedor externo ${i+1} (opcional)`" :options="suppliers" :errors="errors[`tasks.${i}.supplier_id`]" />
<button type="button" :disabled="tasks.length===1 || parts.some(p=>p.task===String(i+1))" @click="removeTask(i)">Retirar trabajo {{ i+1 }}</button>
</div>
<button type="button" :disabled="tasks.length>=100" @click="tasks.push({description:'',supplier:''})">Añadir trabajo</button>
<h3>Repuestos propuestos</h3>
<p>Selecciona repuestos del catálogo existente. La cantidad conserva precisión decimal; no representa stock disponible.</p>
<div v-for="(p,i) in parts" :key="i" class="workshop-card">
<SearchSelect v-model="p.part" :label="`Repuesto ${i+1}`" :options="props.parts" required :errors="errors[`parts.${i}.spare_part_id`]" />
<label>Cantidad {{ i+1 }}<input v-model="p.quantity" :aria-invalid="Boolean(errors[`parts.${i}.quantity`]?.length)" required inputmode="decimal" pattern="[0-9]+(\.[0-9]{1,6})?">
</label>
<label>Trabajo asociado {{ i+1 }}<select :aria-label="`Trabajo asociado ${i+1}`" v-model="p.task">
<option value="">General de esta reparación</option>
<option v-for="(t,n) in tasks" :key="n" :value="String(n+1)">{{ n+1 }}. {{ t.description }}</option>
</select>
</label>
<button type="button" @click="parts.splice(i,1)">Retirar repuesto {{ i+1 }}</button>
</div>
<button type="button" :disabled="parts.length>=100" @click="parts.push({part:'',quantity:'',task:''})">Añadir repuesto</button>
<label>Motivo / información recibida<textarea v-model="reason" :aria-invalid="Boolean(errors.reason?.length)" required maxlength="5000">
</textarea>
</label>
</fieldset>
<div class="workshop-actions">
<button :disabled="busy">{{ busy?'Procesando…':'Guardar' }}</button>
<button type="button" :disabled="cancelDisabled" @click="emit('cancel')">Cancelar</button>
</div>
</form>
</template>
