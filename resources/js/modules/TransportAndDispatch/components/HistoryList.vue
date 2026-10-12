<script setup lang="ts">
import { onMounted, onBeforeUnmount, ref, shallowRef, watch } from 'vue';
import { records } from '../api';
import type { Entry, Page } from '../contracts';
import { useTransport } from '../context';
import Pagination from '../../FleetAndDocuments/components/Pagination.vue';
import { date } from '../presentation';
const props = defineProps<{
    path: string;
    title: string;
    revision: number;
}>();
const ctx = useTransport();
const result = shallowRef<Page<Entry> | null>(null);
const loading = ref(false);
let controller: AbortController | undefined;
async function load(page = 1): Promise<void> { controller?.abort(); const current = new AbortController(); controller = current; loading.value = true; try {
    result.value = await records(props.path, page, current.signal);
}
catch (error) {
    if (!current.signal.aborted)
        await ctx.report(error);
}
finally {
    if (!current.signal.aborted)
        loading.value = false;
} }
onMounted(() => load());
watch(() => [props.path, props.revision], () => load());
onBeforeUnmount(() => controller?.abort());
</script>
<template>
<section class="panel">
<h3 class="text-lg font-semibold">{{title}}</h3>
<p v-if="loading" role="status">Consultando historial…</p>
<template v-else-if="result">
<p v-if="!result.data.length" class="mt-4 text-slate-500">Sin registros.</p>
<article v-for="row in result.data" :key="row.id" class="mt-4 border-t border-slate-200 pt-4">
<p class="font-medium">{{row.action??row.type??row.reading_type??row.code??'Registro'}} · {{date(typeof(row.registered_at??row.captured_at)==='string'?String(row.registered_at??row.captured_at):null)}}</p>
<p>{{row.reason}}</p>
<details class="mt-2">
<summary class="btn-text">Ver datos e historial</summary>
<pre class="overflow-x-auto whitespace-pre-wrap break-words rounded bg-slate-50 p-3 text-xs">{{JSON.stringify(row,null,2)}}</pre>
</details>
</article>
<Pagination :meta="result.meta" :busy="loading||ctx.busy.value" @change="load"label="Historial de registros"/>
</template>
</section>
</template>
