<script setup lang="ts">
import { reactive, useId, watch } from 'vue';

export interface Field {
    key: string;
    label: string;
    type?: string;
    options?: { value: string; label: string }[];
    required?: boolean;
    value?: string;
}

const props = defineProps<{
    title: string;
    fields: Field[];
    busy: boolean;
    errors: Record<string, string[]>;
}>();
const emit = defineEmits<{
    submit: [values: Record<string, string>];
    cancel: [];
}>();
const formId = useId();
const values = reactive<Record<string, string>>({});

watch(() => props.fields, (fields, previous) => {
    for (const key of Object.keys(values)) {
        if (!fields.some(field => field.key === key)) delete values[key];
    }
    for (const field of fields) {
        if (!(field.key in values) || field.value !== previous?.find(item => item.key === field.key)?.value) {
            values[field.key] = field.value ?? '';
        }
    }
}, { immediate: true });

function send(): void {
    if (props.busy) return;
    // Number controls still produce strings: exact decimal input stays intact.
    emit('submit', Object.fromEntries(Object.entries(values).map(([key, value]) => [key, String(value)])));
}

function updateInput(key: string, event: Event): void {
    const input = event.target;
    if (input instanceof HTMLInputElement) values[key] = input.value;
}
</script>

<template>
    <form class="workshop-command-form" :aria-label="title" :aria-busy="busy" @submit.prevent="send">
        <h2>{{ title }}</h2>
        <fieldset :disabled="busy" class="workshop-form-grid">
            <div v-for="field in fields" :key="field.key" class="workshop-form-field" :class="{ 'workshop-field-wide': field.type === 'textarea' }">
                <label :for="`${formId}-${field.key}`">{{ field.label }}<span v-if="field.required" aria-hidden="true"> *</span></label>
                <select v-if="field.options" :id="`${formId}-${field.key}`" v-model="values[field.key]" :required="field.required" :aria-invalid="Boolean(errors[field.key]?.length)" :aria-describedby="errors[field.key]?.length ? `${formId}-${field.key}-error` : undefined">
                    <option value="">Seleccionar…</option>
                    <option v-for="option in field.options" :key="option.value" :value="option.value">{{ option.label }}</option>
                </select>
                <textarea v-else-if="field.type === 'textarea'" :id="`${formId}-${field.key}`" v-model="values[field.key]" rows="4" :required="field.required" :aria-invalid="Boolean(errors[field.key]?.length)" :aria-describedby="errors[field.key]?.length ? `${formId}-${field.key}-error` : undefined" />
                <input v-else :id="`${formId}-${field.key}`" :value="values[field.key]" :type="field.type ?? 'text'" :step="field.type === 'number' ? 'any' : undefined" :required="field.required" :aria-invalid="Boolean(errors[field.key]?.length)" :aria-describedby="errors[field.key]?.length ? `${formId}-${field.key}-error` : undefined" @input="updateInput(field.key, $event)">
                <ul v-if="errors[field.key]?.length" :id="`${formId}-${field.key}-error`" class="workshop-field-errors">
                    <li v-for="message in errors[field.key]" :key="message">{{ message }}</li>
                </ul>
            </div>
        </fieldset>
        <div class="workshop-form-actions">
            <button type="button" class="workshop-button-secondary" :disabled="busy" @click="emit('cancel')">Cancelar</button>
            <button type="submit" class="workshop-button-primary" :disabled="busy">{{ busy ? 'Guardando…' : 'Guardar' }}</button>
        </div>
    </form>
</template>
