<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { all, request } from '../api';
import { useAdmin } from '../context';
import RoutesPanel from './RoutesPanel.vue';
import FieldError from '../../FleetAndDocuments/components/FieldError.vue';
interface CatalogRecord { id:string; name:string; address?:string; is_active?:boolean }
type Kind = 'customers' | 'liquid-products' | 'operation-sites' | 'routes';
const ctx = useAdmin(), kind = ref<Kind>('customers'), search = ref(''), page = ref(1), rows = ref<CatalogRecord[]>([]);
const loading = ref(false), form = ref(false), current = ref<CatalogRecord|null>(null), name = ref(''), address = ref(''), active = ref(true);
const titles: Record<Kind,string> = { customers:'Clientes', 'liquid-products':'Productos líquidos', 'operation-sites':'Sitios operativos', routes:'Rutas de transporte' };
let generation=0;onBeforeUnmount(()=>generation++);
const filtered = computed(()=>rows.value.filter(r=>`${r.name} ${r.address??''}`.toLocaleLowerCase('es').includes(search.value.trim().toLocaleLowerCase('es'))));
const pages=computed(()=>Math.max(1,Math.ceil(filtered.value.length/10))), visible=computed(()=>filtered.value.slice((page.value-1)*10,page.value*10));
async function load(propagate = false):Promise<void> {const version=++generation;loading.value=true;try{const result=await all<CatalogRecord>(`/catalogs/${kind.value}`);if(version===generation)rows.value=result;}catch(error){if(propagate)throw error;if(version===generation)await ctx.report(error);}finally{if(version===generation)loading.value=false;}}
async function change():Promise<void>{form.value=false;current.value=null;rows.value=[];search.value='';page.value=1;if(kind.value!=='routes')await load();}
async function open(row?:CatalogRecord):Promise<void>{if(ctx.busy.value||ctx.uncertain.value)return;ctx.errors.value={};current.value=null;name.value='';address.value='';active.value=true;if(row){try{current.value=await request<CatalogRecord>(`/catalogs/${kind.value}/${row.id}`);name.value=current.value.name;address.value=current.value.address??'';active.value=current.value.is_active??true;}catch(error){await ctx.report(error);return;}}form.value=true;}
async function save():Promise<void>{await ctx.mutate(current.value?'guardar cambios de catálogo':'crear registro de catálogo',async()=>{const payload={name:name.value,...(kind.value==='operation-sites'?{address:address.value}:{}),...(kind.value==='liquid-products'?{is_active:active.value}:{})};await request(`/catalogs/${kind.value}${current.value?`/${current.value.id}`:''}`,current.value?'PUT':'POST',payload);form.value=false;await load(true);});}
onMounted(()=>load());
</script>
<template>
<section class="space-y-5">
<div class="panel flex flex-wrap gap-4"><label>Catálogo<select aria-label="Catálogo" class="field" v-model="kind" :disabled="ctx.busy.value||ctx.uncertain.value||loading" @change="change"><option v-for="(title,key) in titles" :key="key" :value="key">{{title}}</option></select></label><button v-if="kind!=='routes'" class="btn-primary" :disabled="ctx.busy.value||ctx.uncertain.value||loading" @click="open()">Registrar {{kind==='customers'?'cliente':kind==='liquid-products'?'producto':'sitio'}}</button><button v-if="kind!=='routes'" class="btn-secondary" :disabled="loading||ctx.busy.value" @click="load()">Actualizar catálogo</button></div>
<RoutesPanel v-if="kind==='routes'"/>
<template v-else>
<form v-if="form" class="panel space-y-4" aria-label="Registro de catálogo" @submit.prevent="save"><h2 class="text-lg font-semibold">{{current?'Editar registro':'Nuevo registro'}} · {{titles[kind]}}</h2><fieldset :disabled="ctx.busy.value||ctx.uncertain.value||loading" class="space-y-4"><label class="block">Nombre<input aria-label="Nombre" v-model="name" class="field" required maxlength="255"></label><FieldError id="catalog-name-error" :errors="ctx.errors.value.name"/><label v-if="kind==='operation-sites'" class="block">Dirección<textarea aria-label="Dirección" v-model="address" class="field" required maxlength="4096"/></label><FieldError id="catalog-address-error" :errors="ctx.errors.value.address"/><label v-if="kind==='liquid-products'" class="block"><input v-model="active" type="checkbox"> Producto activo</label><FieldError id="catalog-active-error" :errors="ctx.errors.value.is_active"/><div class="flex gap-3"><button class="btn-primary">Guardar catálogo</button><button type="button" class="btn-secondary" @click="form=false">Cancelar</button></div></fieldset></form>
<section class="panel space-y-4"><h2 class="font-semibold">{{titles[kind]}}</h2><label>Buscar en todos los registros<input aria-label="Buscar catálogo" type="search" class="field" v-model="search" @input="page=1"></label><p v-if="loading" role="status">Consultando catálogo…</p><div v-else class="overflow-x-auto"><table class="data-table"><thead><tr><th>Nombre</th><th>{{kind==='operation-sites'?'Dirección':'Estado'}}</th><th>Acciones</th></tr></thead><tbody><tr v-for="row in visible" :key="row.id"><td>{{row.name}}</td><td>{{kind==='operation-sites'?row.address:kind==='liquid-products'?(row.is_active?'Activo':'Inactivo'):'Registrado'}}</td><td><button class="btn-secondary" :disabled="ctx.busy.value||ctx.uncertain.value" @click="open(row)">Consultar / editar</button></td></tr></tbody></table><p v-if="!filtered.length">Sin registros para esta búsqueda.</p></div><div class="flex flex-wrap items-center gap-3"><button class="btn-secondary" :disabled="page<=1||loading" @click="page--">Anterior</button><p>{{filtered.length}} registros · Página {{page}} de {{pages}}</p><button class="btn-secondary" :disabled="page>=pages||loading" @click="page++">Siguiente</button></div></section>
</template>
</section>
</template>
