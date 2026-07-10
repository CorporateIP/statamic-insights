<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps({
    maxHeight: { type: String, default: '20rem' },
});

const container = ref(null);
const inner = ref(null);
const wrap = ref(null);
const moreBelow = ref(false);

function update() {
    const el = container.value;
    if (!el) return;
    moreBelow.value = el.scrollHeight - el.scrollTop - el.clientHeight > 4;
}

// The fade must blend into the card it sits on; the CP exposes no surface
// variable, so sample the nearest painted ancestor background at mount.
function sampleSurface() {
    let node = wrap.value?.parentElement;

    while (node && node !== document.body) {
        const bg = getComputedStyle(node).backgroundColor;
        if (bg && bg !== 'transparent' && bg !== 'rgba(0, 0, 0, 0)') {
            wrap.value.style.setProperty('--insights-surface', bg);
            return;
        }
        node = node.parentElement;
    }
}

let resizeObserver = null;

onMounted(() => {
    sampleSurface();
    update();

    resizeObserver = new ResizeObserver(update);
    if (inner.value) resizeObserver.observe(inner.value);
});

onBeforeUnmount(() => resizeObserver?.disconnect());
</script>

<template>
    <div ref="wrap" class="insights-scroll-wrap">
        <div ref="container" class="insights-scroll" :style="{ maxHeight }" @scroll.passive="update">
            <div ref="inner">
                <slot />
            </div>
        </div>
        <div class="insights-scroll-fade" :class="{ 'is-visible': moreBelow }" aria-hidden="true"></div>
    </div>
</template>

<style>
.insights-scroll-wrap {
    --insights-surface: #ffffff;
    position: relative;
}

.dark .insights-scroll-wrap {
    --insights-surface: #1f2937;
}

.insights-scroll {
    overflow-y: auto;
    padding-inline-end: 0.875rem;
    scrollbar-width: thin;
    scrollbar-color: transparent transparent;
}

.insights-scroll:hover,
.insights-scroll:focus-within {
    scrollbar-color: rgba(128, 128, 128, 0.35) transparent;
}

/* Fallback for browsers that ignore scrollbar-width/scrollbar-color. */
.insights-scroll::-webkit-scrollbar {
    width: 6px;
}

.insights-scroll::-webkit-scrollbar-track {
    background: transparent;
}

.insights-scroll::-webkit-scrollbar-thumb {
    background: transparent;
    border-radius: 9999px;
}

.insights-scroll:hover::-webkit-scrollbar-thumb {
    background: rgba(128, 128, 128, 0.35);
}

/* Frosted hint that there's more below; melts away at the end of the list. */
.insights-scroll-fade {
    position: absolute;
    inset-inline: 0;
    bottom: 0;
    height: 3rem;
    pointer-events: none;
    opacity: 0;
    transition: opacity 0.3s ease;
    background: linear-gradient(to bottom, transparent, var(--insights-surface));
    -webkit-backdrop-filter: blur(2px);
    backdrop-filter: blur(2px);
    -webkit-mask-image: linear-gradient(to bottom, transparent, black 60%);
    mask-image: linear-gradient(to bottom, transparent, black 60%);
}

.insights-scroll-fade.is-visible {
    opacity: 1;
}

@media (prefers-reduced-motion: reduce) {
    .insights-scroll-fade {
        transition: none;
    }
}
</style>
