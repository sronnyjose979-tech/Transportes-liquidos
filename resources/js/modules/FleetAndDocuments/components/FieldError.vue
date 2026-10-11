<script setup lang="ts">
defineProps<{ id: string; errors?: string[] | undefined }>();
</script>
<template><p v-if="errors?.length" :id="id" class="mt-1 text-sm text-red-700">{{ errors.join(' ') }}</p></template>
