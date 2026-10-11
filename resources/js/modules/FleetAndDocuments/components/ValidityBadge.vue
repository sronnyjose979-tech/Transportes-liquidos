<script setup lang="ts">
import type { Validity } from '../contracts';
import { validityLabels } from '../presentation';
defineProps<{ value: Validity }>();
const classes: Record<Validity, string> = { valid: 'bg-emerald-50 text-emerald-800 ring-emerald-200', expiring_soon: 'bg-amber-50 text-amber-900 ring-amber-200', expired: 'bg-red-50 text-red-800 ring-red-200', no_expiration: 'bg-slate-100 text-slate-700 ring-slate-200' };
</script>
<template><span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset" :class="classes[value]">{{ validityLabels[value] }}</span></template>
