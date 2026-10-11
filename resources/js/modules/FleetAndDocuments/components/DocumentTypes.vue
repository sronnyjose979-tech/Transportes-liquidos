<script setup lang="ts">
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { aborted, failure, getTypes, saveType } from '../api';
import type { ApiError } from '../api';
import type { DocumentType, FieldErrors, Page, TypeConfiguration } from '../contracts';
import Pagination from './Pagination.vue';
import FieldError from './FieldError.vue';

const props = defineProps<{ canManage: boolean }>();
const emit = defineEmits<{ error: [error: ApiError]; busy: [value: boolean] }>();
const result = ref<Page<DocumentType> | null>(null);
const loading = ref(false);
const saving = ref(false);
const failed = ref(false);
const formOpen = ref(false);
const editingId = ref<string | undefined>();
const nameInput = ref<HTMLInputElement | null>(null);
const trigger = ref<HTMLButtonElement | null>(null);
const createButton = ref<HTMLButtonElement | null>(null);
const title = ref<HTMLHeadingElement | null>(null);
const form = ref<TypeConfiguration>({ name: '', tracks_expiration: false, required_for_tractor_units: false, required_for_tankers: false });
const errors = ref<FieldErrors>({});
const formMessage = ref('');
const success = ref('');
let control: AbortController | undefined;
onBeforeUnmount(() => control?.abort());
onMounted(() => load(1));
async function load(number: number): Promise<void> {
    control?.abort();
    const active = new AbortController();
    control = active;
    loading.value = true;
    failed.value = false;
    result.value = null;
    try { const value = await getTypes(number, active.signal); if (!active.signal.aborted) result.value = value; }
    catch (error) { if (!aborted(error) && !active.signal.aborted) { failed.value = true; emit('error', failure(error)); } }
    finally { if (!active.signal.aborted) loading.value = false; }
}
async function open(type?: DocumentType, event?: MouseEvent): Promise<void> {
    if (!props.canManage || saving.value) return;
    trigger.value = event?.currentTarget instanceof HTMLButtonElement ? event.currentTarget : null;
    editingId.value = type?.id;
    form.value = type ? { name: type.name, tracks_expiration: type.tracks_expiration, required_for_tractor_units: type.required_for_tractor_units, required_for_tankers: type.required_for_tankers }
        : { name: '', tracks_expiration: false, required_for_tractor_units: false, required_for_tankers: false };
    errors.value = {};
    formMessage.value = '';
    success.value = '';
    formOpen.value = true;
    await nextTick(); nameInput.value?.focus();
}
async function close(): Promise<void> { formOpen.value = false; await nextTick(); (trigger.value?.isConnected ? trigger.value : createButton.value)?.focus(); }
async function submit(): Promise<void> {
    if (saving.value || !props.canManage) return;
    saving.value = true; emit('busy', true);
    errors.value = {}; formMessage.value = ''; success.value = '';
    try {
        await saveType(form.value, editingId.value);
        success.value = editingId.value ? 'Configuración actualizada. Los documentos históricos conservan sus requisitos.' : 'Tipo documental creado.';
        await close();
        await load(1);
        await nextTick(); title.value?.focus();
    } catch (error) { const problem = failure(error); errors.value = problem.fields; formMessage.value = problem.message; emit('error', problem); }
    finally { saving.value = false; emit('busy', false); }
}
</script>
<template>
    <section class="space-y-5" aria-labelledby="types-title">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div><h2 id="types-title" ref="title" tabindex="-1" class="text-2xl font-semibold">Tipos documentales</h2><p class="mt-1 text-sm text-slate-600">Requisitos actuales para cabezales y cisternas.</p></div>
            <button v-if="canManage && !formOpen" ref="createButton" class="btn-primary" @click="open(undefined, $event)">Crear tipo documental</button>
        </div>
        <p v-if="!canManage" class="notice-info">Puedes consultar los tipos. Su configuración corresponde al administrador.</p>
        <p v-if="success" role="status" class="notice-success">{{ success }}</p>
        <section v-if="formOpen && canManage" class="panel" aria-labelledby="type-form-title">
            <h3 id="type-form-title" class="mb-4 text-lg font-semibold">{{ editingId ? 'Modificar tipo documental' : 'Crear tipo documental' }}</h3>
            <p class="mb-4 text-sm text-slate-600">Los cambios se aplican a registros futuros. La configuración histórica permanece intacta.</p>
            <p v-if="formMessage" role="alert" class="notice-error mb-4">{{ formMessage }}</p>
            <form :aria-busy="saving" @submit.prevent="submit">
                <fieldset :disabled="saving" class="space-y-4">
                    <div><label for="type-name" class="field-label">Nombre</label><input id="type-name" ref="nameInput" v-model="form.name" class="field" required maxlength="255" :aria-invalid="!!errors.name" aria-describedby="type-name-error"><FieldError id="type-name-error" :errors="errors.name" /></div>
                    <div v-for="item in ([['tracks_expiration', 'Requiere vencimiento'], ['required_for_tractor_units', 'Obligatorio para cabezales'], ['required_for_tankers', 'Obligatorio para cisternas']] as const)" :key="item[0]">
                        <label class="flex items-center gap-3"><input v-model="form[item[0]]" type="checkbox" class="h-5 w-5 accent-teal-700" :aria-invalid="!!errors[item[0]]" :aria-describedby="`${item[0]}-error`">{{ item[1] }}</label>
                        <FieldError :id="`${item[0]}-error`" :errors="errors[item[0]]" />
                    </div>
                    <div class="flex flex-wrap gap-3"><button class="btn-primary" type="submit" :disabled="saving">{{ saving ? 'Guardando…' : 'Guardar tipo' }}</button><button class="btn-secondary" type="button" :disabled="saving" @click="close">Cancelar</button></div>
                </fieldset>
            </form>
        </section>
        <div class="panel" :aria-busy="loading">
            <p v-if="loading" role="status" class="py-6 text-slate-600">Cargando tipos documentales…</p>
            <div v-else-if="failed"><p class="mb-3">No se pudo cargar el listado.</p><button class="btn-secondary" @click="load(1)">Volver a consultar</button></div>
            <p v-else-if="!result?.data.length" class="py-6 text-slate-600">Todavía no hay tipos documentales.</p>
            <div v-else class="overflow-x-auto"><table class="data-table"><caption class="sr-only">Tipos documentales y requisitos actuales</caption><thead><tr><th scope="col">Nombre</th><th scope="col">Vencimiento</th><th scope="col">Cabezal</th><th scope="col">Cisterna</th><th v-if="canManage" scope="col">Acciones</th></tr></thead>
                <tbody><tr v-for="type in result.data" :key="type.id"><th scope="row">{{ type.name }}</th><td>{{ type.tracks_expiration ? 'Obligatorio' : 'Opcional' }}</td><td>{{ type.required_for_tractor_units ? 'Obligatorio' : 'Opcional' }}</td><td>{{ type.required_for_tankers ? 'Obligatorio' : 'Opcional' }}</td><td v-if="canManage"><button class="btn-secondary" :aria-label="`Modificar ${type.name}`" :disabled="saving" @click="open(type, $event)">Modificar</button></td></tr></tbody>
            </table></div>
            <Pagination v-if="result" class="mt-4" :meta="result.meta" :busy="loading || saving" label="Páginas de tipos documentales" @change="load" />
        </div>
    </section>
</template>
