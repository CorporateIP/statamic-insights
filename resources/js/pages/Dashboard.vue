<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Head } from '@statamic/cms/inertia';
import { Button, Card, Header, Heading, Icon, Select, Subheading } from '@statamic/cms/ui';
import LineChart from '../components/LineChart.vue';
import DoughnutChart from '../components/DoughnutChart.vue';
import AnimatedNumber from '../components/AnimatedNumber.vue';
import ScrollArea from '../components/ScrollArea.vue';
import { cpLocale, fmt } from '../components/locale.js';

const props = defineProps({
    title: { type: String, required: true },
    dataUrl: { type: String, required: true },
    exportUrl: { type: String, required: true },
    settingsUrl: { type: String, default: null },
    initial: { type: Object, required: true },
});

const RANGES = [
    { value: 'today', label: 'Today' },
    { value: '7d', label: '7 days' },
    { value: '30d', label: '30 days' },
    { value: '90d', label: '90 days' },
    { value: '6m', label: '6 months' },
    { value: '12m', label: '12 months' },
    { value: 'all', label: 'All time' },
    { value: 'custom', label: 'Custom range' },
];

const FILTER_LABELS = {
    path: 'Page',
    referrer: 'Referrer',
    country: 'Country',
    device: 'Device',
    browser: 'Browser',
    os: 'OS',
    campaign: 'Campaign',
};

const data = ref(props.initial);
const range = ref(props.initial.range.key);
const customFrom = ref(props.initial.range.key === 'custom' ? props.initial.range.from : '');
const customTo = ref(props.initial.range.key === 'custom' ? props.initial.range.to : '');
const site = ref(props.initial.site || '');
const filters = ref({ ...props.initial.filters });
const loading = ref(false);
const sourceTab = ref('referrers');

const rangeOptions = computed(() => RANGES.map((r) => ({ value: r.value, label: __(r.label) })));

const siteOptions = computed(() => [
    { value: '', label: __('All sites') },
    ...data.value.sites.map((s) => ({ value: s.handle, label: s.name })),
]);

const activeFilters = computed(() => Object.entries(filters.value).map(([key, value]) => ({ key, value })));

const query = (extra = {}) => {
    const params = new URLSearchParams();

    params.set('range', range.value);
    if (range.value === 'custom') {
        params.set('from', customFrom.value);
        params.set('to', customTo.value);
    }
    if (site.value) params.set('site', site.value);
    for (const [key, value] of Object.entries(filters.value)) params.set(`filter_${key}`, value);
    for (const [key, value] of Object.entries(extra)) params.set(key, value);

    return params.toString();
};

const exportHref = (dataset) => `${props.exportUrl}?${query({ dataset })}`;

async function fetchData() {
    if (range.value === 'custom' && (!customFrom.value || !customTo.value)) return;

    loading.value = true;

    try {
        const response = await fetch(`${props.dataUrl}?${query()}`, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        });
        if (response.ok) {
            data.value = await response.json();
            history.replaceState(null, '', `?${query()}`);
        }
    } finally {
        loading.value = false;
    }
}

function setRange(key) {
    if (loading.value) return;

    range.value = key;

    if (key !== 'custom') fetchData();
}

function setSite(handle) {
    site.value = handle || '';
    fetchData();
}

function addFilter(key, value) {
    if (!value || filters.value[key] === value) return;

    filters.value = { ...filters.value, [key]: value };
    fetchData();
}

function removeFilter(key) {
    const next = { ...filters.value };
    delete next[key];
    filters.value = next;
    fetchData();
}

const stats = computed(() => [
    { key: 'pageviews', label: 'Pageviews', icon: 'eye', ...data.value.tiles.pageviews },
    { key: 'visitors', label: 'Unique visitors', icon: 'users', ...data.value.tiles.visitors },
    { key: 'sessions', label: 'Sessions', icon: 'time-clock', ...data.value.tiles.sessions },
    { key: 'bounce_rate', label: 'Bounce rate', icon: 'arrow-turn-up', format: 'percent', invertDelta: true, ...data.value.tiles.bounce_rate },
    { key: 'duration', label: 'Avg. visit duration', icon: 'time-stopwatch', format: 'duration', ...data.value.tiles.duration },
    { key: 'now', label: 'Active now', icon: 'pulse', live: true, ...data.value.tiles.now },
]);

