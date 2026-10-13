<script setup lang="ts">
import { computed, onUnmounted, ref, watch } from 'vue';
import type { Session } from '../../FleetAndDocuments/contracts';
import { getSession, refreshCsrf } from '../../FleetAndDocuments/api';
import { all, ApiError, failure } from '../api';
import type { RecordRow } from '../api';
import { attachmentChanges, attachmentReceipt, authorizedAttachmentMetadata, clearAttachmentRecovery, confirmAttachment, describeFile, downloadAttachment, listAttachments, readAttachmentRecovery, releaseAttachment, requestAttachmentCorrection, reserveAttachment, saveAttachmentRecovery } from '../attachments';
import type { ApprovedAttachmentCorrection, AttachmentRecovery } from '../attachments';
import SearchSelect from './SearchSelect.vue';

const props = withDefaults(defineProps<{ order: RecordRow; actor: Session; canWrite: boolean; approvedCorrections?: ApprovedAttachmentCorrection[] }>(), { approvedCorrections: () => [] });
const emit = defineEmits<{ changed: []; error: [unknown]; pending: [boolean]; navigateAuthorization: [requestId: string] }>();
const rows = ref<RecordRow[]>([]), externalRepairs = ref<RecordRow[]>([]), page = ref(1), lastPage = ref(1), total = ref(0);
const loading = ref(false), busy = ref(false), message = ref(''), error = ref(''), fields = ref<Record<string, string[]>>({});
const file = ref<File>(), category = ref('photo'), reason = ref(''), externalRepair = ref(''), progress = ref<number | null>(null);
const office = computed(() => props.actor.roles.some(role => ['administrator', 'secretary'].includes(role)));
const admin = computed(() => props.actor.roles.includes('administrator'));
const correction = ref(''), previousAttachment = ref(''), allRows = ref<RecordRow[]>([]);
const correctionOptions = computed(() => props.approvedCorrections.map(row => ({ value: row.decisionId, label: row.reason })));
const attachmentOptions = computed(() => allRows.value.filter(row => !allRows.value.some(next => next.previous_attachment_id === row.id)).map(row => ({ value: row.id, label: `${row.category === 'photo' ? 'Fotografía' : row.category === 'invoice' ? 'Factura' : 'Documento'} · ${row.registered_at} · Revisión ${row.revision}` })));
const repairOptions = computed(() => externalRepairs.value.map(row => ({ value: row.id, label: String(row.description ?? 'Reparación externa') })));
// Session-scoped metadata permits explicit recovery; file bytes and credentials are never persisted.
const pending = ref<AttachmentRecovery>();
let generation = 0;
function identity(): string { return [...props.actor.roles].sort().join(','); }
function report(value: unknown): void { const issue = failure(value); error.value = issue.message; fields.value = issue.fields; emit('error', issue); }
async function checkActor(writing = true): Promise<void> {
    const expectedActor = props.actor.id, expectedRoles = identity(), current = await getSession();
    if (current.id !== expectedActor || [...current.roles].sort().join(',') !== expectedRoles) {
        generation++; rows.value = []; allRows.value = []; externalRepairs.value = []; clearAttachmentRecovery(expectedActor); pending.value = undefined; file.value = undefined; emit('pending', false);
        throw new ApiError(403, 'La identidad o los permisos cambiaron. Vuelve a abrir la orden.');
    }
    if (writing && !props.canWrite) throw new ApiError(403, 'No tienes participación vigente para modificar esta orden.');
}
async function refresh(): Promise<void> {
    const version = ++generation; loading.value = true;
    try {
        const result = await listAttachments(props.order.id, page.value);
        if (version !== generation) return;
        rows.value = result.data; page.value = result.meta.current_page; lastPage.value = result.meta.last_page; total.value = result.meta.total;
        if (admin.value) {
            const candidates = [...result.data];
            for (let number = 1; number <= result.meta.last_page; number++) if (number !== page.value) candidates.push(...(await listAttachments(props.order.id, number)).data);
            if (version === generation) allRows.value = candidates;
        }
        if (office.value) { const repairs = await all('external-repairs', { work_order_id: props.order.id }); if (version === generation) externalRepairs.value = repairs; }
    } catch (issue) { if (version === generation) report(issue); }
    finally { if (version === generation) loading.value = false; }
}
function selectFile(event: Event): void { if (event.target instanceof HTMLInputElement) file.value = event.target.files?.[0]; }
function complete(text: string): void { clearAttachmentRecovery(props.actor.id, props.order.id); pending.value = undefined; file.value = undefined; correction.value = ''; previousAttachment.value = ''; progress.value = null; emit('pending', false); message.value = text; emit('changed'); }
function rejectedReservation(issue: unknown): never {
    if (!pending.value?.reservation && issue instanceof ApiError && [401, 403, 404, 409, 419, 422].includes(issue.status)) {
        clearAttachmentRecovery(props.actor.id, props.order.id); pending.value = undefined; emit('pending', false);
    }
    throw issue;
}
async function upload(requestCorrection = false): Promise<void> {
    if (busy.value || !file.value) return;
    busy.value = true; emit('pending', true); error.value = ''; fields.value = {}; message.value = ''; progress.value = null;
    try {
        await checkActor();
        if (!pending.value) {
            let metadata = await describeFile(file.value, category.value, reason.value, office.value ? externalRepair.value : '');
            if (correction.value) {
                const approved = props.approvedCorrections.find(row => row.decisionId === correction.value);
                if (!approved) throw new ApiError(409, 'La rectificación aprobada ya no está disponible.');
                metadata = authorizedAttachmentMetadata(metadata, approved);
            }
            const operation: AttachmentRecovery = { actor: props.actor.id, roles: identity(), order: props.order.id, metadata, reserveKey: crypto.randomUUID(), confirmKey: crypto.randomUUID(), releaseKey: crypto.randomUUID() };
            if (requestCorrection) {
                const executor = Number(props.actor.id), version = Number(props.order.version);
                if (!admin.value || !previousAttachment.value || !Number.isSafeInteger(executor) || executor < 1 || !Number.isSafeInteger(version) || version < 1) throw new ApiError(422, 'Selecciona un adjunto vigente para solicitar su rectificación.');
                operation.rectificationRequest = { factId: previousAttachment.value, executor, version };
            }
            pending.value = operation;
            saveAttachmentRecovery(pending.value);
            emit('pending', true);
        }
        const operation = pending.value;
        const selected = await describeFile(file.value, operation.metadata.category, operation.metadata.reason, operation.metadata.external_repair_id);
        if (JSON.stringify(attachmentChanges(selected)) !== JSON.stringify(attachmentChanges(operation.metadata))) throw new ApiError(422, 'Selecciona el archivo original: la reserva exige el mismo contenido.');
        if (operation.rectificationRequest) {
            if (!admin.value) throw new ApiError(403, 'La solicitud de rectificación requiere administrador.');
            const result = await requestAttachmentCorrection(operation).catch(rejectedReservation);
            complete('Solicitud de rectificación registrada. Revisa su decisión en Autorizaciones; todavía no se ha reemplazado el archivo.');
            await refresh(); if (typeof result.request_id === 'string') emit('navigateAuthorization', result.request_id);
            return;
        }
        if (!operation.reservation) {
            const result = await reserveAttachment(operation.order, operation.reserveKey, operation.metadata).catch(rejectedReservation);
            if (typeof result.reservation_id !== 'string') throw new ApiError(0, 'Reserva sin respuesta válida. Reintenta la misma operación.');
            operation.reservation = result.reservation_id;
            saveAttachmentRecovery(operation);
        }
        await checkActor();
        await confirmAttachment(operation.reservation, operation.confirmKey, file.value, value => { progress.value = value; });
        complete('Evidencia confirmada.'); await refresh();
    } catch (issue) { report(issue); }
    finally { busy.value = false; if (!pending.value) emit('pending', false); }
}
async function recover(): Promise<void> {
    if (busy.value || !pending.value?.reservation) return;
    busy.value = true; error.value = '';
    try {
        await checkActor(false); const receipt = await attachmentReceipt(pending.value.reservation);
        if (receipt.status === 'attachment_confirm') { complete('El recibo confirma que la evidencia está adjunta.'); await refresh(); }
        else if (receipt.status === 'attachment_release') { complete('Reserva liberada.'); await refresh(); }
        else message.value = 'Reserva pendiente. Selecciona el archivo original y reintenta o libera la reserva.';
    } catch (issue) { report(issue); } finally { busy.value = false; }
}
async function release(): Promise<void> {
    if (busy.value || !pending.value?.reservation || !reason.value.trim()) return;
    busy.value = true; error.value = '';
    try {
        await checkActor(); const operation = pending.value; operation.releaseReason ??= reason.value;
        saveAttachmentRecovery(operation);
        await releaseAttachment(operation.reservation!, operation.releaseKey, operation.releaseReason);
        complete('Reserva liberada.'); await refresh();
    } catch (issue) { report(issue); } finally { busy.value = false; }
}
async function download(row: RecordRow): Promise<void> {
    if (busy.value) return; busy.value = true;
    try { await checkActor(false); await downloadAttachment(row.id, String(row.extension)); }
    catch (issue) { report(issue); } finally { busy.value = false; }
}
async function csrf(): Promise<void> { try { await refreshCsrf(); message.value = 'Sesión de seguridad actualizada. Puedes reintentar la misma operación.'; } catch (issue) { report(issue); } }
watch(() => `${props.order.id}:${props.actor.id}:${identity()}`, () => {
    generation++; pending.value = undefined; rows.value = []; allRows.value = []; externalRepairs.value = []; file.value = undefined; reason.value = ''; externalRepair.value = ''; correction.value = ''; previousAttachment.value = ''; page.value = 1; emit('pending', false); void refresh();
    const saved = readAttachmentRecovery(props.actor.id, props.order.id, identity());
    if (saved) {
        pending.value = saved; category.value = saved.metadata.category; reason.value = saved.metadata.reason; externalRepair.value = saved.metadata.external_repair_id ?? ''; emit('pending', true);
        message.value = 'Operación recuperada. Consulta el recibo y selecciona el archivo original antes de reintentar.';
        if (saved.reservation) void recover();
    }
}, { immediate: true });
watch(correction, value => {
    const approved = props.approvedCorrections.find(row => row.decisionId === value);
    if (approved && !pending.value) { category.value = String(approved.after.category); externalRepair.value = typeof approved.after.external_repair_id === 'string' ? approved.after.external_repair_id : ''; reason.value = approved.reason; previousAttachment.value = ''; }
});
onUnmounted(() => { generation++; });
</script>

