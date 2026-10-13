<script setup lang="ts">
import { ref } from 'vue';
import type { RecordRow } from '../api';
import { instant, localInstant } from '../presentation';
import SearchSelect from './SearchSelect.vue';
const props = defineProps<{ causes: RecordRow[]; previousRequests: {value:string;label:string}[]; busy:boolean; cancelDisabled:boolean; errors:Record<string,string[]> }>();
const emit = defineEmits<{ submit:[values:Record<string,unknown>]; cancel:[] }>();
const chosen=ref<string[]>([]), date=ref(localInstant()), reason=ref(''), previous=ref('');
function submit():void {
    if(props.busy)return;
    emit('submit',{resolve_ids:[...chosen.value],exited_at:instant(date.value),reason:reason.value,...(previous.value?{previous_request_id:previous.value}:{})});
}
</script>
<template>
<form aria-label="Solicitar salida" class="workshop-command-form" @submit.prevent="submit">
<h2>Solicitar salida</h2>
<p>Selecciona explícitamente los impedimentos que esta autorización resolverá. El servidor revisará las órdenes y el contenido exacto antes de registrar la salida.</p>
<fieldset :disabled="busy">
<legend>Impedimentos abiertos del vehículo</legend>
<label v-for="cause in causes" :key="cause.id"><input v-model="chosen" type="checkbox" :value="cause.id"> {{ cause.description }} <small>{{ cause.work_order_id?'· Vinculado a reparación':'· Ingreso / otra causa' }}</small></label>
<p v-if="!causes.length">No hay impedimentos abiertos accesibles.</p>
<p v-for="message in errors.resolve_ids" :key="message" class="workshop-field-errors">{{ message }}</p>
<label>Fecha de salida (Costa Rica)<input v-model="date" type="datetime-local" step="1" required></label>
<SearchSelect v-model="previous" label="Solicitud anterior rechazada (opcional)" :options="previousRequests" />
<label>Motivo / información recibida<textarea v-model="reason" required rows="4"></textarea></label>
<p v-for="(items,key) in errors" :key="key" class="workshop-field-errors">{{ items.join(' ') }}</p>
</fieldset>
<p>La salida física no libera asignaciones de Transporte. Si permanecen otros impedimentos, el vehículo seguirá bloqueado.</p>
<div class="workshop-form-actions"><button type="button" class="workshop-button-secondary" :disabled="cancelDisabled" @click="emit('cancel')">Cancelar</button><button :disabled="busy" class="workshop-button-primary">{{ busy?'Guardando…':'Guardar' }}</button></div>
</form>
</template>