const hasData = computed(() => data.value.tiles.pageviews.value > 0 || data.value.tiles.now.value > 0);
const maxPageViews = computed(() => Math.max(...data.value.pages.map((p) => p.views), 1));
const maxReferrerViews = computed(() => Math.max(...data.value.referrers.map((r) => r.views), 1));
const maxGoalConversions = computed(() => Math.max(...data.value.goals.map((g) => g.conversions), 1));

// Countries: bars fill against the COMBINED total, so widths read as share-of-all.
const totalCountryViews = computed(() => data.value.countries.reduce((sum, c) => sum + c.views, 0));
const countryShare = (views) => (totalCountryViews.value ? (views / totalCountryViews.value) * 100 : 0);

// Country names localized to the CP language. Flag emojis are deliberately
// not used: Windows does not render them (you'd see bare letters instead).
const regionNames = new Intl.DisplayNames([cpLocale()], { type: 'region' });
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

const fmtDuration = (seconds) => {
    const m = Math.floor(seconds / 60);
    const s = Math.round(seconds % 60);

    return m > 0 ? `${m}m ${s}s` : `${s}s`;
};

const statValue = (stat) => {
    if (stat.available === false || stat.value === null) return null;
    if (stat.format === 'percent') return `${stat.value}%`;
    if (stat.format === 'duration') return fmtDuration(stat.value);

    return null; // rendered as an AnimatedNumber instead
};

const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

const barStyle = (value, max) => ({
    width: `${(value / max) * 100}%`,
    backgroundColor: 'color-mix(in srgb, var(--color-primary) 10%, transparent)',
    ...(reducedMotion ? {} : { transition: 'width 0.7s cubic-bezier(0.22, 1, 0.36, 1)' }),
});

// The live stat + badges keep themselves fresh without touching the rest.
let realtimeTimer = null;

