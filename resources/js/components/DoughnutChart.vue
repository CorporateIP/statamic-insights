<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Chart } from 'chart.js/auto';
import { palette, paletteHover, prefersReducedMotion, surfaceColor, tickColor, tooltipOptions } from './theme.js';

const props = defineProps({
    items: { type: Array, required: true }, // [{ label, count }]
    heightClass: { type: String, default: 'h-52' },
});

const canvas = ref(null);
let chart = null;

function render() {
    chart?.destroy();

    chart = new Chart(canvas.value, {
        type: 'doughnut',
        data: {
            labels: props.items.map((item) => item.label),
            datasets: [
                {
                    data: props.items.map((item) => item.count),
                    backgroundColor: palette(props.items.length),
                    hoverBackgroundColor: paletteHover(props.items.length),
                    // 2px surface gap between slices - doubles as the colorblind
                    // secondary cue alongside the legend.
                    borderColor: surfaceColor(),
                    borderWidth: 2,
                    hoverBorderColor: surfaceColor(),
                    hoverBorderWidth: 2,
                    hoverOffset: 6,
                },
            ],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            ...(prefersReducedMotion() && { animation: false }),
            cutout: '68%',
            plugins: {
                tooltip: tooltipOptions(),
                legend: {
                    position: 'bottom',
                    labels: {
                        usePointStyle: true,
                        pointStyle: 'circle',
                        boxWidth: 8,
                        color: tickColor(),
                        font: { size: 11 },
                    },
                },
            },
        },
    });
}

watch(() => props.items, render, { deep: true });
onMounted(render);
onBeforeUnmount(() => chart?.destroy());
</script>

<template>
    <div :class="heightClass">
        <canvas ref="canvas" />
    </div>
</template>
