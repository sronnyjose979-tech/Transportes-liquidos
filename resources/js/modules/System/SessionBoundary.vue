<script setup lang="ts">
import { onBeforeUnmount, provide, ref } from 'vue';
import { watchSession } from './sessionIdentity';
import { request } from '../Administration/api';
import { createNavigationLoader, navigationKey } from './navigation';
import type { Navigation } from './navigation';
const navigation = createNavigationLoader(() => request<Navigation>('/navigation'));
provide(navigationKey, navigation);
const checking = ref(false), changed = ref(false);
const stop = watchSession(value => { checking.value = value.checking; changed.value = value.changed; if(value.changed)navigation.clear(); });
onBeforeUnmount(stop);
function reload(): void { window.location.reload(); }
</script>
<template>
    <main v-if="changed" class="mx-auto max-w-xl space-y-4 p-6" role="alert">
        <h1 class="text-2xl font-semibold">La sesión cambió en este navegador</h1>
        <p>El contenido anterior se ha ocultado. Las pestañas del mismo perfil comparten la sesión.</p>
        <p>Vuelve a cargar para consultar la cuenta actual. Si había una operación enviada, comprueba su resultado antes de repetirla. Las capturas offline pendientes se conservan para su cuenta original.</p>
        <button type="button" class="rounded border px-4 py-3" @click="reload">Volver a cargar</button>
        <a class="ml-4 underline" href="/login">Abrir acceso</a>
    </main>
    <template v-else>
        <p v-if="checking" role="status" class="p-6">Comprobando la sesión del navegador…</p>
        <div v-show="!checking" :inert="checking"><slot /></div>
    </template>
</template>