<template>
<section aria-label="Evidencias y documentos" class="workshop-card">
    <h3>Evidencias y documentos privados</h3>
    <p>PDF, JPEG y PNG de hasta 10 MB. Máximo 20 adjuntos por orden, incluidas las reservas pendientes. Los documentos económicos requieren permisos de oficina.</p>
    <p v-if="loading" role="status">Consultando evidencias…</p>
    <p v-if="message" role="status" class="workshop-success">{{ message }}</p>
    <div v-if="error" role="alert" class="workshop-alert">{{ error }}<p v-for="(messages, name) in fields" :key="name">{{ messages.join(' ') }}</p><button type="button" :disabled="busy" @click="csrf">Actualizar sesión de seguridad</button></div>
    <form v-if="canWrite" class="evidence-form" @submit.prevent="upload()">
        <SearchSelect v-if="correctionOptions.length" v-model="correction" label="Rectificación aprobada (opcional)" :options="correctionOptions" :disabled="busy || !!pending || !!previousAttachment" />
        <SearchSelect v-if="admin" v-model="previousAttachment" label="Adjunto a rectificar (opcional)" :options="attachmentOptions" :disabled="busy || !!pending || !!correction" />
        <p v-if="previousAttachment">La solicitud conserva el archivo anterior. Se aplicará mediante una nueva reserva y confirmación solo después de aprobarse.</p>
        <label>Categoría<select aria-label="Categoría" v-model="category" :disabled="busy || !!pending || !!correction"><option value="photo">Fotografía</option><option value="document">Documento</option><option v-if="office" value="invoice">Factura</option></select></label>
        <SearchSelect v-if="office" v-model="externalRepair" label="Reparación externa (opcional)" :options="repairOptions" :disabled="busy || !!pending || !!correction" />
        <label>Motivo del adjunto<textarea aria-label="Motivo del adjunto" v-model="reason" required maxlength="5000" :disabled="busy || !!pending || !!correction" /></label>
        <label>Archivo privado<input aria-label="Archivo privado" type="file" accept="application/pdf,image/jpeg,image/png,.pdf,.jpg,.jpeg,.png" :disabled="busy" @change="selectFile"></label>
        <p v-if="pending" role="status">Operación pendiente. Las claves y metadatos se conservan en esta pestaña para recuperación. El archivo no se almacena: tras recargar, selecciona el original. Consulta el recibo antes de reintentar o liberar.</p>
        <p v-if="progress !== null" role="status">Archivo transferido: {{ progress }} %. Esperando confirmación del servidor.</p>
        <progress v-if="progress !== null" :value="progress" max="100" aria-label="Progreso de transferencia del archivo" />
        <div class="workshop-actions"><button type="submit" :disabled="busy || loading || !file || (!!previousAttachment && !pending)">{{ busy ? 'Procesando…' : pending?.rectificationRequest ? 'Reintentar la misma solicitud' : pending ? 'Reintentar el mismo adjunto' : correction ? 'Aplicar rectificación de evidencia' : 'Adjuntar evidencia' }}</button><button v-if="admin && previousAttachment && !pending" type="button" :disabled="busy || loading || !file || !reason.trim()" @click="upload(true)">Solicitar rectificación de evidencia</button><button v-if="pending?.reservation" type="button" :disabled="busy" @click="recover">Consultar recibo</button><button v-if="pending?.reservation" type="button" :disabled="busy" @click="release">Liberar reserva</button></div>
    </form>
    <p v-if="!loading && !rows.length">Sin evidencias visibles para tu sesión.</p>
    <ul class="evidence-list"><li v-for="row in rows" :key="row.id"><span>{{ row.category === 'photo' ? 'Fotografía' : row.category === 'invoice' ? 'Factura' : 'Documento' }} · {{ row.extension }} · {{ row.size_bytes }} bytes · Revisión {{ row.revision }}<small>{{ row.registered_at }}</small></span><button type="button" :disabled="busy" @click="download(row)">Descargar archivo privado</button></li></ul>
    <div class="workshop-actions"><button type="button" :disabled="busy || loading || page <= 1" @click="page--; refresh()">Evidencias anteriores</button><span>Página {{ page }} de {{ lastPage }} · {{ total }} visibles</span><button type="button" :disabled="busy || loading || page >= lastPage" @click="page++; refresh()">Evidencias siguientes</button><button type="button" :disabled="busy || loading" @click="refresh">Actualizar evidencias</button></div>
</section>
</template>
<style scoped>
.evidence-form { display: grid; gap: 1rem; margin-block: 1rem; }
.evidence-form label { display: grid; gap: .4rem; }
.evidence-list { padding: 0; list-style: none; }
.evidence-list li { display: flex; justify-content: space-between; gap: 1rem; padding-block: 1rem; border-bottom: 1px solid #EAF3FF; flex-wrap: wrap; }
.evidence-list small { display: block; overflow-wrap: anywhere; }
progress { width: 100%; }
</style>
