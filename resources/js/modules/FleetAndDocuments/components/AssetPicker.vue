<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { aborted, failure, getAssets } from '../api';
import type { ApiError } from '../api';
import type { FleetAsset, Page } from '../contracts';
import { assetLabel } from '../presentation';
import Pagination from './Pagination.vue';

defineProps<{ selectedId: string | null; locked: boolean }>();
const emit = defineEmits<{ select: [asset: FleetAsset]; error: [error: ApiError] }>();
const result = ref<Page<FleetAsset> | null>(null);
const loading = ref(false);
const failed = ref(false);
let control: AbortController | undefined;
onMounted(() => load(1));
onBeforeUnmount(() => control?.abort());
async function load(number: number): Promise<void> {
    control?.abort();
    const active = new AbortController(); control = active;
    loading.value = true; failed.value = false; result.value = null;
    try { const value = await getAssets(number, active.signal); if (!active.signal.aborted) result.value = value; }
    catch (error) { if (!aborted(error) && !active.signal.aborted) { failed.value = true; emit('error', failure(error)); } }
    finally { if (!active.signal.aborted) loading.value = false; }
}
</script>
<template>
    <section class="panel" aria-labelledby="asset-picker-title" :aria-busy="loading">
        <h2 id="asset-picker-title" class="text-lg font-semibold">Seleccionar activo</h2>
        <p class="mb-4 mt-1 text-sm text-slate-600">Cabezal o cisterna cuya documentación deseas consultar.</p>
        <p v-if="loading" role="status">Cargando activos…</p>
        <div v-else-if="failed"><p class="mb-3">No se pudo consultar la flota.</p><button class="btn-secondary" @click="load(1)">Volver a consultar</button></div>
        <p v-else-if="!result?.data.length" class="text-slate-600">No hay activos registrados.</p>
        <div v-else class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
            <button v-for="asset in result.data" :key="asset.id" class="rounded-lg border px-4 py-3 text-left transition-colors" :class="selectedId === asset.id ? 'border-teal-700 bg-teal-50 text-teal-900 ring-1 ring-teal-700' : 'border-slate-200 hover:border-teal-500 hover:bg-slate-50'" :aria-pressed="selectedId === asset.id" :disabled="locked" @click="emit('select', asset)">
                <span class="block break-words font-semibold">{{ asset.code }}</span><span class="mt-1 block text-sm text-slate-600">{{ assetLabel(asset.type) }}</span>
            </button>
        </div>
        <Pagination v-if="result" class="mt-4" :meta="result.meta" :busy="loading || locked" label="Páginas de activos" @change="load" />
    </section>
</template>
