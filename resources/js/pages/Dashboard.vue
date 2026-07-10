<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Head } from '@statamic/cms/inertia';
import { Button, ButtonGroup, Card, Header, Heading, Icon, Subheading } from '@statamic/cms/ui';
import LineChart from '../components/LineChart.vue';
import DoughnutChart from '../components/DoughnutChart.vue';
import AnimatedNumber from '../components/AnimatedNumber.vue';
import ScrollArea from '../components/ScrollArea.vue';

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
    { label: 'Pageviews', icon: 'eye', ...data.value.tiles.pageviews },
    { label: 'Unique visitors', icon: 'users', ...data.value.tiles.visitors },
    { label: 'Sessions', icon: 'time-clock', ...data.value.tiles.sessions },
    { label: 'Active now', icon: 'pulse', ...data.value.tiles.now, live: true },
]);

const hasData = computed(() => data.value.tiles.pageviews.value > 0 || data.value.tiles.now.value > 0);
const maxPageViews = computed(() => Math.max(...data.value.pages.map((p) => p.views), 1));
const maxReferrerViews = computed(() => Math.max(...data.value.referrers.map((r) => r.views), 1));

// Countries: bars fill against the COMBINED total, so widths read as share-of-all.
const totalCountryViews = computed(() => data.value.countries.reduce((sum, c) => sum + c.views, 0));
const countryShare = (views) => (totalCountryViews.value ? (views / totalCountryViews.value) * 100 : 0);

// Localized full country names via the browser. Flag emojis are deliberately
// not used: Windows does not render them (you'd see bare letters instead).
const regionNames = new Intl.DisplayNames([document.documentElement.lang || 'en'], { type: 'region' });
const countryName = (code) => {
    try {
        return regionNames.of(code.toUpperCase()) || code;
    } catch (e) {
        return code;
    }
};

// Realtime activity folds into the Top pages rows as a live badge.
const pages = computed(() => {
    const activeByPath = Object.fromEntries(data.value.realtime.pages.map((p) => [p.path, p.views]));

    return data.value.pages.map((page) => ({ ...page, activeNow: activeByPath[page.path] ?? 0 }));
});

const fmt = (n) => new Intl.NumberFormat().format(n);

const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

