<script setup>
import { Head } from '@statamic/cms/inertia';
import { Card, Header, Heading } from '@statamic/cms/ui';

defineProps({
    title: { type: String, required: true },
    stats: { type: Object, required: true },
});
</script>

<template>
    <Head :title="[__(title)]" />

    <div>
        <Header :title="__(title)" icon="chart-monitoring-indicator" />

        <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
            <Card v-for="tile in stats.tiles" :key="tile.label">
                <Heading :text="__(tile.label)" size="sm" />
                <p class="mt-2 text-3xl font-bold" v-text="tile.value" />
            </Card>
        </div>

        <Card class="mt-6">
            <Heading :text="__('No data yet')" />
            <p class="mt-2 text-sm text-gray-500" v-text="__('The tracking pipeline, charts and breakdowns arrive in the next build stages.')" />
        </Card>
    </div>
</template>
