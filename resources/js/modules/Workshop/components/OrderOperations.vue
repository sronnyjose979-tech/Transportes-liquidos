<script setup lang="ts">
import { computed, ref } from 'vue';
import type { RecordRow } from '../api';
import type { Session } from '../../FleetAndDocuments/contracts';
import { instant, serverInstant } from '../presentation';
import type { Field } from './CommandForm.vue';
import RecordTable from './RecordTable.vue';
import { choices, effective, selectField, noteField, dateField, reasonField, operationValues, timeSummary, costSummary } from '../operations';
import type { Choice, OperationCommand } from '../operations';
const props = defineProps<{ order: RecordRow; actor: Session; related: Record<string, RecordRow[]>; parts: Choice[]; suppliers: Choice[]; times: unknown; costTotals: unknown; busy?: boolean; disabled?: boolean }>();
const emit = defineEmits<{ command: [spec: OperationCommand]; error: [error: unknown] }>();
const tab = ref('execution');
const tabs = computed(() => [{ id: 'execution', label: 'Ejecución' }, { id: 'times', label: 'Tiempos' }, { id: 'materials', label: 'Materiales' }, ...(office.value ? [{ id: 'costs', label: 'Costos' }] : [])]);
const admin = computed(() => props.actor.roles.includes('administrator'));
const office = computed(() => props.actor.roles.some(role => ['administrator', 'secretary'].includes(role)));
const assignments = computed(() => (props.related['mechanic-assignments'] ?? []).filter(row => row.is_current === true && (office.value || row.is_current_actor === true)));
const executable = computed(() => (admin.value || props.actor.roles.includes('mechanic')) && (admin.value || assignments.value.some(row => row.is_current_actor === true)));
const active = computed(() => ['authorized', 'in_repair'].includes(String(props.order.status)));
const scopes = computed(() => (props.related.scopes ?? []).filter(row => row.is_effective === true && row.is_applied === true).map(row => row.id));
const tasks = computed(() => (props.related['scope-tasks'] ?? []).filter(row => scopes.value.includes(String(row.scope_id))));
const scopeParts = computed(() => (props.related['scope-parts'] ?? []).filter(row => scopes.value.includes(String(row.scope_id))));
const taskOptions = computed(() => choices(tasks.value, row => String(row.description)));
const partOptions = computed(() => choices(scopeParts.value, row => `${props.parts.find(part => part.value === row.spare_part_id)?.label ?? 'Repuesto autorizado'} · ${String(row.quantity)}`));
const assignmentField = (key = 'mechanic_assignment_id', required = true) => selectField(key, 'Mecánico asignado', choices(assignments.value.filter(row => key === 'delivered_to_assignment_id' || admin.value || row.is_current_actor === true), row => String(row.employee_name ?? 'Mecánico')), required);
const quantity: Field = { key: 'quantity', label: 'Cantidad decimal', required: true, type: 'number' };
const seconds = computed(() => timeSummary(props.times));
const totals = computed(() => costSummary(props.costTotals));
function historyColumns(kind:string){return kind==='activities'?[{key:'description',label:'Actividad'},{key:'observations',label:'Observaciones'},{key:'occurred_at',label:'Fecha'},{key:'revision',label:'Revisión'},{key:'is_effective',label:'Vigente'}]:kind==='time-events'?[{key:'event_type',label:'Evento'},{key:'occurred_at',label:'Fecha'},{key:'revision',label:'Revisión'},{key:'is_effective',label:'Vigente'}]:kind==='costs'?[{key:'description',label:'Concepto'},{key:'basis',label:'Base'},{key:'amount',label:'Importe'},{key:'currency',label:'Moneda'},{key:'revision',label:'Revisión'},{key:'is_effective',label:'Vigente'}]:kind==='external-repairs'?[{key:'description',label:'Reparación'},{key:'performed_at',label:'Fecha'},{key:'revision',label:'Revisión'},{key:'is_effective',label:'Vigente'}]:[{key:'description',label:'Registro'},{key:'quantity',label:'Cantidad'},{key:'occurred_at',label:'Fecha'},{key:'revision',label:'Revisión'},{key:'is_effective',label:'Vigente'}];}
const histories = computed(() => tab.value === 'execution' ? [{ kind: 'activities', title: 'Actividades realizadas', rectification: 'activity' }] : tab.value === 'times' ? [{ kind: 'time-events', title: 'Historial de tiempos', rectification: 'time' }] : tab.value === 'materials' ? [{ kind: 'material-needs', title: 'Necesidades de repuestos', rectification: 'need' }, { kind: 'deliveries', title: 'Entregas físicas', rectification: 'delivery' }, { kind: 'usages', title: 'Utilización de repuestos', rectification: 'usage' }] : [{ kind: 'external-repairs', title: 'Reparaciones externas', rectification: 'external' }, { kind: 'costs', title: 'Historial de costos', rectification: 'cost' }]);
function send(title: string, path: string, fields: Field[], extra: Record<string, unknown> = {}, transform = operationValues): void {
    if (props.busy || props.disabled) return;
    emit('command', { title, path: `orders/${props.order.id}/${path}`, fields, extra, transform });
}
function activity(): void { send('Registrar actividad', 'activities', [selectField('task_id', 'Trabajo autorizado', taskOptions.value), assignmentField('mechanic_assignment_id', !admin.value), noteField('description', 'Actividad realizada'), noteField('observations', 'Observaciones', false), dateField(), reasonField()], { version: props.order.version }); }
function time(event: string): void { send(`Registrar ${ { start: 'inicio', pause: 'pausa', resume: 'reanudación', finish: 'fin' }[event] } de tiempo`, 'time-events', [assignmentField(), dateField(), reasonField()], { event_type: event, version: props.order.version }); }
function finish(): void { send('Finalizar reparación de la orden', 'finish', [assignmentField('mechanic_assignment_id', !admin.value), dateField(), reasonField()], { version: props.order.version }); }
function close(): void { send('Cerrar orden con revisión administrativa', 'close', [noteField('review', 'Revisión: trabajos, repuestos, tiempos y pendientes (un punto por línea)'), dateField(), reasonField()], { version: props.order.version }, values => ({ ...operationValues(values), review: values.review?.split('\n').map(line => line.trim()).filter(Boolean) })); }
function material(kind: string): void {
    const deliveryOptions = choices(effective(props.related.deliveries ?? []).filter(row => admin.value || assignments.value.some(assignment => assignment.id === row.delivered_to_assignment_id)), row => `${props.parts.find(part => part.value === scopeParts.value.find(line => line.id === row.scope_part_id)?.spare_part_id)?.label ?? 'Entrega'} · ${String(row.quantity)} · ${String(row.occurred_at)}`);
    const fields = kind === 'deliveries' ? [selectField('scope_part_id', 'Repuesto autorizado', partOptions.value), assignmentField('delivered_to_assignment_id'), quantity] : kind === 'usages' ? [selectField('delivery_id', 'Entrega recibida', deliveryOptions), assignmentField(), quantity] : [selectField('scope_part_id', 'Línea de alcance (opcional)', partOptions.value, false), selectField('spare_part_id', 'Repuesto solicitado', props.parts), quantity, noteField('description', 'Necesidad de material')];
    send(kind === 'deliveries' ? 'Registrar entrega física' : kind === 'usages' ? 'Registrar utilización' : 'Solicitar repuesto', kind, [...fields, dateField(), reasonField()]);
}
function external(): void { send('Registrar reparación externa', 'external-repairs', [selectField('scope_task_id', 'Trabajo autorizado', taskOptions.value), selectField('supplier_id', 'Proveedor', props.suppliers), noteField('description', 'Reparación realizada'), dateField('performed_at', 'Fecha de reparación (Costa Rica)'), reasonField()]); }
function cost(): void {
    send('Registrar costo', 'costs', [selectField('cost_kind', 'Tipo de costo', [{ value: 'labor', label: 'Mano de obra' }, { value: 'part', label: 'Repuesto' }, { value: 'external', label: 'Externo' }], false), selectField('basis', 'Base', [{ value: 'estimated', label: 'Estimado' }, { value: 'actual', label: 'Real' }]), { key: 'amount', label: 'Importe decimal (si corresponde)', type: 'number' }, { key: 'currency', label: 'Moneda ISO (por ejemplo CRC)' }, selectField('scope_task_id', 'Trabajo (opcional)', taskOptions.value, false), selectField('scope_part_id', 'Repuesto (opcional)', partOptions.value, false), selectField('usage_id', 'Utilización (opcional)', choices(effective(props.related.usages ?? []), row => `Utilización ${String(row.quantity)} · ${String(row.occurred_at)}`), false), selectField('external_repair_id', 'Reparación externa (opcional)', choices(effective(props.related['external-repairs'] ?? []), row => String(row.description)), false), selectField('covered_by_cost_id', 'Cubierto por otro costo (sin doble contabilización)', choices(effective(props.related.costs ?? []), row => `${String(row.description)} · ${String(row.amount)} ${String(row.currency)}`), false), selectField('source_attachment_id', 'Comprobante confirmado (opcional)', choices((props.related.attachments ?? []).filter(row => row.is_effective !== false), row => `${String(row.category)} · ${String(row.original_name ?? row.extension ?? 'Archivo')}`), false), noteField('description', 'Concepto y detalle'), dateField(), reasonField()]);
}
function canCorrect(kind: string, row: RecordRow): boolean {
    return !admin.value && executable.value && active.value && ['activity', 'time'].includes(kind) && row.is_effective === true && assignments.value.some(assignment => assignment.id === row.mechanic_assignment_id);
}
function correct(kind: string, row: RecordRow): void {
    if (!canCorrect(kind, row)) return;
    const fields = kind === 'activity' ? [selectField('task_id', 'Trabajo autorizado', taskOptions.value), assignmentField(), { ...noteField('description', 'Actividad corregida'), value: String(row.description ?? '') }, { ...noteField('observations', 'Observaciones', false), value: String(row.observations ?? '') }, dateField(), reasonField()] : [assignmentField(), { ...selectField('event_type', 'Evento corregido', [{ value: 'start', label: 'Inicio' }, { value: 'pause', label: 'Pausa' }, { value: 'resume', label: 'Reanudación' }, { value: 'finish', label: 'Fin' }]), value: String(row.event_type) }, dateField(), reasonField()];
    for (const field of fields) if (['task_id', 'mechanic_assignment_id'].includes(field.key)) field.value = String(row[field.key] ?? '');
    const occurrence = new Date(serverInstant(String(row.occurred_at)));
    if (Number.isFinite(occurrence.getTime())) {
        const date = fields.find(field => field.key === 'occurred_at');
        if (date) date.value = new Intl.DateTimeFormat('sv-SE', { timeZone: 'America/Costa_Rica', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false }).format(occurrence).replace(' ', 'T');
    }
    const initialDate=fields.find(field=>field.key==='occurred_at')?.value;
    send('Registrar corrección técnica auditable', kind === 'activity' ? 'activities' : 'time-events', fields, { [kind === 'activity' ? 'previous_activity_id' : 'previous_event_id']: row.id, version: props.order.version }, values=>{const result=operationValues(values);if(initialDate && result.occurred_at===instant(initialDate))result.occurred_at=serverInstant(String(row.occurred_at));return result;});
}
function rectify(kind: string, row: RecordRow): void {
    if (props.busy || props.disabled) return;
    const fields: Field[] = kind === 'time' ? [selectField('event_type', 'Evento corregido', [{ value: 'start', label: 'Inicio' }, { value: 'pause', label: 'Pausa' }, { value: 'resume', label: 'Reanudación' }, { value: 'finish', label: 'Fin' }], false), dateField()] : kind === 'activity' ? [noteField('description', 'Actividad corregida'), noteField('observations', 'Observaciones', false)] : kind === 'external' ? [noteField('description', 'Descripción corregida')] : kind === 'cost' ? [{ key: 'amount', label: 'Importe decimal corregido', type: 'number' }, noteField('description', 'Descripción corregida', false)] : kind === 'need' ? [quantity, noteField('description', 'Necesidad corregida', false)] : [quantity];
    emit('command', { title: 'Solicitar rectificación auditable', path: `facts/${kind}/${row.id}/rectifications`, fields: [...fields, reasonField()], transform: values => { const changes = operationValues(values); delete changes.reason; return { version: props.order.version, executor_id: Number(props.actor.id), reason: values.reason, changes }; } });
}
</script>

