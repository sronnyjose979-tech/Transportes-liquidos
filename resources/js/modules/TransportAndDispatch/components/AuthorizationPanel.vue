<script setup lang="ts">
import { ref } from 'vue';
import type { Authorization } from '../contracts';
import { write } from '../api';
import { useTransport } from '../context';
import { date, labels } from '../presentation';
import CommandForm from './CommandForm.vue';
const props = defineProps<{
    authorizations: Authorization[];
    refresh: () => Promise<void>;
}>();
const ctx = useTransport();
const selected = ref<Authorization | null>(null);
async function decide(v: Record<string, string>): Promise<void> { if (!selected.value)
    return; await write(`/authorizations/${selected.value.id}/decisions`, v); selected.value = null; await props.refresh(); }
</script>
<template>
<section class="space-y-5">
<p class="notice-info">Una aprobación cubre únicamente sus datos y versión. No garantiza disponibilidad física y no se consume hasta confirmar la operación.</p>
<CommandForm :key="selected?.id" v-if="selected&&ctx.admin.value&&!selected.decision" title="Decidir autorización" :fields="[{key:'decision',label:'Decisión',required:true,options:[{value:'approved',label:'Aprobar'},{value:'rejected',label:'Rechazar'}]},{key:'reason',label:'Motivo de la decisión',type:'textarea',required:true}]" :submit="decide">
<p>{{labels[selected.type]??selected.type}} · {{selected.reason}}</p>
<pre class="whitespace-pre-wrap break-words text-xs">{{JSON.stringify(selected.content,null,2)}}</pre>
</CommandForm>
<article v-for="item in authorizations" :key="item.id" class="panel">
<h3 class="font-semibold">{{labels[item.type]??item.type}}</h3>
<p class="mt-2">{{item.reason}}</p>
<p class="mt-1 text-sm text-slate-500">Versión {{item.target_version}} · Solicitante {{item.requested_by_id}} · {{date(item.registered_at)}}</p>
<details class="mt-2">
<summary class="btn-text">Datos afectados</summary>
<pre class="whitespace-pre-wrap break-words rounded bg-slate-50 p-3 text-xs">{{JSON.stringify(item.content,null,2)}}</pre>
</details>
<div v-if="item.decision" class="notice-info mt-3">
<p>{{labels[item.decision.decision]}} · {{item.decision.reason}}</p>
<p>Administrador {{item.decision.decided_by_id}} · {{date(item.decision.registered_at)}}</p>
<p>{{item.decision.application?'Aplicada: consumo registrado':'Sin consumir'}}</p>
</div>
<button v-else-if="ctx.admin.value" class="btn-primary mt-3" :disabled="ctx.busy.value" @click="selected=item">Revisar y decidir</button>
<p v-else class="mt-3 text-sm text-amber-800">Pendiente de decisión exclusiva del administrador.</p>
</article>
<p v-if="!authorizations.length" class="panel">No hay solicitudes de autorización en este viaje.</p>
</section>
</template>
