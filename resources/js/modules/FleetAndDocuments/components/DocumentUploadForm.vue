<script setup lang="ts">
import { computed, nextTick, onMounted, ref } from 'vue';
import { failure, upload } from '../api';
import type { ApiError } from '../api';
import type { DocumentType, FieldErrors, FleetAsset, FleetDocument } from '../contracts';
import FieldError from './FieldError.vue';

const props = defineProps<{ asset: FleetAsset; types: DocumentType[]; renewal: FleetDocument | null; initialTypeId: string }>();
const emit = defineEmits<{ saved: []; cancel: []; error: [error: ApiError]; busy: [value: boolean] }>();
const typeId = ref(props.renewal?.document_type_id ?? props.initialTypeId);
const selectedType = computed(() => props.types.find((type) => type.id === typeId.value));
const issuedOn = ref('');
const expiresOn = ref('');
const fileInput = ref<HTMLInputElement | null>(null);
const firstField = ref<HTMLSelectElement | null>(null);
const busy = ref(false);
const errors = ref<FieldErrors>({});
const message = ref('');
onMounted(async () => { await nextTick(); (props.renewal ? fileInput.value : firstField.value)?.focus(); });
async function submit(): Promise<void> {
    if (busy.value) return;
    const file = fileInput.value?.files?.[0];
    if (!file) return;
    errors.value = {};
    message.value = '';
    busy.value = true;
    emit('busy', true);
    const body = new FormData();
    if (!props.renewal) body.append('document_type_id', typeId.value);
    body.append('file', file);
    if (issuedOn.value) body.append('issued_on', issuedOn.value);
    if (expiresOn.value) body.append('expires_on', expiresOn.value);
    try {
        await upload(props.asset.id, body, props.renewal?.id);
        emit('saved');
    } catch (error) {
        const problem = failure(error);
        errors.value = problem.fields;
        message.value = problem.message;
        emit('error', problem);
    } finally { busy.value = false; emit('busy', false); }
}
</script>
<template>
    <section class="panel border-teal-200 bg-teal-50/40" aria-labelledby="upload-title">
        <div class="mb-5 flex flex-wrap items-start justify-between gap-3">
            <div><h3 id="upload-title" class="text-lg font-semibold">{{ renewal ? 'Renovar documento' : 'Registrar documento' }}</h3>
                <p class="mt-1 text-sm text-slate-600">{{ asset.code }} · Se creará una nueva versión actual; el historial se conserva.</p></div>
            <button class="btn-secondary" :disabled="busy" @click="emit('cancel')">Cancelar</button>
        </div>
        <p v-if="message" role="alert" class="notice-error mb-4">{{ message }}</p>
        <form :aria-busy="busy" @submit.prevent="submit">
            <fieldset :disabled="busy" class="grid gap-5 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label for="upload-type" class="field-label">Tipo documental</label>
                    <select v-if="!renewal" id="upload-type" ref="firstField" v-model="typeId" class="field" required :aria-invalid="!!errors.document_type_id" aria-describedby="upload-type-error">
                        <option value="" disabled>Selecciona un tipo</option>
                        <option v-for="type in types" :key="type.id" :value="type.id">{{ type.name }}</option>
                    </select>
                    <p v-else id="upload-type" class="font-medium">{{ selectedType?.name ?? renewal.configuration.name }}</p>
                    <FieldError id="upload-type-error" :errors="errors.document_type_id" />
                    <p class="mt-2 text-sm text-slate-600">Requisito actual: {{ selectedType?.tracks_expiration ? 'vencimiento obligatorio' : 'sin vencimiento obligatorio' }}.</p>
                </div>
                <div class="sm:col-span-2">
                    <label for="upload-file" class="field-label">Archivo documental</label>
                    <input id="upload-file" ref="fileInput" class="field file:mr-3 file:rounded-md file:border-0 file:bg-slate-100 file:px-3 file:py-1" type="file" accept=".pdf,.jpg,.png" required :aria-invalid="!!errors.file" aria-describedby="upload-file-help upload-file-error">
                    <p id="upload-file-help" class="mt-1 text-sm text-slate-600">PDF, JPG o PNG. Máximo 10.000.000 bytes por archivo. Almacenamiento privado.</p>
                    <FieldError id="upload-file-error" :errors="errors.file" />
                </div>
                <div>
                    <label for="upload-issued" class="field-label">Fecha de emisión <span class="font-normal text-slate-500">(opcional)</span></label>
                    <input id="upload-issued" v-model="issuedOn" class="field" type="date" :aria-invalid="!!errors.issued_on" aria-describedby="upload-issued-error">
                    <FieldError id="upload-issued-error" :errors="errors.issued_on" />
                </div>
                <div>
                    <label for="upload-expires" class="field-label">Fecha de vencimiento <span v-if="!selectedType?.tracks_expiration" class="font-normal text-slate-500">(opcional)</span></label>
                    <input id="upload-expires" v-model="expiresOn" class="field" type="date" :required="selectedType?.tracks_expiration" :aria-invalid="!!errors.expires_on" aria-describedby="upload-expires-error">
                    <FieldError id="upload-expires-error" :errors="errors.expires_on" />
                </div>
                <FieldError id="upload-document-error" class="sm:col-span-2" :errors="errors.document" />
                <div class="sm:col-span-2"><button class="btn-primary" :disabled="busy || !selectedType" type="submit">{{ busy ? 'Guardando documento…' : renewal ? 'Guardar renovación' : 'Guardar documento' }}</button></div>
            </fieldset>
        </form>
    </section>
</template>
