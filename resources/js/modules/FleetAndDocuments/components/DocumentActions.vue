<script setup lang="ts">
import type { FleetDocument } from '../contracts';
defineProps<{ document: FleetDocument; history: boolean; busy: boolean; downloading: boolean }>();
defineEmits<{ download: [document: FleetDocument]; renew: [document: FleetDocument, event: MouseEvent]; history: [document: FleetDocument] }>();
</script>
<template>
    <div class="flex flex-wrap gap-2">
        <button class="btn-secondary" :disabled="busy || downloading" :aria-label="`Descargar ${document.configuration.name}`" @click="$emit('download', document)">{{ downloading ? 'Descargando…' : 'Descargar' }}</button>
        <template v-if="!history">
            <button class="btn-secondary" :disabled="busy" :aria-label="`Renovar ${document.configuration.name}`" @click="$emit('renew', document, $event)">Renovar</button>
            <button class="btn-text" :disabled="busy" :aria-label="`Historial de ${document.configuration.name}`" @click="$emit('history', document)">Historial</button>
        </template>
    </div>
</template>
