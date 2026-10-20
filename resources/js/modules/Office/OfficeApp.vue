<script setup lang="ts">
import { onBeforeUnmount, provide, ref, watch } from 'vue';
import { RouterView } from 'vue-router';
import SharedNavigation from '../System/SharedNavigation.vue';
import { navigationKey } from '../System/navigation';
import { officeKey, officeTitles } from './context';
import {receivesSession} from './pages';
import {rememberSession} from '../System/sessionIdentity';
import {failure} from '../Administration/api';
import type { createOfficeWorkspace } from './workspace';
const props = defineProps<{ workspace: ReturnType<typeof createOfficeWorkspace> }>();
const { context, pending, error, host } = props.workspace;
provide(officeKey, host);
provide(navigationKey, {
    get: async actor => {
        if (!context.value || actor.id !== context.value.session.id) throw new Error('La sesión cambió.');
        return context.value.navigation;
    },
    clear: () => {}, // The only snapshot is the current server-validated route context.
});
const childLocked=ref(false);
const stopBlocker=host.registerBlocker(()=>childLocked.value);
watch(()=>context.value?.page,()=>{childLocked.value=false;},{flush:'sync'});
onBeforeUnmount(()=>{stopBlocker();props.workspace.dispose();});
function bindings():Record<string,unknown>{
    const current=context.value;
    if(!current||!receivesSession.has(current.page))return {};
    const active=()=>context.value===current;
    return {session:current.session,onLock:(value:boolean)=>{if(active())childLocked.value=value;},
        onDenied:(problem:unknown)=>{if(active()&&[401,403,419].includes(failure(problem).status))host.invalidate();},
        onSession:(actor:typeof current.session)=>{if(active()){try{rememberSession(actor);context.value={...current,session:actor};}catch{host.invalidate();}}}};
}
function retry():void {host.navigate(location.pathname+location.search);}
</script>
<template>
<div @click="workspace.click" @input="workspace.input" @change="workspace.input" class="administration-shell">
    <header class="bg-white border-b border-blue-200 p-5">
        <p class="font-semibold">Transportes Líquidos</p>
        <h1 v-if="context && (context.page==='dashboard'||receivesSession.has(context.page))" class="text-2xl font-bold">{{officeTitles[context.page]}}</h1>
        <button v-if="context" type="button" :disabled="pending||workspace.blocked.value" @click="workspace.signOut">Cerrar sesión</button>
        <SharedNavigation workspace :session="context?.session ?? null" :disabled="pending||workspace.blocked.value"/>
    </header>
    <p v-if="pending" role="status" class="p-4">Consultando destino autorizado…</p>
    <p v-if="error" role="alert" class="notice-error p-4">{{ error }}</p>
    <main v-if="!context" class="p-6"><p>El contenido privado se ha ocultado.</p><a href="/login">Iniciar sesión</a><button type="button" @click="retry">Volver a comprobar</button></main>
    <div id="office-content" tabindex="-1" :inert="pending" :aria-busy="pending">
        <RouterView v-if="context" v-slot="{ Component }"><component :is="Component" :key="context.page" v-bind="bindings()"/></RouterView>
    </div>
</div>
</template>
