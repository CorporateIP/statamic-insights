<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Chart } from 'chart.js/auto';
import { prefersReducedMotion, primary, tickColor, tooltipOptions } from './theme.js';

const props = defineProps({
    labels: { type: Array, required: true },
    values: { type: Array, required: true },
    label: { type: String, default: 'Pageviews' },
    heightClass: { type: String, default: 'h-72' },
    minimal: { type: Boolean, default: false }, // sparkline mode: no axes
});

const canvas = ref(null);
let chart = null;

function render() {
    chart?.destroy();

    chart = new Chart(canvas.value, {
        type: 'line',
        data: {
            labels: props.labels,
            datasets: [
                {
                    label: props.label,
                    data: props.values,
                    fill: true,
                    tension: 0.35,
                    borderWidth: 2,
                    borderColor: primary(),
                    backgroundColor: primary(0.12),
                    pointRadius: props.values.length > 40 ? 0 : 2.5,
                    pointBackgroundColor: primary(),
                    pointHoverRadius: 5,
                    pointHoverBackgroundColor: primary(),
                    pointHoverBorderColor: 'rgba(255, 255, 255, 0.9)',
                    pointHoverBorderWidth: 2,
                },
            ],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            ...(prefersReducedMotion() && { animation: false }),
            interaction: { mode: 'index', intersect: false },
            plugins: { legend: { display: false }, tooltip: tooltipOptions() },
            scales: props.minimal
                ? { x: { display: false }, y: { display: false, beginAtZero: true } }
                : {
                    x: {
                        grid: { display: false },
                        ticks: { maxTicksLimit: 12, color: tickColor() },
                    },
                    y: {
                        beginAtZero: true,
                        grid: { color: 'rgba(128, 128, 128, 0.12)' },
                        border: { display: false },
                        ticks: { precision: 0, maxTicksLimit: 5, color: tickColor() },
                    },
                },
        },
    });
}

watch(() => [props.labels, props.values], render, { deep: true });
onMounted(render);
onBeforeUnmount(() => chart?.destroy());
</script>

<template>
    <div :class="heightClass">
        <canvas ref="canvas" />
    </div>
</template>
