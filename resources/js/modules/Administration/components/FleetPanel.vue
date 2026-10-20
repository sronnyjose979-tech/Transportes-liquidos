<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useModuleRequests } from '../../Office/useModuleRequests';
const {all,request}=useModuleRequests();
import { useAdmin } from '../context';
import FieldError from '../../FleetAndDocuments/components/FieldError.vue';

interface Compartment { id?: string; code: string; capacity_liters: string; is_active: boolean }
interface Asset { id: string; code: string; type: 'tractor_unit' | 'tanker'; service_status: string; has_physical_partitions?: boolean; total_capacity_liters?: string; compartments?: Compartment[] }
const ctx = useAdmin();
const assets = ref<Asset[]>([]);
const loading = ref(false);
const search = ref('');
const type = ref<'tractor_unit' | 'tanker'>('tractor_unit');
const page = ref(1);
const mode = ref<'list' | 'create' | 'view' | 'edit'>('list');
const selected = ref<Asset | null>(null);
const code = ref('');
const partitions = ref(false);
const compartments = ref<Compartment[]>([]);
let generation = 0;
const disabled = computed(() => ctx.busy.value || ctx.uncertain.value || loading.value);
const filtered = computed(() => assets.value.filter(asset => asset.type === type.value && asset.code.toLocaleLowerCase('es').includes(search.value.trim().toLocaleLowerCase('es'))));
const pages = computed(() => Math.max(1, Math.ceil(filtered.value.length / 15)));
const rows = computed(() => filtered.value.slice((page.value - 1) * 15, page.value * 15));
const endpoint = () => type.value === 'tractor_unit' ? '/tractor-units' : '/tankers';
const states: Record<string, string> = { available: 'Disponible', maintenance: 'Mantenimiento', out_of_service: 'Fuera de servicio' };
watch([search, type], () => { page.value = 1; mode.value = 'list'; selected.value = null; });
watch(partitions, value => { if (!value && mode.value === 'create') compartments.value = compartments.value.slice(0, 1); });
async function load() {
    const current = ++generation;
    loading.value = true;
    try {
        const [tractors, tankers] = await Promise.all([all<Asset>('/tractor-units'), all<Asset>('/tankers')]);
        if (current === generation) { assets.value = [...tractors, ...tankers]; page.value = Math.min(page.value, pages.value); }
    } catch (error) { if (current === generation) await ctx.report(error); }
    finally { if (current === generation) loading.value = false; }
}
function create() {
    selected.value = null; code.value = ''; partitions.value = false;
    compartments.value = [{ code: '', capacity_liters: '', is_active: true }];
    ctx.errors.value = {}; mode.value = 'create';
}
async function open(asset: Asset) {
    const current = ++generation;
    loading.value = true;
    try {
        const result = await request<Asset>(`${asset.type === 'tractor_unit' ? '/tractor-units' : '/tankers'}/${asset.id}`);
        if (current === generation) { selected.value = result; mode.value = 'view'; ctx.errors.value = {}; }
    } catch (error) { if (current === generation) await ctx.report(error); }
    finally { if (current === generation) loading.value = false; }
}
function edit() {
    if (!selected.value) return;
    code.value = selected.value.code;
    partitions.value = selected.value.has_physical_partitions ?? false;
    compartments.value = (selected.value.compartments ?? []).map(compartment => ({ ...compartment }));
    ctx.errors.value = {}; mode.value = 'edit';
}
async function save() {
    if (disabled.value) return;
    const creating = mode.value === 'create';
    const target = `${endpoint()}${creating ? '' : `/${selected.value?.id}`}`;
    const body: Record<string, unknown> = { code: code.value };
    if (type.value === 'tanker') {
        if (creating) body.has_physical_partitions = partitions.value;
        body.compartments = compartments.value.map(compartment => creating
            ? { code: compartment.code, capacity_liters: compartment.capacity_liters, is_active: compartment.is_active }
            : { id: compartment.id, code: compartment.code, capacity_liters: compartment.capacity_liters });
    }
    await ctx.mutate(creating ? 'Registrar activo de flota' : 'Actualizar activo de flota', async () => {
        selected.value = await request<Asset>(target, creating ? 'POST' : 'PATCH', body);
        mode.value = 'view';
        // Propagate refresh failures: the confirmed write must not be repeated automatically.
        const [tractors, tankers] = await Promise.all([all<Asset>('/tractor-units'), all<Asset>('/tankers')]);
        assets.value = [...tractors, ...tankers];
    });
}
onMounted(load);
onBeforeUnmount(() => { generation++; });
</script>

