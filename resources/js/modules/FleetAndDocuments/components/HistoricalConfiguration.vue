<script setup lang="ts">
import type { TypeConfiguration } from '../contracts';
defineProps<{ configuration: TypeConfiguration }>();
</script>
<template>
    <details class="mt-3 text-xs text-slate-600">
        <summary class="cursor-pointer rounded font-medium text-teal-800 focus-visible:outline-2 focus-visible:outline-teal-700">Requisitos al registro</summary>
        <dl class="mt-2 space-y-1">
            <div><dt class="inline">Vencimiento: </dt><dd class="inline">{{ configuration.tracks_expiration ? 'obligatorio' : 'opcional' }}</dd></div>
            <div><dt class="inline">Cabezal: </dt><dd class="inline">{{ configuration.required_for_tractor_units ? 'obligatorio' : 'opcional' }}</dd></div>
            <div><dt class="inline">Cisterna: </dt><dd class="inline">{{ configuration.required_for_tankers ? 'obligatorio' : 'opcional' }}</dd></div>
        </dl>
    </details>
</template>
