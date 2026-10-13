<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, useId, watch } from 'vue';
import { all, request } from '../api';
import { useAdmin } from '../context';
import FieldError from '../../FleetAndDocuments/components/FieldError.vue';
import SearchSelect from '../../Workshop/components/SearchSelect.vue';

interface TransportRoute {
    id: string;
    name: string;
    origin_site_id: string;
    destination_site_id: string;
    has_requests: boolean;
}
interface OperationSite { id: string; name: string; address: string }
const ctx = useAdmin();
const rows = ref<TransportRoute[]>([]), sites = ref<OperationSite[]>([]);
const search = ref(''), page = ref(1), loading = ref(false), opening = ref(false), form = ref(false);
const current = ref<TransportRoute | null>(null), name = ref(''), origin = ref(''), destination = ref('');
const nameId = useId();
let generation = 0, openGeneration = 0;
onBeforeUnmount(() => { generation++; openGeneration++; });
const disabled = computed(() => ctx.busy.value || ctx.uncertain.value || loading.value || opening.value);
const readonly = computed(() => current.value?.has_requests === true);
const options = computed(() => sites.value.map(site => ({ value: site.id, label: `${site.name} · ${site.address}` })));
const siteNames = computed(() => new Map(sites.value.map(site => [site.id, site.name])));
const siteName = (id: string): string => siteNames.value.get(id) ?? id;
const filtered = computed(() => {
    const term = search.value.trim().toLocaleLowerCase('es');
    return rows.value.filter(row => `${row.name} ${siteName(row.origin_site_id)} ${siteName(row.destination_site_id)}`.toLocaleLowerCase('es').includes(term));
});
const pages = computed(() => Math.max(1, Math.ceil(filtered.value.length / 10)));
const visible = computed(() => filtered.value.slice((page.value - 1) * 10, page.value * 10));
watch(search, () => { page.value = 1; });
watch(pages, count => { page.value = Math.min(page.value, count); });

async function load(propagate = false): Promise<void> {
    const version = ++generation;
    loading.value = true;
    try {
        const [routes, operationSites] = await Promise.all([all<TransportRoute>('/catalogs/routes'), all<OperationSite>('/catalogs/operation-sites')]);
        if (version !== generation) return;
        rows.value = routes;
        sites.value = operationSites;
    } catch (error) {
        if (propagate) throw error;
        if (version === generation) await ctx.report(error);
    } finally { if (version === generation) loading.value = false; }
}
async function open(row?: TransportRoute): Promise<void> {
    if (disabled.value) return;
    const version = ++openGeneration;
    ctx.errors.value = {};
    form.value = false;
    current.value = null;
    name.value = ''; origin.value = ''; destination.value = '';
    if (!row) { form.value = true; return; }
    opening.value = true;
    try {
        const result = await request<TransportRoute>(`/catalogs/routes/${row.id}`);
        if (version !== openGeneration) return;
        current.value = result;
        name.value = result.name; origin.value = result.origin_site_id; destination.value = result.destination_site_id;
        form.value = true;
    } catch (error) { if (version === openGeneration) await ctx.report(error); }
    finally { if (version === openGeneration) opening.value = false; }
}
async function save(): Promise<void> {
    if (disabled.value || readonly.value) return;
    await ctx.mutate(current.value ? 'guardar cambios de ruta' : 'crear ruta', async () => {
        const record = current.value;
        const payload = {
            name: name.value, origin_site_id: origin.value, destination_site_id: destination.value,
            ...(record ? { expected: { name: record.name, origin_site_id: record.origin_site_id, destination_site_id: record.destination_site_id } } : {}),
        };
        await request(`/catalogs/routes${record ? `/${record.id}` : ''}`, record ? 'PATCH' : 'POST', payload);
        form.value = false;
        await load(true);
    });
}
onMounted(() => load());
</script>
<template>
    <section class="space-y-5">
        <div class="panel flex flex-wrap items-center gap-3">
            <button class="btn-primary" :disabled="disabled" @click="open()">Registrar ruta</button>
            <button class="btn-secondary" :disabled="disabled" @click="load()">Actualizar rutas</button>
        </div>
        <p class="notice-info">Las rutas utilizan sitios operativos existentes. Las rutas vinculadas a solicitudes se consultan sin editar: su modificación histórica requiere una definición pendiente.</p>
        <p v-if="opening" role="status">Consultando ruta…</p>
        <form v-if="form" class="panel space-y-4" aria-label="Registro de ruta" @submit.prevent="save">
            <h2 class="text-lg font-semibold">{{ readonly ? 'Consultar ruta' : current ? 'Editar ruta' : 'Nueva ruta' }}</h2>
            <p v-if="readonly" class="notice-info">Esta ruta tiene solicitudes asociadas y conserva sus datos históricos. No se permite editarla en esta entrega.</p>
            <fieldset :disabled="disabled || readonly" class="space-y-4">
                <label :for="nameId" class="block">Nombre<input :id="nameId" aria-label="Nombre de ruta" v-model="name" class="field" required maxlength="255" :aria-invalid="!!ctx.errors.value.name?.length" :aria-describedby="ctx.errors.value.name?.length ? `${nameId}-error` : undefined"></label>
                <FieldError :id="`${nameId}-error`" :errors="ctx.errors.value.name" />
                <div class="grid gap-4 md:grid-cols-2">
                    <SearchSelect v-model="origin" label="Origen" :options="options" required :disabled="disabled || readonly" :errors="ctx.errors.value.origin_site_id" />
                    <SearchSelect v-model="destination" label="Destino" :options="options" required :disabled="disabled || readonly" :errors="ctx.errors.value.destination_site_id" />
                </div>
                <FieldError :id="`${nameId}-expected-error`" :errors="ctx.errors.value.expected" />
                <button v-if="!readonly" class="btn-primary">Guardar ruta</button>
            </fieldset>
            <button type="button" class="btn-secondary" :disabled="disabled" @click="form=false">{{ readonly ? 'Cerrar consulta' : 'Cancelar' }}</button>
        </form>
        <section class="panel space-y-4">
            <h2 class="font-semibold">Rutas de transporte</h2>
            <label class="block">Buscar nombre, origen o destino<input aria-label="Buscar rutas" v-model="search" type="search" class="field"></label>
            <p v-if="loading" role="status">Consultando rutas y sitios…</p>
            <div v-else class="overflow-x-auto">
                <table class="data-table"><thead><tr><th>Nombre</th><th>Origen</th><th>Destino</th><th>Uso</th><th>Acciones</th></tr></thead>
                    <tbody><tr v-for="row in visible" :key="row.id"><td>{{ row.name }}</td><td>{{ siteName(row.origin_site_id) }}</td><td>{{ siteName(row.destination_site_id) }}</td><td>{{ row.has_requests ? 'Con solicitudes' : 'Sin solicitudes' }}</td><td><button class="btn-secondary" :disabled="disabled" @click="open(row)">{{ row.has_requests ? 'Consultar' : 'Consultar / editar' }}</button></td></tr></tbody>
                </table>
                <p v-if="!filtered.length">Sin rutas para esta búsqueda.</p>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <button class="btn-secondary" :disabled="page<=1 || loading" @click="page--">Anterior</button>
                <p>{{ filtered.length }} rutas · Página {{ page }} de {{ pages }}</p>
                <button class="btn-secondary" :disabled="page>=pages || loading" @click="page++">Siguiente</button>
            </div>
        </section>
    </section>
</template>
