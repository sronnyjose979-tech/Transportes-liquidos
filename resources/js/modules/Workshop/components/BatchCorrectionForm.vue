<script setup lang="ts">
import {ref} from 'vue';
import SearchSelect from './SearchSelect.vue';
defineProps<{options:{value:string;label:string}[];busy:boolean;cancelDisabled:boolean;errors:Record<string,string[]>}>();
const emit=defineEmits<{submit:[values:Record<string,unknown>];cancel:[]}>();
const choices=ref(['']);
</script>
<template><form aria-label="Aplicar rectificaciones en lote" @submit.prevent="emit('submit',{decisions:[...choices]})"><h2>Aplicar rectificaciones en lote</h2><p>Selecciona las decisiones aprobadas en el orden de aplicación. Cuando una rectificación cambia un costo que cubre otros, selecciona primero su corrección. El servidor valida la relación y confirma todo el lote en una sola transacción.</p><fieldset :disabled="busy"><div v-for="(choice,index) in choices" :key="index"><SearchSelect :model-value="choice" @update:model-value="choices[index]=$event" :label="`Decisión ${index+1}`" :options="options.filter(option=>option.value===choice || !choices.includes(option.value))" required /><button type="button" :disabled="choices.length===1" @click="choices.splice(index,1)">Retirar decisión {{ index+1 }}</button></div><button type="button" :disabled="choices.length>=100 || choices.length>=options.length" @click="choices.push('')">Añadir decisión</button><p v-for="(messages,key) in errors" :key="key" class="workshop-field-errors">{{ messages.join(' ') }}</p></fieldset><div class="workshop-actions"><button :disabled="busy">Guardar</button><button type="button" :disabled="cancelDisabled" @click="emit('cancel')">Cancelar</button></div></form></template>
