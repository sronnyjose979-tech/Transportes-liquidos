<script setup lang="ts">
import type { FleetDocument } from '../contracts';
import { calendarDate, registrationDate } from '../presentation';
import ValidityBadge from './ValidityBadge.vue';
import DocumentActions from './DocumentActions.vue';
import HistoricalConfiguration from './HistoricalConfiguration.vue';
defineProps<{ documents: FleetDocument[]; history: boolean; busy: boolean; downloadingId: string | null }>();
defineEmits<{ download: [document: FleetDocument]; renew: [document: FleetDocument, event: MouseEvent]; history: [document: FleetDocument] }>();
</script>
<template>
    <div class="hidden overflow-x-auto lg:block">
        <table class="data-table min-w-[900px]">
            <caption class="sr-only">{{ history ? 'Historial documental del activo seleccionado' : 'Documentos actuales del activo seleccionado' }}</caption>
            <thead><tr><th scope="col">Documento</th><th scope="col">Referencia</th><th scope="col">Vigencia</th><th scope="col">Emisión / vencimiento</th><th scope="col">Registro</th><th scope="col">Acciones</th></tr></thead>
            <tbody><tr v-for="document in documents" :key="document.id">
                <th scope="row"><span class="block">{{ document.configuration.name }}</span><span class="mt-1 block break-all text-xs font-normal text-slate-500" :title="document.id">{{ document.id.slice(0, 8) }}</span><HistoricalConfiguration v-if="history" :configuration="document.configuration" /></th>
                <td><span :class="document.is_current ? 'font-semibold text-teal-800' : 'text-slate-600'">{{ document.is_current ? 'Actual' : 'Anterior' }}</span></td>
                <td><ValidityBadge :value="document.validity" /></td>
                <td><span class="block">Emisión: {{ calendarDate(document.issued_on) }}</span><span class="mt-1 block">Vence: {{ calendarDate(document.expires_on) }}</span></td>
                <td><span class="block font-medium">{{ document.uploaded_by.name }}</span><span class="mt-1 block text-xs">{{ registrationDate(document.registered_at) }}</span></td>
                <td><DocumentActions :document="document" :history="history" :busy="busy" :downloading="downloadingId === document.id" @download="$emit('download', $event)" @renew="(doc, event) => $emit('renew', doc, event)" @history="$emit('history', $event)" /></td>
            </tr></tbody>
        </table>
    </div>
    <div class="grid gap-4 sm:grid-cols-2 lg:hidden">
        <article v-for="document in documents" :key="document.id" class="rounded-xl border border-slate-200 p-4">
            <div class="flex flex-wrap items-start justify-between gap-2"><h4 class="font-semibold">{{ document.configuration.name }}</h4><ValidityBadge :value="document.validity" /></div>
            <p class="mt-2 text-sm" :class="document.is_current ? 'font-semibold text-teal-800' : 'text-slate-600'">{{ document.is_current ? 'Documento actual' : 'Versión anterior' }}</p>
            <HistoricalConfiguration v-if="history" :configuration="document.configuration" />
            <dl class="my-4 grid grid-cols-2 gap-x-3 gap-y-2 text-sm"><dt class="text-slate-500">Emisión</dt><dd>{{ calendarDate(document.issued_on) }}</dd><dt class="text-slate-500">Vencimiento</dt><dd>{{ calendarDate(document.expires_on) }}</dd><dt class="text-slate-500">Registrado por</dt><dd class="break-words">{{ document.uploaded_by.name }}</dd><dt class="text-slate-500">Fecha de registro</dt><dd>{{ registrationDate(document.registered_at) }}</dd></dl>
            <DocumentActions :document="document" :history="history" :busy="busy" :downloading="downloadingId === document.id" @download="$emit('download', $event)" @renew="(doc, event) => $emit('renew', doc, event)" @history="$emit('history', $event)" />
        </article>
    </div>
</template>
