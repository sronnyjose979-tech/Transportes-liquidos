<script setup lang="ts">
import {ref,watch,onBeforeUnmount} from 'vue';
import type {Session} from '../FleetAndDocuments/contracts';
import {request} from '../Administration/api';
export interface ModuleLink {key:string;label:string;href:string}
const props=defineProps<{session:Session|null;disabled?:boolean}>();
const currentPath=window.location.pathname;
const links=ref<ModuleLink[]>([]),error=ref('');let generation=0;
watch(()=>[props.session?.id,props.session?.roles.join('|')],async()=>{const current=++generation;links.value=[];error.value='';if(!props.session)return;try{const result=await request<{home:string;modules:ModuleLink[]}>('/navigation');if(current===generation)links.value=result.modules;}catch{if(current===generation)error.value='No se pudo consultar la navegación autorizada.';}},{immediate:true});
onBeforeUnmount(()=>{generation++;});
</script>
<template><nav v-if="session" aria-label="Módulos del sistema" class="flex flex-wrap items-center gap-2 text-sm"><a v-for="link in links" :key="link.key" :href="link.href" class="rounded-md border border-blue-200 bg-white px-3 py-2 text-blue-800 hover:bg-blue-50" :aria-current="link.href===currentPath?'page':undefined" :aria-disabled="disabled" @click="disabled&&$event.preventDefault()">{{link.label}}</a><p v-if="error" role="status">{{error}}</p></nav></template>
