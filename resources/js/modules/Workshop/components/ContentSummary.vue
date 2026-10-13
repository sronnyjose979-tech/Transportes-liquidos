<script setup lang="ts">
import { computed } from 'vue';
import { labels, text } from '../presentation';
const props = defineProps<{
    value: unknown;
}>();
const names: Record<string, string> = { before: 'Contenido anterior', after: 'Contenido propuesto', description: 'Descripción', tasks: 'Trabajos', parts: 'Repuestos', quantity: 'Cantidad', unit_snapshot: 'Unidad', description_snapshot: 'Repuesto', line_number: 'Línea', supplier_id: 'Proveedor', spare_part_id: 'Identificador del repuesto', task_id: 'Trabajo asociado', id: 'Identificador', work_order_id: 'Orden', scope_id: 'Alcance', root_record_id: 'Registro original', revision: 'Revisión', diagnosis_id: 'Diagnóstico', scope_kind: 'Tipo de alcance', priority: 'Prioridad', status: 'Estado', version: 'Versión', fleet_asset_id: 'Vehículo', kind: 'Tipo de mantenimiento', created_at: 'Fecha de creación', created_by_id: 'Responsable', admission_id: 'Ingreso', resolve_ids: 'Impedimentos a resolver', exited_at: 'Fecha de salida', fact_table: 'Proceso de registro', fact_id: 'Registro', changes: 'Cambios solicitados', executor_id: 'Responsable de ejecución', reason: 'Motivo' };
const entries = computed(() => props.value && typeof props.value === 'object' && !Array.isArray(props.value) ? Object.entries(props.value) : []);
</script>
<template>
<div class="workshop-content-summary">
<template v-if="Array.isArray(value)">
<p v-if="!value.length">Sin registros.</p>
<article v-for="(item,index) in value" :key="index">
<ContentSummary :value="item" />
</article>
</template>
<dl v-else-if="entries.length">
<template v-for="([key,item]) in entries" :key="key">
<dt>{{ names[key] ?? key }}</dt>
<dd>
<ContentSummary v-if="item && typeof item==='object'" :value="item" />
<span v-else>{{ labels[text(item)] ?? text(item) }}</span>
</dd>
</template>
</dl>
<span v-else>{{ text(value) }}</span>
</div>
</template>
