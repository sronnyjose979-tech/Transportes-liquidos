<script setup lang="ts">
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { aborted, download, failure, getAllTypes, getCounts, getDocuments, getMissing } from '../api';
import type { ApiError } from '../api';
import type { DocumentType, FleetAsset, FleetDocument, Page, Validity } from '../contracts';
import { assetLabel, validityLabels } from '../presentation';
import DocumentList from './DocumentList.vue';
import DocumentUploadForm from './DocumentUploadForm.vue';
import Pagination from './Pagination.vue';

const props = defineProps<{ asset: FleetAsset }>();
const emit = defineEmits<{ error: [error: ApiError]; busy: [value: boolean] }>();
const result = ref<Page<FleetDocument> | null>(null);
const missing = ref<Page<DocumentType> | null>(null);
const counts = ref<Record<Validity, number> | null>(null);
const loading = ref(false);
const summaryLoading = ref(false);
const listFailed = ref(false);
const summaryFailed = ref(false);
const history = ref(false);
const validity = ref<Validity | ''>('');
const historyType = ref('');
const historyName = ref('');
const formOpen = ref(false);
const typesLoading = ref(false);
const saving = ref(false);
const types = ref<DocumentType[]>([]);
const renewal = ref<FleetDocument | null>(null);
const initialTypeId = ref('');
const success = ref('');
const downloadingId = ref<string | null>(null);
const title = ref<HTMLHeadingElement | null>(null);
const registerButton = ref<HTMLButtonElement | null>(null);
let trigger: HTMLElement | null = null;
let listControl: AbortController | undefined;
let summaryControl: AbortController | undefined;
let missingControl: AbortController | undefined;
let typesControl: AbortController | undefined;
let downloadControl: AbortController | undefined;
const states: Validity[] = ['valid', 'expiring_soon', 'expired', 'no_expiration'];
onMounted(() => refresh());
onBeforeUnmount(() => { listControl?.abort(); summaryControl?.abort(); missingControl?.abort(); typesControl?.abort(); downloadControl?.abort(); });
async function load(number = 1): Promise<void> {
    listControl?.abort(); const active = new AbortController(); listControl = active;
    loading.value = true; listFailed.value = false; result.value = null;
    try { const value = await getDocuments(props.asset.id, number, history.value, validity.value, historyType.value, active.signal); if (!active.signal.aborted) result.value = value; }
    catch (error) { if (!aborted(error) && !active.signal.aborted) { listFailed.value = true; emit('error', failure(error)); } }
    finally { if (!active.signal.aborted) loading.value = false; }
}
async function loadSummary(): Promise<void> {
    summaryControl?.abort(); const active = new AbortController(); summaryControl = active;
    summaryLoading.value = true; summaryFailed.value = false; counts.value = null;
    try { const value = await getCounts(props.asset.id, active.signal); if (!active.signal.aborted) counts.value = value; }
    catch (error) { if (!aborted(error) && !active.signal.aborted) { summaryFailed.value = true; emit('error', failure(error)); } }
    finally { if (!active.signal.aborted) summaryLoading.value = false; }
}
async function loadMissing(number = 1): Promise<void> {
    missingControl?.abort(); const active = new AbortController(); missingControl = active;
    missing.value = null;
    try { const value = await getMissing(props.asset.id, number, active.signal); if (!active.signal.aborted) missing.value = value; }
    catch (error) { if (!aborted(error) && !active.signal.aborted) emit('error', failure(error)); }
}
async function refresh(): Promise<void> { await Promise.all([load(1), loadSummary(), loadMissing(1)]); }
function viewHistory(document?: FleetDocument): void {
    history.value = true; validity.value = ''; historyType.value = document?.document_type_id ?? ''; historyName.value = document?.configuration.name ?? '';
    void load();
    void nextTick(() => title.value?.focus());
}
function viewCurrent(): void { history.value = false; historyType.value = ''; historyName.value = ''; void load(); }
function filter(state: Validity | ''): void { validity.value = state; void load(); }
async function openForm(document: FleetDocument | null = null, event?: MouseEvent, typeId = ''): Promise<void> {
    if (saving.value || typesLoading.value) return;
    trigger = event?.currentTarget instanceof HTMLElement ? event.currentTarget : null;
    typesControl?.abort(); const active = new AbortController(); typesControl = active;
    typesLoading.value = true; emit('busy', true); success.value = '';
    try {
        const value = await getAllTypes(active.signal);
        if (active.signal.aborted) return;
        types.value = value; renewal.value = document; initialTypeId.value = typeId; formOpen.value = true;
    } catch (error) { if (!aborted(error) && !active.signal.aborted) emit('error', failure(error)); }
    finally { if (!active.signal.aborted) { typesLoading.value = false; emit('busy', false); } }
}
async function closeForm(): Promise<void> {
    formOpen.value = false; renewal.value = null; types.value = [];
    await nextTick();
    (trigger?.isConnected ? trigger : registerButton.value)?.focus();
}
function busy(value: boolean): void { saving.value = value; emit('busy', value); }
async function saved(): Promise<void> {
    success.value = renewal.value ? 'Renovación registrada. La versión anterior permanece en el historial.' : 'Documento registrado correctamente.';
    await closeForm(); await refresh();
    await nextTick(); title.value?.focus();
}
async function downloadFile(document: FleetDocument): Promise<void> {
    if (downloadingId.value) return;
    downloadControl = new AbortController(); downloadingId.value = document.id;
    try { await download(document.id, downloadControl.signal); }
    catch (error) { if (!aborted(error)) emit('error', failure(error)); }
    finally { downloadingId.value = null; }
}
</script>
<template>
    <section class="space-y-5" aria-labelledby="asset-documents-title">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div><p class="text-sm font-medium uppercase tracking-wide text-teal-700">{{ assetLabel(asset.type) }}</p><h2 id="asset-documents-title" class="mt-1 break-words text-2xl font-semibold">Documentación de {{ asset.code }}</h2></div>
            <div class="flex flex-wrap gap-2"><button class="btn-secondary" :disabled="loading || summaryLoading || saving || typesLoading" @click="refresh">Actualizar</button><button v-if="!formOpen" ref="registerButton" class="btn-primary" :disabled="typesLoading" @click="openForm(null, $event)">{{ typesLoading ? 'Preparando formulario…' : 'Registrar documento' }}</button></div>
        </div>
        <p class="text-sm text-slate-600">El documento actual es la versión seleccionada por el servidor. Su vigencia es una condición independiente.</p>
        <p v-if="success" role="status" class="notice-success">{{ success }}</p>
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-5" :aria-busy="summaryLoading">
            <div v-for="state in states" :key="state" class="rounded-xl border p-4" :class="state === 'expired' ? 'border-red-200 bg-red-50' : state === 'expiring_soon' ? 'border-amber-200 bg-amber-50' : 'border-slate-200 bg-white'">
                <span class="block text-sm text-slate-600">{{ state === 'valid' ? 'Vigentes' : state === 'expiring_soon' ? 'Por vencer · 0–15 días' : state === 'expired' ? 'Vencidos' : 'Sin vencimiento' }}</span><span class="mt-2 block text-2xl font-semibold">{{ counts ? counts[state] : '—' }}</span>
            </div>
            <div class="rounded-xl border border-orange-200 bg-orange-50 p-4"><span class="block text-sm text-slate-600">Obligatorios faltantes</span><span class="mt-2 block text-2xl font-semibold">{{ missing?.meta.total ?? '—' }}</span></div>
        </div>
        <p v-if="summaryLoading" role="status" class="text-sm text-slate-600">Actualizando indicadores…</p>
        <p v-if="summaryFailed" class="text-sm text-red-700">No fue posible consultar los indicadores de vigencia. Usa Actualizar para volver a consultar.</p>
        <DocumentUploadForm v-if="formOpen" :asset="asset" :types="types" :renewal="renewal" :initial-type-id="initialTypeId" @saved="saved" @cancel="closeForm" @error="emit('error', $event)" @busy="busy" />
        <section class="panel" aria-labelledby="document-list-title" :aria-busy="loading">
            <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
                <div class="flex flex-wrap gap-2" aria-label="Vista documental"><button class="btn-secondary" :class="!history ? 'bg-teal-50 text-teal-900' : ''" :aria-pressed="!history" :disabled="saving || typesLoading" @click="viewCurrent">Documentos actuales</button><button class="btn-secondary" :class="history ? 'bg-teal-50 text-teal-900' : ''" :aria-pressed="history" :disabled="saving || typesLoading" @click="viewHistory()">Historial completo</button></div>
                <div><label for="validity-filter" class="mr-2 text-sm font-medium">Vigencia</label><select id="validity-filter" class="field inline-block w-auto" :value="validity" :disabled="saving || typesLoading" @change="filter(($event.target as HTMLSelectElement).value as Validity | '')"><option value="">Todas</option><option v-for="state in states" :key="state" :value="state">{{ validityLabels[state] }}</option></select></div>
            </div>
            <h3 id="document-list-title" ref="title" tabindex="-1" class="mb-2 text-lg font-semibold">{{ history ? 'Historial documental' : 'Documentos actuales' }}{{ historyName ? ` · ${historyName}` : '' }}</h3>
            <p v-if="history" class="mb-4 text-sm text-slate-600">Incluye la versión actual y las anteriores. Cada versión conserva sus requisitos al registrarse; no admite edición ni eliminación.</p>
            <button v-if="historyType" class="btn-text mb-3" :disabled="saving" @click="viewHistory()">Ver todos los tipos en el historial</button>
            <p v-if="loading" role="status" class="py-6 text-slate-600">Cargando documentos…</p>
            <div v-else-if="listFailed"><p class="mb-3">No se pudo consultar la documentación.</p><button class="btn-secondary" @click="load()">Volver a consultar</button></div>
            <p v-else-if="!result?.data.length" class="py-6 text-slate-600">No hay documentos para esta consulta.</p>
            <DocumentList v-else :documents="result.data" :history="history" :busy="saving || typesLoading || formOpen" :downloading-id="downloadingId" @download="downloadFile" @renew="(doc, event) => openForm(doc, event)" @history="viewHistory" />
            <Pagination v-if="result" class="mt-4" :meta="result.meta" :busy="loading || saving || typesLoading" label="Páginas de documentos" @change="load" />
        </section>
        <section class="panel" aria-labelledby="missing-title">
            <h3 id="missing-title" class="text-lg font-semibold">Documentos obligatorios faltantes</h3>
            <p class="mb-4 mt-1 text-sm text-slate-600">Falta una versión actual de estos tipos. Un documento vencido se muestra como vencido, no como ausente.</p>
            <div v-if="!missing"><p class="text-sm text-slate-600">Requisitos pendientes de consulta.</p><button class="btn-text mt-2" :disabled="saving" @click="loadMissing()">Consultar requisitos</button></div>
            <p v-else-if="!missing.data.length" class="text-sm text-emerald-800">No faltan documentos obligatorios.</p>
            <ul v-else class="divide-y divide-slate-100"><li v-for="type in missing.data" :key="type.id" class="flex flex-wrap items-center justify-between gap-3 py-3"><span class="font-medium">{{ type.name }}</span><button class="btn-secondary" :disabled="saving || typesLoading || formOpen" :aria-label="`Registrar ${type.name}`" @click="openForm(null, $event, type.id)">Registrar</button></li></ul>
            <Pagination v-if="missing" class="mt-4" :meta="missing.meta" :busy="saving || typesLoading" label="Páginas de requisitos faltantes" @change="loadMissing" />
        </section>
    </section>
</template>
