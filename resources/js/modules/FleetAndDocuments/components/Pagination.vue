<script setup lang="ts">
import type { PageMeta } from '../contracts';
defineProps<{ meta: PageMeta; busy: boolean; label: string }>();
defineEmits<{ change: [page: number] }>();
</script>
<template>
    <nav v-if="meta.total > 0" :aria-label="label" class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 pt-4 text-sm text-slate-600">
        <span>Página {{ meta.current_page }} de {{ meta.last_page }} · {{ meta.total }} registros</span>
        <div class="flex gap-2">
            <button class="btn-secondary" :disabled="busy || meta.current_page <= 1" @click="$emit('change', meta.current_page - 1)">Anterior</button>
            <button class="btn-secondary" :disabled="busy || meta.current_page >= meta.last_page" @click="$emit('change', meta.current_page + 1)">Siguiente</button>
        </div>
    </nav>
</template>
