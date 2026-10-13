<script setup lang="ts">
import { ref, useId, onMounted } from 'vue';
import type { RecordRow } from '../api';
const props = defineProps<{
    diagnoses: RecordRow[];
    scopes: RecordRow[];
    busy: boolean;
    errors: Record<string, string[]>;
}>();
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
<fieldset :disabled="busy">
<label :for="`${id}-diagnosis`">Diagnóstico<select :id="`${id}-diagnosis`" v-model="diagnosis" required>
<option value="">Seleccionar diagnóstico</option>
<option v-for="d in diagnoses.filter(d=>d.is_effective!==false)" :key="d.id" :value="d.id">{{ d.problem }}</option>
</select>
</label>
<label>Tipo de alcance<select v-model="kind">
<option value="initial">Inicial</option>
<option value="extension">Ampliación de la misma reparación</option>
<option value="revision">Revisión de alcance anterior</option>
</select>
</label>
<label v-if="kind==='revision'">Alcance anterior<select v-model="previous" required>
<option value="">Seleccionar alcance</option>
<option v-for="s in scopes" :key="s.id" :value="s.id">{{ s.description }} · revisión {{ s.revision }}</option>
</select>
</label>
<label>Solicitud anterior rechazada (UUID, opcional)<input v-model="previousRequest">
</label>
<label>Descripción del alcance<textarea v-model="description" required maxlength="10000">
</textarea>
</label>
<h3>Trabajos propuestos</h3>
<div v-for="(t,i) in tasks" :key="i" class="workshop-card">
<label>Trabajo {{ i+1 }}<textarea v-model="t.description" required maxlength="10000">
</textarea>
</label>
<label>Proveedor externo (UUID, opcional)<input v-model="t.supplier">
</label>
<button type="button" :disabled="tasks.length===1 || parts.some(p=>p.task===String(i+1))" @click="removeTask(i)">Retirar trabajo {{ i+1 }}</button>
</div>
<button type="button" :disabled="tasks.length>=100" @click="tasks.push({description:'',supplier:''})">Añadir trabajo</button>
<h3>Repuestos propuestos</h3>
<p>Usa identificadores del catálogo existente. La cantidad conserva precisión decimal; no representa stock disponible.</p>
<div v-for="(p,i) in parts" :key="i" class="workshop-card">
<label>Repuesto {{ i+1 }} (UUID)<input v-model="p.part" required pattern="[0-9a-fA-F-]{36}">
</label>
<label>Cantidad {{ i+1 }}<input v-model="p.quantity" required inputmode="decimal" pattern="[0-9]+(\.[0-9]{1,6})?">
</label>
<label>Trabajo asociado {{ i+1 }}<select v-model="p.task">
<option value="">General de esta reparación</option>
<option v-for="(t,n) in tasks" :key="n" :value="String(n+1)">{{ n+1 }}. {{ t.description }}</option>
</select>
</label>
<button type="button" @click="parts.splice(i,1)">Retirar repuesto {{ i+1 }}</button>
</div>
<button type="button" :disabled="parts.length>=100" @click="parts.push({part:'',quantity:'',task:''})">Añadir repuesto</button>
<label>Motivo / información recibida<textarea v-model="reason" required maxlength="5000">
</textarea>
</label>
</fieldset>
<div class="workshop-actions">
<button :disabled="busy">{{ busy?'Procesando…':'Guardar' }}</button>
<button type="button" :disabled="busy" @click="emit('cancel')">Cancelar</button>
</div>
</form>
</template>
