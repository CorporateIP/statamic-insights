<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Head } from '@statamic/cms/inertia';
import { Button, ButtonGroup, Card, Header, Heading, Subheading } from '@statamic/cms/ui';
import LineChart from '../components/LineChart.vue';
import DoughnutChart from '../components/DoughnutChart.vue';
import AnimatedNumber from '../components/AnimatedNumber.vue';

const props = defineProps({
    title: { type: String, required: true },
    dataUrl: { type: String, required: true },
    initial: { type: Object, required: true },
});

const RANGES = [
    { key: 'today', label: 'Today' },
    { key: '7d', label: '7 days' },
    { key: '30d', label: '30 days' },
    { key: '90d', label: '90 days' },
];

const data = ref(props.initial);
const range = ref(props.initial.range);
const loading = ref(false);
const sourceTab = ref('referrers');

const stats = computed(() => [
    { label: 'Pageviews', ...data.value.tiles.pageviews },
    { label: 'Unique visitors', ...data.value.tiles.visitors },
    { label: 'Sessions', ...data.value.tiles.sessions },
    { label: 'Active now', ...data.value.tiles.now, live: true },
]);

const hasData = computed(() => data.value.tiles.pageviews.value > 0 || data.value.tiles.now.value > 0);
const maxPageViews = computed(() => Math.max(...data.value.pages.map((p) => p.views), 1));
const maxReferrerViews = computed(() => Math.max(...data.value.referrers.map((r) => r.views), 1));
const maxCountryViews = computed(() => Math.max(...data.value.countries.map((c) => c.views), 1));

// Realtime activity folds into the Top pages rows as a live badge.
const pages = computed(() => {
    const activeByPath = Object.fromEntries(data.value.realtime.pages.map((p) => [p.path, p.views]));

    return data.value.pages.map((page) => ({ ...page, activeNow: activeByPath[page.path] ?? 0 }));
});

const fmt = (n) => new Intl.NumberFormat().format(n);

const flag = (code) =>
    code.toUpperCase().replace(/./g, (c) => String.fromCodePoint(127397 + c.charCodeAt(0)));

const barStyle = (value, max) => ({
    width: `${(value / max) * 100}%`,
    backgroundColor: 'color-mix(in srgb, var(--color-primary) 10%, transparent)',
    transition: 'width 0.7s cubic-bezier(0.22, 1, 0.36, 1)',
});

async function setRange(key) {
    if (loading.value || key === range.value) return;

    range.value = key;
    loading.value = true;

    try {
        const response = await fetch(`${props.dataUrl}?range=${key}`, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        });
        if (response.ok) data.value = await response.json();
    } finally {
        loading.value = false;
    }
}

// The live stat + badges keep themselves fresh without touching the rest.
let realtimeTimer = null;

async function refreshRealtime() {
    try {
        const response = await fetch(`${props.dataUrl}?range=${range.value}&only=realtime`, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        });
        if (!response.ok) return;

        const { realtime } = await response.json();
        data.value.realtime = realtime;
        data.value.tiles.now.value = realtime.count;
    } catch (e) {
        /* transient network errors: try again next tick */
    }
}

onMounted(() => (realtimeTimer = setInterval(refreshRealtime, 30000)));
onBeforeUnmount(() => clearInterval(realtimeTimer));
</script>

