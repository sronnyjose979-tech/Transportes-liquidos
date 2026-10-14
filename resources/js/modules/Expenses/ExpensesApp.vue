<script setup lang="ts">
import {computed,onBeforeUnmount,ref,watch} from 'vue';
import type {Session} from '../FleetAndDocuments/contracts';
import {getSession,refreshCsrf} from '../FleetAndDocuments/api';
import {request,failure,ApiError} from '../Administration/api';
import {upload} from './api';
import ExpensesPanel from './ExpensesPanel.vue';
export interface ExpenseIntent {actor:string;roles:string[];title:string;path:string;body:Record<string,unknown>;file:File|null}
const props=defineProps<{session:Session|null}>();const emit=defineEmits<{denied:[error:unknown];lock:[locked:boolean];session:[actor:Session]}>();
const busy=ref(false),pending=ref<ExpenseIntent|null>(null),error=ref(''),success=ref(''),fields=ref<Record<string,string[]>>({}),epoch=ref(0),expired=ref(false);const recovery=new Map<string,ExpenseIntent>();
const office=computed(()=>props.session?.roles.some(role=>['administrator','secretary'].includes(role))??false),admin=computed(()=>props.session?.roles.includes('administrator')??false),locked=computed(()=>busy.value||!!pending.value);
watch(()=>props.session,actor=>{pending.value=actor?recovery.get(actor.id)??null:null;},{immediate:true});watch(locked,value=>emit('lock',value),{immediate:true});
function report(e:unknown):void{const problem=failure(e);error.value=problem.message;fields.value=problem.fields;expired.value=problem.status===419;if([401,403].includes(problem.status))emit('denied',problem);}
async function send(intent:ExpenseIntent):Promise<void>{if(busy.value)return;busy.value=true;error.value='';success.value='';fields.value={};let attempted=false;const recovering=recovery.has(intent.actor);try{const actor=await getSession();emit('session',actor);if(actor.id!==intent.actor||actor.roles.slice().sort().join('|')!==intent.roles.slice().sort().join('|'))throw new ApiError(403,'La identidad o los permisos cambiaron. Consulta los registros con la cuenta original.');attempted=true;if(intent.file)await upload(intent.path,intent.body,intent.file);else await request(intent.path,'POST',intent.body);recovery.delete(intent.actor);pending.value=null;success.value='Operación confirmada. Consulta el estado actualizado.';epoch.value++;}catch(e){const problem=failure(e);if(recovering||(attempted&&(problem.status===0||problem.status>=500||problem.status===419))){recovery.set(intent.actor,intent);if(props.session?.id===intent.actor)pending.value=intent;}else if(attempted){recovery.delete(intent.actor);pending.value=null;}report(problem);}finally{busy.value=false;}}
async function command(title:string,path:string,body:Record<string,unknown>,file:File|null=null):Promise<void>{if(locked.value||!office.value||!props.session)return;if(!window.confirm(`${title}. ¿Confirmas los datos?`))return;await send({actor:props.session.id,roles:[...props.session.roles],title,path,body:{...body,request_key:crypto.randomUUID()},file});}
async function retry():Promise<void>{if(pending.value&&!busy.value&&window.confirm('Reenviar el mismo contenido y comprobante con su clave original.'))await send(pending.value);}
async function inspect():Promise<void>{if(busy.value)return;try{await refreshCsrf();const actor=await getSession();emit('session',actor);pending.value=recovery.get(actor.id)??null;expired.value=false;epoch.value++;}catch(e){report(e);}}
function unload(event:BeforeUnloadEvent):void{if(locked.value){event.preventDefault();event.returnValue='';}}window.addEventListener('beforeunload',unload);onBeforeUnmount(()=>{window.removeEventListener('beforeunload',unload);emit('lock',false);});
</script>
<template><section class="space-y-4"><p class="notice-info">Control administrativo de gastos. Los gastos pendientes no están aprobados. No afectan facturas, pagos ni saldos de clientes. CRC y USD se consultan por separado.</p><p v-if="error" role="alert" class="notice-error">{{error}}</p><ul v-if="Object.keys(fields).length" role="alert" class="notice-error"><template v-for="(messages,key) in fields" :key="key"><li v-for="message in messages" :key="message">{{key}}: {{message}}</li></template></ul><p v-if="success" role="status" class="notice-success">{{success}}</p><button v-if="expired||!session" class="btn-secondary" :disabled="busy" @click="inspect">Comprobar sesión y recuperar operación</button><section v-if="pending&&office" class="panel space-y-3"><h2 class="font-semibold">Operación pendiente de confirmación</h2><p>{{pending.title}}. Una respuesta perdida no demuestra que el gasto falló. No se reenvía automáticamente ni se guarda en el navegador.</p><button class="btn-secondary" :disabled="busy" @click="retry">Reintentar la misma operación</button></section><ExpensesPanel v-if="session&&office" :key="`${session.id}:${session.roles.join('|')}`" :admin="admin" :locked="locked" :epoch="epoch" @command="command" @error="report"/></section></template>

<style scoped>
section :deep(.panel){background:white;border:1px solid #bfdbfe;border-radius:.75rem;padding:1.25rem}
section :deep(.field){display:block;width:100%;min-width:0;border:1px solid #94a3b8;border-radius:.375rem;padding:.65rem;margin:.3rem 0;background:white}
section :deep(label){display:block;overflow-wrap:anywhere}
section :deep(.btn-primary),section :deep(.btn-secondary){display:inline-block;border-radius:.375rem;padding:.65rem 1rem;cursor:pointer}
section :deep(.btn-primary){background:#1d4ed8;color:white}
section :deep(.btn-secondary){background:#eff6ff;color:#1e40af;border:1px solid #93c5fd}
section :deep(button:disabled){opacity:.5;cursor:default}
section :deep(.data-table){width:100%;border-collapse:collapse}
section :deep(th),section :deep(td){padding:.75rem;text-align:left;border-bottom:1px solid #dbeafe}
section :deep(th){background:#eff6ff}
section :deep(.notice-info),section :deep(.notice-success),section :deep(.notice-error){border-radius:.375rem;padding:1rem;overflow-wrap:anywhere}
section :deep(.notice-info){background:#dbeafe}
section :deep(.notice-success){background:#dcfce7}
section :deep(.notice-error){background:#fee2e2;color:#991b1b}
</style>
