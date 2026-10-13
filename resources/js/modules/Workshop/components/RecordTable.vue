<script setup lang="ts">
export interface RecordRow { id: string; [key: string]: unknown }
defineProps<{ rows: RecordRow[]; columns: { key: string; label: string }[]; selectable?: boolean }>();
defineEmits<{ open: [row: RecordRow] }>();

function display(value: unknown): string {
    if (value === null || value === undefined || value === '') return '—';
    if (typeof value === 'string' || typeof value === 'number') return String(value);
    if (typeof value === 'boolean') return value ? 'Sí' : 'No';
    if (Array.isArray(value)) return value.map(display).join(', ');
    return 'Ver detalle';
}
</script>

<template>
    <div class="workshop-table-scroll" role="region" aria-label="Listado de registros" tabindex="0">
        <table class="workshop-table">
            <caption class="workshop-sr-only">Registros de Taller</caption>
            <thead><tr><th v-for="column in columns" :key="column.key" scope="col">{{ column.label }}</th><th v-if="selectable" scope="col">Acciones</th></tr></thead>
            <tbody>
                <tr v-for="row in rows" :key="row.id">
                    <td v-for="column in columns" :key="column.key" :data-label="column.label">{{ display(row[column.key]) }}</td>
                    <td v-if="selectable"><button type="button" class="workshop-button-secondary" :aria-label="`Ver detalle del registro ${row.id}`" @click="$emit('open', row)">Ver detalle <span aria-hidden="true">→</span></button></td>
                </tr>
                <tr v-if="!rows.length"><td :colspan="columns.length + (selectable ? 1 : 0)" class="workshop-empty">No hay registros para los criterios seleccionados.</td></tr>
            </tbody>
        </table>
    </div>
</template>