async function refreshRealtime() {
    try {
        const response = await fetch(`${props.dataUrl}?${query({ only: 'realtime' })}`, {
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
            <div class="flex items-center gap-2">
                <Select
                    v-if="data.sites.length"
                    :model-value="site"
                    :options="siteOptions"
                    option-label="label"
                    option-value="value"
                    size="sm"
                    @update:model-value="setSite"
                />
                <Select
                    :model-value="range"
                    :options="rangeOptions"
                    option-label="label"
                    option-value="value"
                    size="sm"
                    @update:model-value="setRange"
                />
                <Button v-if="settingsUrl" size="sm" icon="cog" :href="settingsUrl" :title="__('Insights settings')" />
            </div>
        </Header>

        <div v-if="range === 'custom'" class="insights-custom-range">
            <label class="text-xs text-gray-500" v-text="__('From')" />
            <input v-model="customFrom" type="date" class="insights-date-input" :max="customTo || undefined" />
            <label class="text-xs text-gray-500" v-text="__('To')" />
            <input v-model="customTo" type="date" class="insights-date-input" :min="customFrom || undefined" />
            <Button size="sm" variant="primary" :text="__('Apply')" :disabled="!customFrom || !customTo" @click="fetchData" />
        </div>

        <div v-if="activeFilters.length || data.range.clamped || data.range.source === 'rollups'" class="mt-2 flex flex-wrap items-center gap-2">
            <button
                v-for="filter in activeFilters"
                :key="filter.key"
                type="button"
                class="insights-chip"
                :title="__('Remove filter')"
                @click="removeFilter(filter.key)"
            >
                <span class="opacity-60">{{ __(FILTER_LABELS[filter.key]) }}:</span>
                <span class="font-medium">{{ filter.value }}</span>
                <span aria-hidden="true">×</span>
            </button>

            <span v-if="data.range.clamped" class="text-xs text-gray-500">
                {{ __('Filters read raw pageviews only - the range was limited to the retention window.') }}
            </span>
            <span v-else-if="data.range.source === 'rollups'" class="text-xs text-gray-500">
                {{ __('Aggregated daily data - bounce rate and visit duration need raw pageviews.') }}
            </span>
        </div>

        <div :class="{ 'pointer-events-none opacity-60': loading }" class="transition-opacity duration-300">
            <!-- Hero: stats + trend in one card -->
            <Card class="insights-rise mt-2">
                <div class="insights-tiles">
                    <div v-for="stat in stats" :key="stat.key">
                        <div class="flex items-center gap-2">
                            <Icon :name="stat.icon" class="size-4 shrink-0 opacity-50" />
                            <Subheading :text="__(stat.label)" />
                            <span v-if="stat.live" class="relative flex size-2">
                                <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-green-400 opacity-75"></span>
                                <span class="relative inline-flex size-2 rounded-full bg-green-500"></span>
                            </span>
                        </div>
                        <div class="mt-1 flex items-baseline gap-2">
                            <span
                                v-if="stat.available === false"
                                class="text-3xl font-bold text-gray-400"
                                :title="__('Not available for aggregated ranges.')"
                                >&mdash;</span
                            >
                            <span v-else-if="statValue(stat)" class="text-3xl font-bold tabular-nums" v-text="statValue(stat)" />
                            <span v-else class="text-3xl font-bold tabular-nums">
                                <span
                                    v-if="stat.approx"
                                    class="text-gray-400"
                                    :title="__('Sum of daily unique visitors - repeat visitors count once per day.')"
                                    >≈</span
                                >
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
                                :class="(stat.invertDelta ? stat.delta <= 0 : stat.delta >= 0) ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'"
                                v-text="`${stat.delta >= 0 ? '▲' : '▼'} ${Math.abs(stat.delta)}%`"
                            />
                        </div>
                    </div>
                </div>

                <LineChart class="mt-6" height-class="h-64" :labels="data.timeseries.labels" :values="data.timeseries.views" :label="__('Pageviews')" />
            </Card>

            <template v-if="hasData">
                <!-- Goals -->
                <Card v-if="data.goals.length" class="insights-rise mt-4" style="animation-delay: 30ms">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <Icon name="flag" class="size-4 opacity-50" />
                            <Heading :text="__('Goals')" />
                        </div>
                        <a class="insights-csv" :href="exportHref('goals')" :title="__('Export CSV')">CSV</a>
                    </div>
                    <table class="mt-3 w-full table-fixed text-sm">
                        <thead>
                            <tr class="text-xs text-gray-500">
                                <th class="pb-2 text-start font-normal" v-text="__('Goal')" />
                                <th class="w-28 pb-2 ps-6 text-end font-normal" v-text="__('Visitors')" />
                                <th class="w-28 pb-2 ps-6 text-end font-normal" v-text="__('Conversions')" />
                                <th class="w-32 pb-2 ps-6 text-end font-normal" v-text="__('Conversion rate')" />
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="goal in data.goals" :key="goal.handle">
                                <td class="relative py-1.5 pe-3">
                                    <div class="absolute inset-y-1 start-0 rounded" :style="barStyle(goal.conversions, maxGoalConversions)"></div>
                                    <span class="relative block truncate ps-1.5" v-text="goal.name" />
                                </td>
                                <td class="py-1.5 ps-6 text-end tabular-nums text-gray-500" v-text="fmt(goal.visitors)" />
                                <td class="py-1.5 ps-6 text-end font-medium tabular-nums" v-text="fmt(goal.conversions)" />
                                <td class="py-1.5 ps-6 text-end tabular-nums text-gray-500" v-text="goal.rate !== null ? `${goal.rate}%` : '—'" />
                            </tr>
                        </tbody>
                    </table>
                </Card>

                <!-- Pages + sources -->
                <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
                    <Card class="insights-rise" style="animation-delay: 60ms">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <Icon name="eye" class="size-4 opacity-50" />
                                <Heading :text="__('Top pages')" />
                            </div>
                            <a class="insights-csv" :href="exportHref('pages')" :title="__('Export CSV')">CSV</a>
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
                                <tr v-for="page in pages" :key="page.path" class="cursor-pointer" :title="__('Filter by this value')" @click="addFilter('path', page.path)">
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
                            <div class="flex items-center gap-2">
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
                                <a class="insights-csv" :href="exportHref(sourceTab)" :title="__('Export CSV')">CSV</a>
                            </div>
                        </div>

                        <ScrollArea class="mt-3" max-height="20rem">
                        <template v-if="sourceTab === 'referrers'">
                            <table v-if="data.referrers.length" class="w-full table-fixed text-sm">
                                <tbody>
                                    <tr v-for="referrer in data.referrers" :key="referrer.domain" class="cursor-pointer" :title="__('Filter by this value')" @click="addFilter('referrer', referrer.domain)">
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
                                    <tr v-for="c in data.campaigns" :key="`${c.campaign}-${c.source}`" class="cursor-pointer" :title="__('Filter by this value')" @click="addFilter('campaign', c.campaign)">
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
                        <div class="insights-tech mt-3">
                            <div>
                                <Subheading :text="__('Devices')" />
                                <DoughnutChart v-if="data.devices.length" class="mt-2" height-class="h-40" :items="data.devices" />
                                <p v-else class="mt-2 text-sm text-gray-500" v-text="__('No data yet.')" />
                            </div>
                            <div>
                                <Subheading :text="__('Browsers')" />
                                <DoughnutChart v-if="data.browsers.length" class="mt-2" height-class="h-40" :items="data.browsers" />
                                <p v-else class="mt-2 text-sm text-gray-500" v-text="__('No data yet.')" />
                            </div>
                            <div>
                                <Subheading :text="__('Operating systems')" />
                                <DoughnutChart v-if="data.os.length" class="mt-2" height-class="h-40" :items="data.os" />
                                <p v-else class="mt-2 text-sm text-gray-500" v-text="__('No data yet.')" />
                            </div>
                        </div>
                    </Card>

                    <Card class="insights-rise" style="animation-delay: 240ms">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <Icon name="earth" class="size-4 opacity-50" />
                                <Heading :text="__('Countries')" />
                            </div>
                            <a class="insights-csv" :href="exportHref('countries')" :title="__('Export CSV')">CSV</a>
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
                                <li v-for="country in data.countries" :key="country.code" class="cursor-pointer" :title="__('Filter by this value')" @click="addFilter('country', country.code)">
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

/* Own layout classes: the CP's compiled Tailwind only includes utilities the
   core happens to use, so anything layout-critical is defined here. */
.insights-tiles {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 1.5rem;
}

@media (min-width: 1024px) {
    .insights-tiles {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }
}

@media (min-width: 1536px) {
    .insights-tiles {
        grid-template-columns: repeat(6, minmax(0, 1fr));
    }
}

.insights-tech {
    display: grid;
    grid-template-columns: minmax(0, 1fr);
    gap: 1rem;
}

@media (min-width: 640px) {
    .insights-tech {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }
}

.insights-custom-range {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin-top: 0.5rem;
}

.insights-date-input {
    border: 1px solid color-mix(in srgb, currentColor 20%, transparent);
    border-radius: 0.375rem;
    background: transparent;
    color: inherit;
    font-size: 0.8125rem;
    padding: 0.25rem 0.5rem;
}

.insights-chip {
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
    border-radius: 9999px;
    font-size: 0.75rem;
    padding: 0.25rem 0.625rem;
    background-color: color-mix(in srgb, var(--color-primary) 12%, transparent);
    color: inherit;
}

.insights-chip:hover {
    background-color: color-mix(in srgb, var(--color-primary) 22%, transparent);
}

.insights-csv {
    font-size: 0.6875rem;
    font-weight: 600;
    letter-spacing: 0.05em;
    opacity: 0.5;
}

.insights-csv:hover {
    opacity: 1;
    text-decoration: underline;
}
</style>
