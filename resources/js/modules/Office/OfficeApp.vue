<script setup lang="ts">
import { onBeforeUnmount, provide } from 'vue';
import { RouterView } from 'vue-router';
import SharedNavigation from '../System/SharedNavigation.vue';
import { navigationKey } from '../System/navigation';
import { officeKey } from './context';
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
window.addEventListener('beforeunload', props.workspace.beforeUnload);
onBeforeUnmount(() => { window.removeEventListener('beforeunload', props.workspace.beforeUnload); props.workspace.dispose(); });
function reload(): void { window.location.reload(); }
</script>
<template>
<div @click="workspace.click" @input="workspace.input" @change="workspace.input" class="administration-shell">
    <header class="bg-white border-b border-blue-200 p-5">
        <p class="font-semibold">Transportes Líquidos</p>
        <h1 v-if="context?.page === 'dashboard'" class="text-2xl font-bold">Panel general</h1>
        <button v-if="context" type="button" :disabled="pending" @click="workspace.signOut">Cerrar sesión</button>
        <SharedNavigation workspace :session="context?.session ?? null" :disabled="pending"/>
    </header>
    <p v-if="pending" role="status" class="p-4">Consultando destino autorizado…</p>
    <p v-if="error" role="alert" class="notice-error p-4">{{ error }}</p>
    <main v-if="!context" class="p-6"><p>El contenido privado se ha ocultado.</p><a href="/login">Iniciar sesión</a><button type="button" @click="reload">Volver a comprobar</button></main>
    <div id="office-content" tabindex="-1" :inert="pending" :aria-busy="pending">
        <RouterView v-if="context" v-slot="{ Component }"><component :is="Component" :key="context.page"/></RouterView>
    </div>
</div>
</template>