<template>
    <Head :title="[__(title)]" />

    <div>
        <Header :title="__(title)" icon="chart-monitoring-indicator">
            <ButtonGroup>
                <Button
                    v-for="r in RANGES"
                    :key="r.key"
                    :text="__(r.label)"
                    :variant="range === r.key ? 'primary' : 'default'"
                    size="sm"
                    @click="setRange(r.key)"
                />
            </ButtonGroup>
        </Header>

        <div :class="{ 'pointer-events-none opacity-60': loading }" class="transition-opacity duration-300">
            <!-- Hero: stats + trend in one card -->
            <Card class="insights-rise">
                <div class="grid grid-cols-2 gap-6 lg:grid-cols-4">
                    <div v-for="stat in stats" :key="stat.label">
                        <div class="flex items-center gap-2">
                            <Subheading :text="__(stat.label)" />
                            <span v-if="stat.live" class="relative flex size-2">
                                <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-green-400 opacity-75"></span>
                                <span class="relative inline-flex size-2 rounded-full bg-green-500"></span>
                            </span>
                        </div>
                        <div class="mt-1 flex items-baseline gap-2">
                            <span class="text-3xl font-bold tabular-nums">
                                <AnimatedNumber :value="stat.value" />
                            </span>
                            <span
                                v-if="stat.delta !== null && stat.delta !== undefined"
                                class="text-xs font-medium"
                                :class="stat.delta >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'"
                                v-text="`${stat.delta >= 0 ? '▲' : '▼'} ${Math.abs(stat.delta)}%`"
                            />
                        </div>
                    </div>
                </div>

                <LineChart class="mt-6" height-class="h-64" :labels="data.timeseries.labels" :values="data.timeseries.views" :label="__('Pageviews')" />
            </Card>

            <template v-if="hasData">
                <!-- Pages + sources -->
                <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
                    <Card class="insights-rise" style="animation-delay: 60ms">
                        <Heading :text="__('Top pages')" />
                        <table class="mt-3 w-full text-sm">
                            <thead>
                                <tr class="text-xs text-gray-500">
                                    <th class="pb-2 text-start font-normal" v-text="__('Page')" />
                                    <th class="pb-2 text-end font-normal" v-text="__('Visitors')" />
                                    <th class="pb-2 text-end font-normal" v-text="__('Views')" />
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="page in pages" :key="page.path">
                                    <td class="relative py-1.5 pe-3">
                                        <div class="absolute inset-y-1 start-0 rounded" :style="barStyle(page.views, maxPageViews)"></div>
                                        <span class="relative flex items-center gap-1.5 ps-1.5">
                                            <span class="truncate" :title="page.path" v-text="page.title ?? page.path" />
                                            <span v-if="page.title" class="hidden truncate text-xs text-gray-500 sm:inline" v-text="page.path" />
                                            <span
                                                v-if="page.activeNow"
                                                class="inline-flex size-1.5 shrink-0 rounded-full bg-green-500"
                                                :title="__('Being viewed right now')"
                                            ></span>
                                        </span>
                                    </td>
                                    <td class="py-1.5 text-end tabular-nums text-gray-500" v-text="fmt(page.visitors)" />
                                    <td class="py-1.5 text-end font-medium tabular-nums" v-text="fmt(page.views)" />
                                </tr>
                            </tbody>
                        </table>
                    </Card>

                    <Card class="insights-rise" style="animation-delay: 120ms">
                        <div class="flex items-center justify-between">
                            <Heading :text="__('Sources')" />
                            <div class="flex gap-1 text-xs">
                                <button
                                    type="button"
                                    class="rounded-full px-2.5 py-1 font-medium transition-colors"
                                    :style="sourceTab === 'referrers' ? { backgroundColor: 'var(--color-primary)', color: '#fff' } : { color: 'inherit', opacity: 0.6 }"
                                    @click="sourceTab = 'referrers'"
                                    v-text="__('Referrers')"
                                />
                                <button
                                    type="button"
                                    class="rounded-full px-2.5 py-1 font-medium transition-colors"
                                    :style="sourceTab === 'campaigns' ? { backgroundColor: 'var(--color-primary)', color: '#fff' } : { color: 'inherit', opacity: 0.6 }"
                                    @click="sourceTab = 'campaigns'"
                                    v-text="__('Campaigns')"
                                />
                            </div>
                        </div>

                        <template v-if="sourceTab === 'referrers'">
                            <table v-if="data.referrers.length" class="mt-3 w-full text-sm">
                                <tbody>
                                    <tr v-for="referrer in data.referrers" :key="referrer.domain">
                                        <td class="relative py-1.5 pe-3">
                                            <div class="absolute inset-y-1 start-0 rounded" :style="barStyle(referrer.views, maxReferrerViews)"></div>
                                            <span class="relative block truncate ps-1.5" v-text="referrer.domain" />
                                        </td>
                                        <td class="py-1.5 text-end font-medium tabular-nums" v-text="fmt(referrer.views)" />
                                    </tr>
                                </tbody>
                            </table>
                            <p v-else class="mt-3 text-sm text-gray-500" v-text="__('No external referrers in this period.')" />
                        </template>

                        <template v-else>
                            <table v-if="data.campaigns.length" class="mt-3 w-full text-sm">
                                <thead>
                                    <tr class="text-xs text-gray-500">
                                        <th class="pb-2 text-start font-normal" v-text="__('Campaign')" />
                                        <th class="pb-2 text-start font-normal" v-text="__('Source')" />
                                        <th class="pb-2 text-end font-normal" v-text="__('Views')" />
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="c in data.campaigns" :key="`${c.campaign}-${c.source}`">
                                        <td class="truncate py-1.5 pe-3" v-text="c.campaign" />
                                        <td class="truncate py-1.5 pe-3 text-gray-500" v-text="c.source ?? '-'" />
                                        <td class="py-1.5 text-end font-medium tabular-nums" v-text="fmt(c.views)" />
                                    </tr>
                                </tbody>
                            </table>
                            <p v-else class="mt-3 text-sm text-gray-500">
                                {{ __('No UTM-tagged visits yet. Add ?utm_campaign=… to newsletter and social links to see them here.') }}
                            </p>
                        </template>
                    </Card>
                </div>

                <!-- Technology + countries -->
                <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
                    <Card class="insights-rise" style="animation-delay: 180ms">
                        <Heading :text="__('Technology')" />
                        <div class="mt-3 grid grid-cols-2 gap-4">
                            <div>
                                <Subheading :text="__('Devices')" />
                                <DoughnutChart v-if="data.devices.length" class="mt-2" height-class="h-44" :items="data.devices" />
                                <p v-else class="mt-2 text-sm text-gray-500" v-text="__('No data yet.')" />
                            </div>
                            <div>
                                <Subheading :text="__('Browsers')" />
                                <DoughnutChart v-if="data.browsers.length" class="mt-2" height-class="h-44" :items="data.browsers" />
                                <p v-else class="mt-2 text-sm text-gray-500" v-text="__('No data yet.')" />
                            </div>
                        </div>
                    </Card>

                    <Card class="insights-rise" style="animation-delay: 240ms">
                        <Heading :text="__('Countries')" />
                        <ul v-if="data.countries.length" class="mt-3 text-sm">
                            <li v-for="country in data.countries" :key="country.code" class="relative flex items-center gap-2 py-1">
                                <div class="absolute inset-y-0.5 start-0 rounded" :style="barStyle(country.views, maxCountryViews)"></div>
                                <span class="relative ps-1.5" v-text="flag(country.code)" />
                                <span class="relative flex-1" v-text="country.code" />
                                <span class="relative font-medium tabular-nums" v-text="fmt(country.views)" />
                            </li>
                        </ul>
                        <p v-else class="mt-3 text-sm text-gray-500">
                            {{ __('No data yet - run insights:geo-update to enable country stats.') }}
                        </p>
                    </Card>
                </div>
            </template>

            <Card v-else class="insights-rise mt-4" style="animation-delay: 60ms">
                <Heading :text="__('No visits recorded yet')" />
                <p class="mt-2 text-sm text-gray-500">
                    {{ __('Data appears here as soon as the tracker on the website registers its first pageviews.') }}
                </p>
            </Card>
        </div>
    </div>
</template>

<style>
@keyframes insights-rise {
    from {
        opacity: 0;
        transform: translateY(10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.insights-rise {
    animation: insights-rise 0.45s cubic-bezier(0.22, 1, 0.36, 1) both;
}
</style>
