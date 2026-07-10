<script setup>
import { Card, Heading, Subheading } from '@statamic/cms/ui';
import LineChart from './LineChart.vue';

defineProps({
    title: { type: String, required: true },
    tiles: { type: Object, required: true },
    timeseries: { type: Object, required: true },
    url: { type: String, required: true },
});

const fmt = (n) => new Intl.NumberFormat().format(n);
</script>

<template>
    <Card>
        <div class="flex items-center justify-between">
            <Heading :text="__(title)" />
            <a
                :href="url"
                class="text-xs font-medium hover:underline"
                style="color: var(--color-primary)"
                v-text="__('View dashboard')"
            />
        </div>

        <div class="mt-3 flex gap-8">
            <div>
                <Subheading :text="__('Pageviews (7d)')" />
                <div class="mt-1 text-2xl font-bold tabular-nums" v-text="fmt(tiles.pageviews.value)" />
            </div>
            <div>
                <Subheading :text="__('Visitors')" />
                <div class="mt-1 text-2xl font-bold tabular-nums" v-text="fmt(tiles.visitors.value)" />
            </div>
            <div>
                <Subheading :text="__('Active now')" />
                <div class="mt-1 text-2xl font-bold tabular-nums" v-text="fmt(tiles.now.value)" />
            </div>
        </div>

        <LineChart class="mt-3" height-class="h-20" minimal :labels="timeseries.labels" :values="timeseries.views" />
    </Card>
</template>
