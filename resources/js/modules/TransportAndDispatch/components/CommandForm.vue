<script setup lang="ts">
import { reactive, ref, watch, useId } from 'vue';
import { beginCreation, creationIntent, finishCreation, reviseCreation } from '../creationIntent';
import { ApiError } from '../api';
import FieldError from '../../FleetAndDocuments/components/FieldError.vue';
import { useTransport } from '../context';
export interface Field {
    key: string;
    label: string;
    type?: string;
    required?: boolean;
    options?: {
        value: string;
        label: string;
    }[];
    value?: string;
    min?: string;
    step?: string;
    pattern?: string;
}
const props = defineProps<{
    creation?: 'request' | 'trip' | undefined;
    title: string;
    fields: Field[];
    submitLabel?: string;
    submit: (values: Record<string, string>) => Promise<void>;
    disabled?: boolean;
}>();
const emit = defineEmits<{
    done: [
    ];
}>();
const formId = useId();
const ctx = useTransport();
const actor = ctx.actor.value;
const pending = ref(props.creation ? creationIntent(actor, props.creation) : undefined);
const values = reactive<Record<string, string>>({...pending.value?.values});
watch(() => props.fields, (fields, previous) => { for (const key of Object.keys(values))
    if (!fields.some(f => f.key === key))
        delete values[key]; for (const field of fields) {
    if (!pending.value?.values && (!(field.key in values) || field.value !== previous?.find(f => f.key === field.key)?.value))
        values[field.key] = field.value ?? '';
} }, { immediate: true });
async function send(): Promise<void> {
    if (props.disabled || ctx.busy.value || ctx.uncertain.value) return;
    const result = await ctx.mutate(props.title, async () => {
        if (props.creation) pending.value = beginCreation(actor, props.creation, values);
        try {
            await props.submit(pending.value ? {...(pending.value.values ?? values), request_key: pending.value.key} : {...values});
            if (props.creation) {finishCreation(actor, props.creation); pending.value = undefined;}
        } catch (error) {
            // Permit validation corrections without changing the key: an earlier attempt may already exist.
            if (props.creation && error instanceof ApiError && error.status === 422) {
                pending.value = reviseCreation(actor, props.creation);
            }
            throw error;
        }
    });
    if (result) emit('done');
}
function newIntention(): void {
    if (!props.creation || ctx.busy.value || !window.confirm('La creación anterior puede estar confirmada. Consulta los registros antes de iniciar una creación distinta. ¿Continuar con una intención nueva?')) return;
    finishCreation(actor, props.creation); pending.value = undefined;
}
</script>
<template>
<form :aria-label="title" class="panel space-y-4" :aria-busy="ctx.busy.value" @submit.prevent="send">
<h3 class="text-lg font-semibold">{{title}}</h3>
<slot/>
<p v-if="pending" class="notice-info">{{pending.values?'Creación pendiente de confirmar. Tras consultar el estado, Guardar reenvía la misma clave y datos; no crea un duplicado.':'Se conservó la clave tras recargar, sin guardar datos del formulario. Consulta los registros; si reintentas, introduce los datos originales. Datos distintos producirán conflicto.'}}</p>
<button v-if="pending" type="button" class="btn-secondary" :disabled="ctx.busy.value||ctx.uncertain.value" @click="newIntention">Nueva creación distinta</button>
<fieldset :disabled="ctx.busy.value||ctx.uncertain.value||disabled" class="grid gap-4 sm:grid-cols-2">
<div v-for="field in fields" :key="field.key">
<label class="field-label" :for="`${formId}-${field.key}`">{{field.label}}</label>
<select v-if="field.options" :id="`${formId}-${field.key}`" v-model="values[field.key]" class="field" :aria-invalid="!!ctx.errors.value[field.key]" :aria-describedby="`${formId}-${field.key}-error`" :disabled="!!pending?.values" :required="field.required">
<option value="">Seleccionar…</option>
<option v-for="option in field.options" :key="option.value" :value="option.value">{{option.label}}</option>
</select>
<textarea v-else-if="field.type==='textarea'" :id="`${formId}-${field.key}`" v-model="values[field.key]" class="field" :aria-invalid="!!ctx.errors.value[field.key]" :aria-describedby="`${formId}-${field.key}-error`" :disabled="!!pending?.values" :required="field.required" maxlength="5000"/>
<input v-else :id="`${formId}-${field.key}`" v-model="values[field.key]" class="field" :type="field.type??'text'" :disabled="!!pending?.values" :required="field.required" :min="field.min" :step="field.step" :pattern="field.pattern" :maxlength="field.type==='text'||!field.type?100:undefined" :aria-invalid="!!ctx.errors.value[field.key]" :aria-describedby="`${formId}-${field.key}-error`">
<p v-if="field.pattern" class="mt-1 text-xs text-slate-500">Usa punto como separador decimal.</p>
<FieldError :id="`${formId}-${field.key}-error`" :errors="ctx.errors.value[field.key]"/>
</div>
<div class="sm:col-span-2">
<button class="btn-primary" type="submit" :disabled="ctx.busy.value||ctx.uncertain.value||disabled">{{ctx.busy.value?'Confirmando…':submitLabel??'Guardar'}}</button>
</div>
</fieldset>
</form>
</template>
