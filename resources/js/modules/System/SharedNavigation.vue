<script setup lang="ts">
import {computed,ref,watch,onBeforeUnmount} from 'vue';
import type {Session} from '../FleetAndDocuments/contracts';
import {useNavigation} from './useNavigation';
import type {ModuleLink} from './navigation';
export type {ModuleLink} from './navigation';
const navigation=useNavigation();
const props=defineProps<{session:Session|null;disabled?:boolean;workspace?:boolean}>();
const currentPath=window.location.pathname;
const links=ref<ModuleLink[]>([]),error=ref('');let generation=0;
watch(()=>props.session,async actor=>{const current=++generation;links.value=[];error.value='';if(!actor){navigation.clear();return;}try{const result=await navigation.get(actor);if(current===generation)links.value=result.modules;}catch{if(current===generation)error.value='No se pudo consultar la navegación autorizada.';}},{immediate:true,deep:true});
const areas = computed(() => {
    const names: Record<string,string> = { dashboard:'Mi trabajo',transport:'Mi trabajo',driver:'Mi trabajo',
        personnel:'Administración',fleet:'Administración',catalogs:'Administración',
        workshop:'Operación y recursos',documents:'Operación y recursos',alerts:'Operación y recursos',inventory:'Operación y recursos',purchasing:'Operación y recursos',
        billing:'Control y finanzas',expenses:'Control y finanzas',reports:'Control y finanzas',audit:'Control y finanzas' };
    return ['Mi trabajo','Administración','Operación y recursos','Control y finanzas','Otros'].map(label=>({label,items:links.value.filter(link=>(names[link.key]??'Otros')===label)})).filter(area=>area.items.length);
});
onBeforeUnmount(()=>{generation++;});
</script>
<template>
<nav v-if="session && workspace" aria-label="Módulos del sistema" class="workspace-navigation">
    <div class="workspace-brand"><p>Transportes Líquidos</p><span>Espacio de trabajo</span></div>
    <section v-for="area in areas" :key="area.label" class="workspace-area"><h2>{{area.label}}</h2><a v-for="link in area.items" :key="link.key" :href="link.href" :aria-current="link.href===currentPath?'page':undefined" :aria-disabled="disabled" @click="disabled&&$event.preventDefault()">{{link.label}}</a></section>
    <p v-if="error" role="status">{{error}}</p>
</nav>
<nav v-else-if="session" aria-label="Módulos del sistema" class="flex flex-wrap items-center gap-2 text-sm"><a v-for="link in links" :key="link.key" :href="link.href" class="rounded-md border border-blue-200 bg-white px-3 py-2 text-blue-800 hover:bg-blue-50" :aria-current="link.href===currentPath?'page':undefined" :aria-disabled="disabled" @click="disabled&&$event.preventDefault()">{{link.label}}</a><p v-if="error" role="status">{{error}}</p></nav>
</template>
<style>
body:has(.workspace-navigation) .btn-primary {background:#1d4ed8;}
body:has(.workspace-navigation) .btn-primary:hover {background:#1e40af;}
.workspace-navigation {width:100%;min-width:0;display:flex;flex-wrap:wrap;gap:1rem;text-align:left;font-size:.875rem;}
.workspace-brand {display:none;}
.workspace-area {min-width:0;display:flex;flex-wrap:wrap;gap:.4rem;align-content:start;}
.workspace-area h2 {width:100%;font-size:.75rem;font-weight:700;color:#475569;letter-spacing:.025em;}
.workspace-area a {display:flex;align-items:center;min-height:44px;padding:.55rem .8rem;border:1px solid #cbd5e1;border-radius:.5rem;background:white;color:#16324f;overflow-wrap:anywhere;}
.workspace-area a[aria-current=page] {background:#dbeafe;border-color:#2563eb;font-weight:700;}
.workspace-area a[aria-disabled=true] {opacity:.55;cursor:not-allowed;}
@media(min-width:1100px){
 body:has(.workspace-navigation) {padding-left:15.5rem;}
 .workspace-navigation {position:fixed;inset:0 auto 0 0;z-index:30;width:15.5rem;overflow-y:auto;display:flex;flex-direction:column;flex-wrap:nowrap;gap:1.25rem;padding:1.4rem 1rem;background:#102d48;color:white;box-shadow:2px 0 12px #0f172a12;}
 .workspace-brand {display:block;padding:.25rem .5rem 1rem;border-bottom:1px solid #345269;font-weight:700;font-size:1rem;}
 .workspace-brand span {display:block;font-size:.75rem;color:#b6cede;font-weight:400;margin-top:.25rem;}
 .workspace-area {display:flex;flex-direction:column;flex-wrap:nowrap;}
 .workspace-area h2 {padding-left:.75rem;color:#b6cede;}
 .workspace-area a {background:transparent;color:#e8f1f8;border-color:transparent;}
 .workspace-area a:hover {background:#1e4668;}
 .workspace-area a[aria-current=page] {background:#e0efff;color:#102d48;border-color:#e0efff;}
}
</style>
