<script setup lang="ts">
import {onMounted,onBeforeUnmount,ref} from 'vue';
import {useOffice} from './context';
import { useModuleRequests } from '../Office/useModuleRequests';
import {failure} from '../Administration/api';
const {request}=useModuleRequests();
import WorkOverview from '../System/WorkOverview.vue';
import ExpirationIndicators from '../DocumentAlerts/ExpirationIndicators.vue';
const host=useOffice();
const session=host!.context.value!.session;
const counters=ref<{key:string;label:string;href:string;value:number}[]>([]),error=ref('');
let alive=true;
async function load():Promise<void>{try{const result=await request<{counters:typeof counters.value}>('/dashboard');if(alive)counters.value=result.counters;}catch(e){if(!alive)return;error.value=failure(e).message;if([401,403,419].includes(failure(e).status))host?.invalidate();}}
onMounted(load);onBeforeUnmount(()=>{alive=false;});
</script>
<template><main class="max-w-7xl mx-auto p-6 space-y-5"><p v-if="error" role="alert">{{error}}</p><WorkOverview :session="session"/><section aria-label="Resumen general" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3"><a v-for="counter in counters" :key="counter.key" :href="counter.href" class="panel"><h2>{{counter.label}}</h2><p class="text-3xl font-bold">{{new Intl.NumberFormat('es-CR').format(counter.value)}}</p><span>Consultar registros →</span></a></section><p>Conteos de registros actuales del sistema. No representan ingresos ni indicadores financieros.</p><ExpirationIndicators :session="session"/></main></template>