<template>
    <section class="space-y-5" aria-labelledby="fleet-heading">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 id="fleet-heading" class="text-xl font-semibold text-blue-950">Administración de Flota</h2>
            <button class="btn-secondary" :disabled="disabled" @click="load">Actualizar flota</button>
        </div>
        <div class="panel space-y-4">
            <div class="grid gap-3 sm:grid-cols-2">
                <label class="field-label">Tipo de activo<select aria-label="Tipo de activo" v-model="type" class="field" :disabled="disabled"><option value="tractor_unit">Cabezales</option><option value="tanker">Cisternas</option></select></label>
                <label class="field-label">Buscar por código<input aria-label="Buscar por código" :disabled="disabled" v-model="search" class="field" type="search" /></label>
            </div>
            <p v-if="loading" role="status">Consultando flota…</p>
            <template v-if="mode === 'list'">
                <button class="btn-primary !bg-blue-800 hover:!bg-blue-900" :disabled="disabled" @click="create">{{ type === 'tractor_unit' ? 'Registrar cabezal' : 'Registrar cisterna' }}</button>
                <div class="overflow-x-auto">
                    <table class="data-table"><caption class="sr-only">Activos registrados</caption><thead><tr><th>Código</th><th>Estado de servicio</th><th>Acción</th></tr></thead><tbody>
                        <tr v-for="asset in rows" :key="asset.id"><th scope="row">{{ asset.code }}</th><td>{{ states[asset.service_status] ?? asset.service_status }}</td><td><button class="btn-secondary" :disabled="disabled" :aria-label="`Consultar ${asset.code}`" @click="open(asset)">Consultar</button></td></tr>
                    </tbody></table>
                </div>
                <p v-if="!loading && !filtered.length">No hay activos que coincidan con la búsqueda.</p>
                <nav v-if="filtered.length" aria-label="Paginación de flota" class="flex flex-wrap items-center gap-3"><span>Página {{ page }} de {{ pages }} · {{ filtered.length }} registros</span><button class="btn-secondary" :disabled="disabled || page === 1" @click="page--">Anterior</button><button class="btn-secondary" :disabled="disabled || page === pages" @click="page++">Siguiente</button></nav>
            </template>
            <div v-else-if="mode === 'view' && selected" class="space-y-4">
                <h3 class="text-lg font-semibold">{{ selected.code }}</h3>
                <dl class="grid gap-3 sm:grid-cols-2"><div><dt class="font-medium">Estado de servicio</dt><dd>{{ states[selected.service_status] ?? selected.service_status }}</dd></div><div v-if="selected.type === 'tanker'"><dt class="font-medium">Divisiones físicas</dt><dd>{{ selected.has_physical_partitions ? 'Sí' : 'No' }}</dd></div></dl>
                <div v-if="selected.type === 'tanker'" class="overflow-x-auto"><table class="data-table"><caption>Compartimentos · capacidades en litros</caption><thead><tr><th>Código</th><th>Capacidad (L)</th><th>Activo</th></tr></thead><tbody><tr v-for="compartment in selected.compartments" :key="compartment.id"><th scope="row">{{ compartment.code }}</th><td>{{ compartment.capacity_liters }}</td><td>{{ compartment.is_active ? 'Sí' : 'No' }}</td></tr></tbody></table></div>
                <div class="flex gap-3"><button class="btn-primary !bg-blue-800 hover:!bg-blue-900" :disabled="disabled" @click="edit">Editar activo</button><button class="btn-secondary" :disabled="disabled" @click="mode = 'list'">Volver al listado</button></div>
                <p class="text-sm text-slate-600">El estado de servicio se consulta; esta administración no cambia bloqueos de Taller ni asignaciones.</p>
            </div>
            <form v-else class="space-y-4" @submit.prevent="save">
                <h3 class="text-lg font-semibold">{{ mode === 'create' ? 'Nuevo activo' : 'Editar activo' }}</h3>
                <div><label for="fleet-code" class="field-label">Código del activo</label><input id="fleet-code" v-model="code" class="field" required maxlength="255" :disabled="disabled" :aria-invalid="Boolean(ctx.errors.value.code?.length)" aria-describedby="fleet-code-errors" /><FieldError id="fleet-code-errors" :errors="ctx.errors.value.code" /></div>
                <template v-if="type === 'tanker'">
                    <label class="flex items-center gap-2"><input v-model="partitions" type="checkbox" :disabled="disabled || mode !== 'create'" /> Tiene divisiones físicas</label>
                    <FieldError id="fleet-partitions-errors" :errors="ctx.errors.value.has_physical_partitions" />
                    <FieldError id="fleet-compartments-errors" :errors="ctx.errors.value.compartments" />
                    <fieldset v-for="(compartment, index) in compartments" :key="compartment.id ?? index" class="rounded-lg border border-blue-100 p-4"><legend class="px-1 font-medium">Compartimento {{ index + 1 }}</legend><div class="grid gap-3 sm:grid-cols-2">
                        <div><label :for="`fleet-comp-code-${index}`" class="field-label">Código de compartimento</label><input :id="`fleet-comp-code-${index}`" v-model="compartment.code" class="field" required maxlength="255" :disabled="disabled" :aria-invalid="Boolean(ctx.errors.value[`compartments.${index}.code`]?.length)" :aria-describedby="`fleet-comp-code-errors-${index}`" /><FieldError :id="`fleet-comp-code-errors-${index}`" :errors="ctx.errors.value[`compartments.${index}.code`]" /></div>
                        <div><label :for="`fleet-comp-cap-${index}`" class="field-label">Capacidad en litros</label><input :id="`fleet-comp-cap-${index}`" v-model="compartment.capacity_liters" class="field" inputmode="decimal" required pattern="[0-9]+(\.[0-9]{1,2})?" :disabled="disabled" :aria-invalid="Boolean(ctx.errors.value[`compartments.${index}.capacity_liters`]?.length)" :aria-describedby="`fleet-comp-cap-errors-${index}`" /><FieldError :id="`fleet-comp-cap-errors-${index}`" :errors="ctx.errors.value[`compartments.${index}.capacity_liters`]" /></div>
                    </div><p class="mt-2 text-sm text-slate-600">Use punto decimal y hasta dos decimales.</p><label class="mt-3 flex items-center gap-2"><input v-model="compartment.is_active" type="checkbox" :disabled="disabled || mode !== 'create'" /> Compartimento activo</label><FieldError :id="`fleet-comp-active-errors-${index}`" :errors="ctx.errors.value[`compartments.${index}.is_active`]" /><button v-if="mode === 'create' && partitions && compartments.length > 1" type="button" class="btn-secondary mt-3" :disabled="disabled" @click="compartments.splice(index, 1)">Quitar compartimento {{ index + 1 }}</button></fieldset>
                    <button v-if="mode === 'create' && partitions" type="button" class="btn-secondary" :disabled="disabled" @click="compartments.push({ code: '', capacity_liters: '', is_active: true })">Añadir compartimento</button>
                    <p v-if="mode === 'edit'" class="text-sm text-slate-600">Solo se editan códigos y capacidades. La estructura, divisiones y activación se conservan.</p>
                </template>
                <div class="flex flex-wrap gap-3"><button class="btn-primary !bg-blue-800 hover:!bg-blue-900" type="submit" :disabled="disabled">Guardar activo</button><button class="btn-secondary" type="button" :disabled="disabled" @click="mode = selected ? 'view' : 'list'">Cancelar</button></div>
            </form>
        </div>
    </section>
</template>
