<script setup lang="ts">
import { ref, watch, onBeforeUnmount } from 'vue';
import type { Session } from '../FleetAndDocuments/contracts';
import type { ModuleLink } from './SharedNavigation.vue';
import { request } from '../Administration/api';
const props = defineProps<{ session: Session | null; office?: boolean; disabled?: boolean }>();
const links = ref<ModuleLink[]>([]), unavailable = ref(false);
const descriptions: Record<string,string> = {
    personnel:'Registrar personas, preparar accesos y consultar su estado.',
    transport:'Gestionar solicitudes, programar y dar seguimiento a los viajes.',
    documents:'Consultar y renovar la documentación de los vehículos.',
    purchasing:'Dar seguimiento a solicitudes y órdenes de compra.',
    billing:'Consultar importes, facturas internas y pagos registrados.',
    expenses:'Consultar gastos, comprobantes y sus decisiones.',
    alerts:'Revisar documentos vencidos o próximos a vencer.',
    reports:'Consultar resultados administrativos por período.',
};
let generation = 0;
watch(()=>props.session,async actor=>{
    const current=++generation;links.value=[];unavailable.value=false;
    if(!actor)return;
    try { const result=await request<{modules:ModuleLink[]}>('/navigation');
        if(current===generation)links.value=result.modules.filter(link=>link.key in descriptions && (props.office ? ['transport','documents','purchasing','billing','expenses','alerts'].includes(link.key) : true));
    } catch { if(current===generation)unavailable.value=true; }
},{immediate:true});
onBeforeUnmount(()=>{generation++;});
</script>
<template><section v-if="session" class="space-y-4" :aria-label="office?'Mi jornada de oficina':'Accesos rápidos'"><div><h2 class="text-xl font-semibold text-blue-950">{{office?'Mi jornada de oficina':'¿Qué necesitas hacer?'}}</h2><p class="mt-1 text-sm text-slate-600">{{office?'Organiza el trabajo diario desde tus tareas autorizadas.':'Accede a las tareas de gestión. Los conteos del resumen se consultan por separado.'}}</p></div><p v-if="unavailable" role="status">No se pudieron consultar los accesos. Utiliza la navegación o actualiza la página.</p><div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3"><a v-for="link in links" :key="link.key" :href="link.href" class="panel transition-colors hover:border-blue-500 focus-visible:outline-blue-700" :aria-disabled="disabled" @click="disabled&&$event.preventDefault()"><h3 class="font-semibold text-blue-900">{{link.label}}</h3><p class="mt-2 text-sm text-slate-600">{{descriptions[link.key]}}</p><span class="mt-3 inline-block text-sm font-semibold text-blue-800">Abrir {{link.label.toLocaleLowerCase('es')}} →</span></a></div></section></template>