<template>
    <section class="workshop-panel" aria-label="Operación de la orden">
        <h2>Operación de Taller</h2>
        <nav class="workshop-form-actions" aria-label="Secciones operativas"><button v-for="item in tabs" :key="item.id" type="button" :class="tab === item.id ? 'workshop-button-primary' : 'workshop-button-secondary'" :aria-pressed="tab === item.id" @click="tab = item.id">{{ item.label }}</button></nav>
        <template v-if="tab === 'execution'">
            <h3>Trabajos autorizados y aplicados</h3>
            <RecordTable :rows="tasks" :columns="[{key:'line_number',label:'Línea'},{key:'description',label:'Trabajo'}]" />
            <div class="workshop-form-actions"><button v-if="executable && active && tasks.length" class="workshop-button-primary" :disabled="busy || disabled" @click="activity">Registrar actividad</button><button v-if="executable && order.status === 'in_repair'" class="workshop-button-secondary" :disabled="busy || disabled" @click="finish">Finalizar reparación</button><button v-if="admin && order.status === 'finished'" class="workshop-button-secondary" :disabled="busy || disabled" @click="close">Revisar y cerrar orden</button></div>
        </template>
        <template v-if="tab === 'times'">
            <p v-if="seconds">Tiempo confirmado acumulado: <strong>{{ seconds.total }} segundos-persona</strong>. Los intervalos abiertos no están incluidos.</p><p v-else>Sin resumen de tiempos confirmado.</p>
            <ul v-if="seconds"><li v-for="participant in seconds.participants" :key="participant.id">{{ (related['mechanic-assignments'] ?? []).find(row => row.id === participant.id)?.employee_name ?? 'Mecánico' }}: {{ participant.seconds }} segundos <span v-if="participant.open">· intervalo abierto desde {{ participant.open }}</span></li></ul>
            <div v-if="executable && active" class="workshop-form-actions"><button v-for="event in [{id:'start',label:'Iniciar tiempo'},{id:'pause',label:'Pausar'},{id:'resume',label:'Reanudar'},{id:'finish',label:'Finalizar tiempo'}]" :key="event.id" class="workshop-button-secondary" :disabled="busy || disabled" @click="time(event.id)">{{ event.label }}</button></div><p>Los intervalos y conflictos se validan en el servidor. La finalización de tiempos no finaliza la orden.</p>
        </template>
        <template v-if="tab === 'materials'">
            <p class="workshop-alert">Estos registros documentan entregas y utilización física. Todavía no existe descuento real de inventario ni consulta de existencias.</p>
            <div v-if="active" class="workshop-form-actions"><button v-if="office || executable" class="workshop-button-secondary" :disabled="busy || disabled" @click="material('material-needs')">Solicitar repuesto</button><button v-if="office" class="workshop-button-secondary" :disabled="busy || disabled" @click="material('deliveries')">Registrar entrega</button><button v-if="executable" class="workshop-button-primary" :disabled="busy || disabled" @click="material('usages')">Registrar utilización</button></div>
        </template>
        <template v-if="tab === 'costs' && office">
            <h3>Totales confirmados por moneda</h3><p v-if="costTotals===null">Resumen de costos no disponible.</p><p v-else-if="!totals.length">Sin costos monetarios contabilizables.</p><p v-for="total in totals" :key="`${total.basis}-${total.currency}`"><strong>{{ total.basis === 'actual' ? 'Real' : 'Estimado' }}: {{ total.amount }} {{ total.currency }}</strong></p><p>Totales calculados por el servidor con precisión decimal, sin sumar monedas ni duplicar costos cubiertos o rectificados.</p>
            <div v-if="active" class="workshop-form-actions"><button class="workshop-button-primary" :disabled="busy || disabled" @click="cost">Registrar costo</button><button v-if="admin" class="workshop-button-secondary" :disabled="busy || disabled" @click="external">Registrar reparación externa</button></div>
        </template>
        <article v-for="history in histories" :key="history.kind"><h3>{{ history.title }}</h3><RecordTable :rows="related[history.kind] ?? []" :columns="historyColumns(history.kind)" /><div class="workshop-form-actions"><button v-for="row in (related[history.kind] ?? []).filter(item => canCorrect(history.rectification,item))" :key="`correct-${row.id}`" :disabled="busy || disabled" class="workshop-button-secondary" @click="correct(history.rectification,row)">Corregir {{ String(row.description ?? row.event_type) }} · {{ String(row.occurred_at) }}</button></div><div v-if="admin" class="workshop-form-actions"><button v-for="row in effective(related[history.kind] ?? [])" :key="row.id" :disabled="busy || disabled" class="workshop-button-secondary" @click="rectify(history.rectification,row)">Rectificar {{ String(row.description ?? row.event_type ?? row.quantity ?? 'registro') }} · {{ String(row.occurred_at ?? row.performed_at) }}</button></div></article>
    </section>
</template>