const barStyle = (value, max) => ({
    width: `${(value / max) * 100}%`,
    backgroundColor: 'color-mix(in srgb, var(--color-primary) 10%, transparent)',
    ...(reducedMotion ? {} : { transition: 'width 0.7s cubic-bezier(0.22, 1, 0.36, 1)' }),
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
        data.value.tiles.now.unit = realtime.unit;
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
                            <Icon :name="stat.icon" class="size-4 shrink-0 opacity-50" />
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
                                v-if="stat.unit === 'views'"
                                class="text-xs text-gray-500"
                                :title="__('No consented visitors in the window; showing anonymous pageviews.')"
                                v-text="__('views')"
                            />
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
                        <div class="flex items-center gap-2">
                            <Icon name="eye" class="size-4 opacity-50" />
                            <Heading :text="__('Top pages')" />
                        </div>
                        <ScrollArea class="mt-3" max-height="20rem">
                        <table class="w-full table-fixed text-sm">
                            <thead>
                                <tr class="text-xs text-gray-500">
                                    <th class="pb-2 text-start font-normal" v-text="__('Page')" />
                                    <th class="w-24 pb-2 ps-6 text-end font-normal" v-text="__('Visitors')" />
                                    <th class="w-24 pb-2 ps-6 text-end font-normal" v-text="__('Views')" />
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="page in pages" :key="page.path">
                                    <td class="relative py-1.5 pe-3">
                                        <div class="absolute inset-y-1 start-0 rounded" :style="barStyle(page.views, maxPageViews)"></div>
                                        <span class="relative flex min-w-0 items-center gap-1.5 ps-1.5" :title="page.path">
                                            <span class="min-w-0 truncate" v-text="page.title ?? page.path" />
                                            <span v-if="page.title" class="min-w-0 truncate text-xs text-gray-500" v-text="page.path" />
                                            <span
                                                v-if="page.activeNow"
                                                class="inline-flex size-1.5 shrink-0 rounded-full bg-green-500"
                                                :title="__('Being viewed right now')"
                                            ></span>
                                        </span>
                                    </td>
                                    <td class="py-1.5 ps-6 text-end tabular-nums text-gray-500" v-text="fmt(page.visitors)" />
                                    <td class="py-1.5 ps-6 text-end font-medium tabular-nums" v-text="fmt(page.views)" />
                                </tr>
                            </tbody>
                        </table>
                        </ScrollArea>
                    </Card>

                    <Card class="insights-rise" style="animation-delay: 120ms">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <Icon name="share-link" class="size-4 opacity-50" />
                                <Heading :text="__('Sources')" />
                            </div>
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

                        <ScrollArea class="mt-3" max-height="20rem">
                        <template v-if="sourceTab === 'referrers'">
                            <table v-if="data.referrers.length" class="w-full table-fixed text-sm">
                                <tbody>
                                    <tr v-for="referrer in data.referrers" :key="referrer.domain">
                                        <td class="relative py-1.5 pe-3">
                                            <div class="absolute inset-y-1 start-0 rounded" :style="barStyle(referrer.views, maxReferrerViews)"></div>
                                            <span class="relative block truncate ps-1.5" v-text="referrer.domain" />
                                        </td>
                                        <td class="w-24 py-1.5 text-end font-medium tabular-nums" v-text="fmt(referrer.views)" />
                                    </tr>
                                </tbody>
                            </table>
                            <p v-else class="text-sm text-gray-500" v-text="__('No external referrers in this period.')" />
                        </template>

                        <template v-else>
                            <table v-if="data.campaigns.length" class="w-full table-fixed text-sm">
                                <thead>
                                    <tr class="text-xs text-gray-500">
                                        <th class="pb-2 text-start font-normal" v-text="__('Campaign')" />
                                        <th class="pb-2 text-start font-normal" v-text="__('Source')" />
                                        <th class="w-20 pb-2 text-end font-normal" v-text="__('Views')" />
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
                            <p v-else class="text-sm text-gray-500">
                                {{ __('No UTM-tagged visits yet. Add ?utm_campaign=… to newsletter and social links to see them here.') }}
                            </p>
                        </template>
                        </ScrollArea>
                    </Card>
                </div>

                <!-- Technology + countries -->
                <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
                    <Card class="insights-rise" style="animation-delay: 180ms">
                        <div class="flex items-center gap-2">
                            <Icon name="ui-browser-slider-2" class="size-4 opacity-50" />
                            <Heading :text="__('Technology')" />
                        </div>
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
                        <div class="flex items-center gap-2">
                            <Icon name="earth" class="size-4 opacity-50" />
                            <Heading :text="__('Countries')" />
                        </div>
                        <template v-if="data.countries.length">
                            <div class="mt-3 flex items-center gap-3 text-xs text-gray-500" style="padding-inline-end: 0.875rem">
                                <span class="min-w-0 flex-1" />
                                <span class="w-14 shrink-0 text-end" v-text="__('Visitors')" />
                                <span class="w-16 shrink-0 text-end" v-text="__('Views')" />
                                <span class="w-10 shrink-0 text-end">%</span>
                            </div>
                        <ScrollArea class="mt-2" max-height="18.5rem">
                            <ul class="space-y-2 text-sm">
                                <li v-for="country in data.countries" :key="country.code">
                                    <div class="flex items-center gap-3">
                                        <span class="flex min-w-0 flex-1 items-center gap-2">
                                            <img
                                                :src="`/vendor/statamic-insights/flags/${country.code.toLowerCase()}.svg`"
                                                class="size-4 shrink-0 rounded-full"
                                                alt=""
                                                loading="lazy"
                                                @error="$event.target.style.display = 'none'"
                                            />
                                            <span class="truncate" :title="country.code" v-text="countryName(country.code)" />
                                        </span>
                                        <span class="w-14 shrink-0 text-end tabular-nums text-gray-500" v-text="fmt(country.visitors)" />
                                        <span class="w-16 shrink-0 text-end font-medium tabular-nums" v-text="fmt(country.views)" />
                                        <span class="w-10 shrink-0 text-end text-xs tabular-nums text-gray-500" v-text="`${Math.round(countryShare(country.views))}%`" />
                                    </div>
                                    <div
                                        class="mt-1 h-1.5 w-full overflow-hidden rounded-full"
                                        style="background-color: color-mix(in srgb, var(--color-primary) 10%, transparent)"
                                    >
                                        <div
                                            class="h-full rounded-full"
                                            :style="{
                                                width: `${countryShare(country.views)}%`,
                                                backgroundColor: 'var(--color-primary)',
                                                transition: 'width 0.7s cubic-bezier(0.22, 1, 0.36, 1)',
                                            }"
                                        ></div>
                                    </div>
                                </li>
                            </ul>
                        </ScrollArea>
                        </template>
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

@media (prefers-reduced-motion: reduce) {
    .insights-rise {
        animation: none;
    }
}
</style>
