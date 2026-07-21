<script setup>
import { onBeforeUnmount, ref, watch } from 'vue';
import { fmt } from './locale.js';

const props = defineProps({
    value: { type: Number, required: true },
});

const display = ref(props.value);
let frame = null;

watch(
    () => props.value,
    (to) => {
        cancelAnimationFrame(frame);

        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            display.value = to;
            return;
        }

        const from = display.value;
        const start = performance.now();
        const duration = 500;

        const step = (time) => {
            const progress = Math.min(1, (time - start) / duration);
            const eased = 1 - Math.pow(1 - progress, 3);
            display.value = Math.round(from + (to - from) * eased);
            if (progress < 1) frame = requestAnimationFrame(step);
        };

        frame = requestAnimationFrame(step);
    },
);

onBeforeUnmount(() => cancelAnimationFrame(frame));
</script>

<template>
    <span v-text="fmt(display)" />
</template>
