<script setup lang="ts">
import {computed} from 'vue';
import type {Row} from './contracts';
import {labels} from './contracts';
import {registrationDate} from '../FleetAndDocuments/presentation';
const props=defineProps<{rows:Row[];caption:string}>();
const columns=computed(()=>Object.keys(props.rows[0]??{}));
function format(key:string,value:Row[string]):string {
 if(value===null)return 'Sin registro';
 if(typeof value==='boolean')return value?'Sí':'No';
 if((key==='date'||key.endsWith('_at'))&&typeof value==='string')return registrationDate(value);
 return ['status','planning_status','administrative_status','priority'].includes(key)?labels[String(value)]??String(value):String(value);
}
</script>
<template><div class="overflow-x-auto rounded border border-blue-200 bg-white"><table v-if="rows.length" class="data-table"><caption class="p-3 text-left font-semibold text-blue-900">{{caption}}</caption><thead><tr><th v-for="column in columns" :key="column" scope="col">{{labels[column]??column}}</th></tr></thead><tbody><tr v-for="(row,index) in rows" :key="String(row.id??index)"><td v-for="column in columns" :key="column" :class="column==='id'||column.endsWith('_id')?'max-w-64 break-all':''">{{format(column,row[column]??null)}}</td></tr></tbody></table><p v-else class="p-4">{{caption}}: sin registros para estos filtros.</p></div></template>
