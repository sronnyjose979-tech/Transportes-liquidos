<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { failure, getUploadResult, upload } from '../api';
import type { ApiError } from '../api';
import { confirmUpload, pendingUploads, uploadBody, uploadFingerprint, uploadsChanged } from '../uploadRecovery';
import type { PendingUpload } from '../uploadRecovery';

const props = defineProps<{ actorId: string; blocked: boolean }>();
const emit = defineEmits<{ error: [error: ApiError]; busy: [value: boolean]; resolved: [] }>();
const pending = ref<PendingUpload[]>([]);
const busy = ref(false);
const message = ref('');
let mounted = true;
async function refresh(): Promise<void> {
    const actor = props.actorId;
    try {
        const result = await pendingUploads(actor);
        if (mounted && props.actorId === actor) pending.value = result;
    } catch { if (mounted) message.value = 'No se pudo consultar la recuperación local. No inicies otra carga hasta recuperar el almacenamiento del navegador.'; }
}
function changed(): void { void refresh(); }
function beforeUnload(event: BeforeUnloadEvent): void { if (busy.value) { event.preventDefault(); event.returnValue = ''; } }
onMounted(() => { window.addEventListener(uploadsChanged, changed); window.addEventListener('beforeunload', beforeUnload); void refresh(); });
onBeforeUnmount(() => { mounted = false; window.removeEventListener(uploadsChanged, changed); window.removeEventListener('beforeunload', beforeUnload); });
watch(() => props.actorId, () => { pending.value = []; message.value = ''; void refresh(); });
async function recover(intent: PendingUpload, retry: boolean): Promise<void> {
    if (busy.value || props.blocked || intent.actorId !== props.actorId) return;
    busy.value = true; emit('busy', true); message.value = '';
    try {
        if (retry) await upload(intent.assetId, uploadBody(intent), intent.renewedId);
        else await getUploadResult(intent.assetId, intent.requestKey, await uploadFingerprint(intent));
        await confirmUpload(intent);
        if (mounted && intent.actorId === props.actorId) { message.value = 'Carga confirmada. El archivo pendiente se retiró de este navegador.'; emit('resolved'); }
    } catch (error) {
        if (!mounted || intent.actorId !== props.actorId) return;
        const problem = failure(error);
        message.value = !retry && problem.status === 404
            ? 'No hay confirmación visible todavía. Se conserva el archivo; puedes reintentar con la misma clave. Un resultado ausente no demuestra que la operación haya sido cancelada.'
            : problem.message;
        // Never erase an uncertain intent, even after a 409, expired session or rejected retry.
        emit('error', problem);
    } finally { busy.value = false; if (mounted) emit('busy', false); }
}
</script>
<template>
    <section v-if="pending.length || message" class="panel border-amber-300" aria-labelledby="upload-recovery-title">
        <h2 id="upload-recovery-title" class="text-lg font-semibold">Recuperación de cargas</h2>
        <p v-if="pending.length" class="mt-2 text-sm">Estas cargas no tienen confirmación local. Se conserva únicamente el archivo y la información necesaria para recuperar cada operación. No borres los datos del navegador mientras haya pendientes.</p>
        <p v-if="message" role="status" class="mt-3">{{ message }}</p>
        <ul><li v-for="intent in pending" :key="intent.requestKey" class="mt-4 space-x-3">
            <span>Carga {{ intent.requestKey }}</span>
            <button class="btn-secondary" :disabled="busy || blocked" @click="recover(intent, false)">Consultar resultado</button>
            <button class="btn-secondary" :disabled="busy || blocked" @click="recover(intent, true)">Reintentar misma carga</button>
        </li></ul>
    </section>
</template>
