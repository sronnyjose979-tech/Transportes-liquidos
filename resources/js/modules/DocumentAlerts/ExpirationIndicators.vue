<script setup lang="ts">
import {ref,watch,onBeforeUnmount} from 'vue';
import type {Session} from '../FleetAndDocuments/contracts';
import {failure} from '../Administration/api';
import {useModuleRequests} from '../Office/useModuleRequests';
import {useOffice} from '../Office/context';
const {request}=useModuleRequests();const host=useOffice();
const props=defineProps<{session:Session|null}>();
const summary=ref<{expired:number;expiring_soon:number;unreviewed:number}|null>(null),error=ref('');let generation=0;
watch(()=>[props.session?.id,props.session?.roles.join('|')],async()=>{const current=++generation;summary.value=null;error.value='';if(!props.session?.roles.some(r=>['administrator','secretary'].includes(r)))return;try{const value=await request<{expired:number;expiring_soon:number;unreviewed:number}>('/document-alerts/summary');if(![value.expired,value.expiring_soon,value.unreviewed].every(v=>Number.isInteger(v)&&v>=0))throw new Error('Indicadores incompletos');if(current===generation)summary.value=value;}catch(problem){if(current===generation){error.value='No se pudieron consultar los vencimientos.';if([401,403,419].includes(failure(problem).status))host?.invalidate();}}},{immediate:true});
onBeforeUnmount(()=>generation++);
</script>
<template><aside v-if="summary||error" aria-label="Indicadores de vencimientos" class="panel space-y-2"><h2 class="font-semibold text-blue-900">Vencimientos documentales · información, sin bloqueo automático</h2><div v-if="summary" class="flex flex-wrap gap-4"><a href="/alertas?status=expired" class="text-red-800 underline">Vencidos: {{summary.expired}}</a><a href="/alertas?status=expiring_soon" class="text-amber-900 underline">Próximos a vencer: {{summary.expiring_soon}}</a><a href="/alertas?attention=unreviewed" class="text-blue-800 underline">Sin revisar: {{summary.unreviewed}}</a></div><p v-if="error" role="status">{{error}} <a href="/alertas" class="underline">Consultar alertas</a></p></aside></template>
