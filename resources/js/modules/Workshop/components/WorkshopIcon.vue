<script setup lang="ts">
defineProps<{
    name: string;
}>();
const paths: Record<string, string> = { dashboard: 'M3 3h7v7H3z M14 3h7v7h-7z M3 14h7v7H3z M14 14h7v7h-7z', orders: 'M9 5H5v16h14V5h-4 M9 3h6v4H9z M8 11h8 M8 15h8', reports: 'M12 3 2 21h20L12 3z M12 9v5 M12 17h.01', authorizations: 'M12 3 4 6v6c0 5 8 9 8 9s8-4 8-9V6l-8-3z M8 12l3 3 5-6', impediments: 'M5 5l14 14 M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z' };
</script>
<template>
<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
<path :d="paths[name] ?? paths.orders" />
</svg>
</template>
