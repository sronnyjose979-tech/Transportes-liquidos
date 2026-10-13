<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue';
defineProps<{
    title: string;
}>();
const emit = defineEmits<{
    cancel: [
    ];
}>(), root = ref<HTMLElement | null>(null);
let previous: HTMLElement | null = null, previousOverflow = '';
const focusable = () => Array.from(root.value?.querySelectorAll<HTMLElement>('button:not(:disabled), input:not(:disabled), select:not(:disabled), textarea:not(:disabled), [tabindex="0"]') ?? []).filter(e => e.getClientRects().length);
function key(event: KeyboardEvent): void { if (event.key === 'Escape') {
    emit('cancel');
    return;
} if (event.key !== 'Tab')
    return; const nodes = focusable(), first = nodes[0], last = nodes.at(-1); if (!first) {
    event.preventDefault();
    root.value?.focus();
}
else if (event.shiftKey && (document.activeElement === first || document.activeElement === root.value)) {
    event.preventDefault();
    last?.focus();
}
else if (!event.shiftKey && document.activeElement === last) {
    event.preventDefault();
    first.focus();
} }
onMounted(() => { previous = document.activeElement instanceof HTMLElement ? document.activeElement : null; previousOverflow = document.body.style.overflow; document.body.style.overflow = 'hidden'; (focusable()[0] ?? root.value)?.focus(); });
onUnmounted(() => { document.body.style.overflow = previousOverflow; previous?.focus(); });
</script>
<template>
<div class="workshop-modal-backdrop">
<section ref="root" class="workshop-modal" role="dialog" aria-modal="true" :aria-label="title" tabindex="-1" @keydown="key">
<slot />
</section>
</div>
</template>
