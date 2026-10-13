<script setup lang="ts">
import { computed, ref, useId } from 'vue';
const props = withDefaults(defineProps<{
    modelValue: string;
    options: { value: string; label: string }[];
    label: string;
    required?: boolean | undefined;
    disabled?: boolean | undefined;
    errors?: string[] | undefined;
    id?: string;
}>(), { required: false, disabled: false, errors: () => [] });
const emit = defineEmits<{ 'update:modelValue': [value: string] }>();
const generatedId = useId();
const controlId = computed(() => props.id ?? generatedId);
const query = ref('');
const visible = computed(() => {
    const term = query.value.trim().toLocaleLowerCase('es');
    return props.options.filter(option => option.value === props.modelValue || option.label.toLocaleLowerCase('es').includes(term));
});
function update(event: Event): void {
    if (event.target instanceof HTMLSelectElement) emit('update:modelValue', event.target.value);
}
</script>
<template>
    <div class="workshop-search-select">
        <label :for="`${controlId}-search`">Buscar {{ label }}</label>
        <input :id="`${controlId}-search`" v-model="query" type="search" :disabled="disabled" autocomplete="off">
        <label :for="controlId">{{ label }}<span v-if="required" aria-hidden="true"> *</span></label>
        <select :aria-label="label" :id="controlId" :value="modelValue" :required="required" :disabled="disabled" :aria-invalid="errors.length > 0" :aria-describedby="errors.length ? `${controlId}-error` : undefined" @change="update">
            <option value="">Seleccionar…</option>
            <option v-for="option in visible" :key="option.value" :value="option.value">{{ option.label }}</option>
        </select>
        <p v-if="!options.length" role="status">No hay opciones disponibles.</p>
        <p v-else-if="!visible.length" role="status">Sin resultados.</p>
        <ul v-if="errors.length" :id="`${controlId}-error`" class="workshop-field-errors">
            <li v-for="message in errors" :key="message">{{ message }}</li>
        </ul>
    </div>
</template>
<style scoped>
.workshop-search-select { display: grid; gap: .4rem; min-width: 0; }
</style>
