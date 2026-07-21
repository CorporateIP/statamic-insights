<?php

namespace CorporateIp\Insights\Dashboard;

use Carbon\CarbonPeriod;
use CorporateIp\Insights\Goals\Goal;
use CorporateIp\Insights\Goals\GoalRepository;
use CorporateIp\Insights\Models\Event;
use CorporateIp\Insights\Models\Hit;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;

/**
 * Computes every dataset the dashboard needs for one date range.
 *
 * Two data sources, picked per request:
 * - RAW hit/event rows for ranges inside the retention window and for every
 *   filtered view (pre-aggregated rollups can't cross-filter).
 * - Daily ROLLUP tables for longer unfiltered ranges, with today's raw numbers
 *   merged in live. Unique visitors can't be deduplicated across pre-
 *   aggregated days, so rollup-sourced visitor counts are flagged approximate.
 *
 * Filtered ranges silently clamp to the retention window (flagged `clamped`
 * so the UI can say so).
 */
class Metrics
{
    public const RANGES = ['today', '7d', '30d', '90d', '6m', '12m', 'all', 'custom'];

    /** Dimensions the dashboard can filter by; each maps to a raw hits column. */
    public const FILTERS = [
        'path' => 'path',
        'referrer' => 'referrer_domain',
        'country' => 'country',
        'device' => 'device_type',
        'browser' => 'browser',
        'os' => 'os',
        'campaign' => 'utm_campaign',
    ];

    private Carbon $start;

    private Carbon $end;

    private bool $clamped = false;

    public function __construct(
        private readonly string $range,
        ?string $from = null,
        ?string $to = null,
        private readonly ?string $site = null,
        private readonly array $filters = [],
    ) {
        abort_unless(in_array($range, self::RANGES, true), 422, 'Unknown range.');

        // Ranges snap to full days (today included), so the first chart bucket
        // is never a silently-undercounted partial day.
        $this->end = now();
        $this->start = match ($range) {
            'today' => now()->startOfDay(),
            '7d' => now()->subDays(6)->startOfDay(),
            '30d' => now()->subDays(29)->startOfDay(),
            '90d' => now()->subDays(89)->startOfDay(),
            '6m' => now()->subMonths(6)->startOfDay(),
            '12m' => now()->subMonths(12)->startOfDay(),
            'all' => $this->oldestDay(),
            'custom' => $this->parseDay($from),
        };

        if ($range === 'custom' && $to) {
            $this->end = $this->parseDay($to)->endOfDay()->min(now());
        }

        abort_if($this->start->gt($this->end), 422, 'Invalid range.');

        // Filters need raw rows; clamp filtered ranges to the raw window.
        if ($this->filters !== [] && $this->start->lt($rawCutoff = $this->rawCutoff())) {
            $this->start = $rawCutoff;
            $this->clamped = true;
        }
    }

    public static function fromRequest(Request $request): self
    {
        $filters = collect(self::FILTERS)
            ->mapWithKeys(fn ($column, $key) => [$key => $request->query("filter_{$key}")])
            ->filter(fn ($value) => is_string($value) && $value !== '')
            ->map(fn ($value) => mb_substr($value, 0, 255))
            ->all();

        return new self(
            range: (string) $request->query('range', '7d'),
            from: $request->query('from'),
            to: $request->query('to'),
            site: self::validSite($request->query('site')),
            filters: $filters,
        );
    }

    public static function make(string $range): array
    {
        return (new self($range))->payload();
    }

    /**
     * Cached payload for dashboard/widget reads (the realtime slice always
     * bypasses this via realtime()). 60s keeps repeated CP loads cheap without
     * the numbers ever feeling stale. Keyed per locale: chart labels are baked
     * into the payload.
     */
    public static function cached(string $range): array
    {
        return (new self($range))->cachedPayload();
    }

    public function cachedPayload(): array
    {
        $key = sprintf(
            'insights.metrics.%s.%s',
            app()->getLocale(),
            md5(json_encode([$this->range, $this->start->timestamp, $this->end->toDateString(), $this->site, $this->filters])),
        );

        return Cache::remember($key, 60, fn () => $this->payload());
    }

    public function payload(): array
    {
        $rollups = $this->usesRollups();

        return [
            'range' => [
                'key' => $this->range,
                'from' => $this->start->toDateString(),
                'to' => $this->end->toDateString(),
                'source' => $rollups ? 'rollups' : 'raw',
                'clamped' => $this->clamped,
            ],
            'site' => $this->site,
            'sites' => $this->sites(),
            'filters' => (object) $this->filters,
            'tiles' => $rollups ? $this->rollupTiles() : $this->tiles(),
            'timeseries' => $rollups ? $this->rollupTimeseries() : $this->timeseries(),
            'pages' => $rollups ? $this->rollupPages() : $this->pages(),
            'referrers' => $rollups ? $this->rollupDimension('referrer') : $this->referrers(),
            'devices' => $rollups ? $this->rollupDimension('device') : $this->breakdown('device_type'),
            'browsers' => $rollups ? $this->rollupDimension('browser') : $this->breakdown('browser'),
            'os' => $rollups ? $this->rollupDimension('os') : $this->breakdown('os'),
            'countries' => $rollups ? $this->rollupCountries() : $this->countries(),
            'campaigns' => $rollups ? $this->rollupCampaigns() : $this->campaigns(),
            'goals' => $rollups ? $this->rollupGoals() : $this->goals(),
            'realtime' => $this->realtime(),
        ];
    }

    /**
     * Activity in the last 30 minutes - independent of the selected range.
     * Respects the site filter (an editor watching one site shouldn't see
     * another site's live visitors) but no other filters.
     */
    public function realtime(): array
    {
        // Tighter window than the 30-minute count: the green dot on a page should
        // mean "someone is looking at this right now", not "within the half hour".
        $pages = Hit::query()
            ->when($this->site, fn ($query) => $query->where('site', $this->site))
            ->where('visited_at', '>=', now()->subMinutes(5))
            ->selectRaw('path, COUNT(*) as views')
            ->groupBy('path')
            ->orderByDesc('views')
            ->limit(25)
            ->get()
            ->map(fn ($row) => ['path' => $row->path, 'views' => (int) $row->views])
            ->all();

        $now = $this->activeNow();

        return ['count' => $now['value'], 'unit' => $now['unit'], 'pages' => $pages];
    }

    // ------------------------------------------------------------------
    // Range plumbing
    // ------------------------------------------------------------------

    private function usesRollups(): bool
    {
        // Filters already clamped the range into the raw window.
        return $this->filters === [] && $this->start->lt($this->rawCutoff());
    }

    private function rawCutoff(): Carbon
    {
        return today()->subDays(max((int) config('insights.retention_days', 90), 90))->startOfDay();
    }

    private function oldestDay(): Carbon
    {
        $oldest = collect([
            DB::table('insights_daily_totals')->min('date'),
            Hit::query()->min('visited_at'),
        ])->filter()->map(fn ($date) => Carbon::parse($date))->min();

        return ($oldest ?? now())->startOfDay();
    }

    private function parseDay(?string $date): Carbon
    {
        abort_unless((bool) $date, 422, 'Missing date.');

        return rescue(fn () => Carbon::createFromFormat('Y-m-d', $date)->startOfDay(), fn () => abort(422, 'Invalid date.'), false);
    }

    private function sites(): array
    {
        if (! Site::multiEnabled() || Site::all()->count() < 2) {
            return [];
        }

        return Site::all()->map(fn ($site) => [
            'handle' => $site->handle(),
            'name' => (string) $site->name(),
        ])->values()->all();
    }

    private static function validSite(mixed $handle): ?string
    {
        if (! is_string($handle) || $handle === '') {
            return null;
        }

        return Site::all()->map->handle()->contains($handle) ? $handle : null;
    }

    // ------------------------------------------------------------------
    // Raw source
    // ------------------------------------------------------------------

    private function query()
    {
        $query = Hit::query()->whereBetween('visited_at', [$this->start, $this->end]);

        if ($this->site) {
            $query->where('site', $this->site);
        }

        foreach ($this->filters as $key => $value) {
            $query->where(self::FILTERS[$key], $value);
        }

        return $query;
    }

    private function tiles(): array
    {
        $current = $this->totals($this->query());
        $previous = $this->totals($this->freshRangeQuery($this->previousWindow()));

        $tile = fn (string $key) => [
            'value' => $current[$key],
            'delta' => $previous[$key] > 0
                ? (int) round(($current[$key] - $previous[$key]) / $previous[$key] * 100)
                : null,
        ];

        $engagement = $this->engagement($this->query());
        $previousEngagement = $this->engagement($this->freshRangeQuery($this->previousWindow()));

        $now = $this->activeNow();

        return [
            'pageviews' => $tile('views'),
            'visitors' => $tile('visitors') + ['approx' => false],
            'sessions' => $tile('sessions'),
            'bounce_rate' => $this->engagementTile($engagement, $previousEngagement, 'bounce_rate'),
            'duration' => $this->engagementTile($engagement, $previousEngagement, 'duration'),
            'now' => ['value' => $now['value'], 'unit' => $now['unit'], 'delta' => null],
        ];
    }

    private function previousWindow(): array
    {
        $length = max($this->start->diffInSeconds($this->end), 1);

        return [$this->start->copy()->subSeconds($length), $this->start];
    }

    /** A query with the site + dimension filters but a caller-chosen window. */
    private function freshRangeQuery(array $window)
    {
        $query = Hit::query()->whereBetween('visited_at', $window);

        if ($this->site) {
            $query->where('site', $this->site);
        }

        foreach ($this->filters as $key => $value) {
            $query->where(self::FILTERS[$key], $value);
        }

        return $query;
    }

    private function totals($query): array
    {
        $row = $query->selectRaw('COUNT(*) as views, COUNT(DISTINCT visitor_id) as visitors, COUNT(DISTINCT session_id) as sessions')->first();

        return [
            'views' => (int) $row->views,
            'visitors' => (int) $row->visitors,
            'sessions' => (int) $row->sessions,
        ];
    }

    /**
     * Bounce rate + average visit duration over CONSENTED sessions (the only
     * honest denominator; anonymous hits carry no session). Duration is
     * last-hit minus first-hit per session - single-page visits count as 0,
     * like every other analytics tool.
     *
     * @return array{sessions: int, bounce_rate: ?float, duration: ?int}
     */
    private function engagement($query): array
    {
        $sessions = $query
            ->whereNotNull('session_id')
            ->selectRaw('session_id, COUNT(*) as pageviews, '.$this->sessionSecondsExpression().' as duration')
            ->groupBy('session_id');

        $row = DB::query()->fromSub($sessions, 'sessions')
            ->selectRaw('COUNT(*) as sessions, SUM(CASE WHEN pageviews = 1 THEN 1 ELSE 0 END) as bounces, AVG(duration) as duration')
            ->first();

        $count = (int) $row->sessions;

        return [
            'sessions' => $count,
            'bounce_rate' => $count > 0 ? round($row->bounces / $count * 100) : null,
            'duration' => $count > 0 ? (int) round($row->duration) : null,
        ];
    }

    private function engagementTile(array $current, array $previous, string $key): array
    {
        return [
            'value' => $current[$key],
            'delta' => $current[$key] !== null && $previous[$key]
                ? (int) round(($current[$key] - $previous[$key]) / $previous[$key] * 100)
                : null,
            'available' => $current[$key] !== null,
        ];
    }

    private function sessionSecondsExpression(): string
    {
        return match (Hit::query()->getConnection()->getDriverName()) {
            'sqlite' => "strftime('%s', MAX(visited_at)) - strftime('%s', MIN(visited_at))",
            'pgsql' => 'EXTRACT(EPOCH FROM (MAX(visited_at) - MIN(visited_at)))',
            default => 'TIMESTAMPDIFF(SECOND, MIN(visited_at), MAX(visited_at))',
        };
    }

    /**
     * Consented visitors active in the last 30 minutes. With anonymous-only
     * traffic there is no honest people-count, so the fallback reports the
     * pageview count AS pageviews (the unit travels with the value) instead of
     * pretending hits are humans.
     *
     * @return array{value: int, unit: string}
     */
    private function activeNow(): array
    {
        $window = Hit::query()
            ->when($this->site, fn ($query) => $query->where('site', $this->site))
            ->where('visited_at', '>=', now()->subMinutes(30));

        $visitors = (int) (clone $window)->distinct()->count('visitor_id');

        if ($visitors > 0) {
            return ['value' => $visitors, 'unit' => 'visitors'];
        }

        return ['value' => (int) $window->count(), 'unit' => 'views'];
    }

    private function timeseries(): array
    {
        $hourly = $this->range === 'today';

        $rows = $this->query()
            ->selectRaw($this->bucketExpression($hourly).' as bucket, COUNT(*) as views')
            ->groupBy('bucket')
            ->pluck('views', 'bucket');

        return $this->buckets($hourly ? 'hour' : 'day', fn ($bucket) => (int) ($rows[$bucket] ?? 0));
    }

    /**
     * Zero-filled chart buckets between start and end. Bucket granularity:
     * hours for today, days for ranges up to ~13 months, months beyond that.
     *
     * @return array{labels: array, views: array}
     */
    private function buckets(string $unit, callable $views): array
    {
        // Laravel 13 no longer syncs Carbon's locale with the app locale, so the
        // CP locale (set per-user by Statamic's Localize middleware) is applied
        // explicitly - otherwise month names always render in English.
        $locale = app()->getLocale();

        $period = CarbonPeriod::create(
            match ($unit) {
                'hour' => $this->start,
                'day' => $this->start->copy()->startOfDay(),
                'month' => $this->start->copy()->startOfMonth(),
            },
            "1 {$unit}",
            $this->end,
        );

        $labels = [];
        $values = [];

        foreach ($period as $moment) {
            $bucket = $moment->format(match ($unit) {
                'hour' => 'Y-m-d H:00',
                'day' => 'Y-m-d',
                'month' => 'Y-m',
            });

            $labels[] = match ($unit) {
                'hour' => $moment->format('H:00'),
                'day' => $moment->locale($locale)->translatedFormat('j M'),
                'month' => $moment->locale($locale)->translatedFormat('M Y'),
            };

            $values[] = $views($bucket);
        }

        return ['labels' => $labels, 'views' => $values];
    }

    private function bucketExpression(bool $hourly): string
    {
        $driver = Hit::query()->getConnection()->getDriverName();

        return match ($driver) {
            'sqlite' => $hourly ? "strftime('%Y-%m-%d %H:00', visited_at)" : "strftime('%Y-%m-%d', visited_at)",
            'pgsql' => $hourly ? "to_char(visited_at, 'YYYY-MM-DD HH24:00')" : "to_char(visited_at, 'YYYY-MM-DD')",
            default => $hourly ? "DATE_FORMAT(visited_at, '%Y-%m-%d %H:00')" : "DATE_FORMAT(visited_at, '%Y-%m-%d')",
        };
    }

    private function pages(): array
    {
        return $this->query()
            ->selectRaw('path, MAX(entry_id) as entry_id, COUNT(*) as views, COUNT(DISTINCT visitor_id) as visitors')
            ->groupBy('path')
            ->orderByDesc('views')
            ->limit(25)
            ->get()
            ->map(fn ($row) => [
                'path' => $row->path,
                'title' => $this->entryTitle($row->entry_id),
                'views' => (int) $row->views,
                'visitors' => (int) $row->visitors,
            ])
            ->all();
    }

    private function entryTitle(?string $entryId): ?string
    {
        return $entryId ? rescue(fn () => Entry::find($entryId)?->value('title'), null, false) : null;
    }

    private function campaigns(): array
    {
        return $this->query()
            ->whereNotNull('utm_campaign')
            ->selectRaw('utm_campaign as campaign, utm_source as source, COUNT(*) as views, COUNT(DISTINCT visitor_id) as visitors')
            ->groupBy('utm_campaign', 'utm_source')
            ->orderByDesc('views')
            ->limit(25)
            ->get()
            ->map(fn ($row) => [
                'campaign' => $row->campaign,
                'source' => $row->source,
                'views' => (int) $row->views,
                'visitors' => (int) $row->visitors,
            ])
            ->all();
    }

    private function referrers(): array
    {
        return $this->query()
            ->whereNotNull('referrer_domain')
            ->selectRaw('referrer_domain as domain, COUNT(*) as views')
            ->groupBy('referrer_domain')
            ->orderByDesc('views')
            ->limit(25)
            ->get()
            ->map(fn ($row) => ['domain' => $row->domain, 'views' => (int) $row->views])
            ->all();
    }

    private function breakdown(string $column): array
    {
        return $this->query()
            ->whereNotNull($column)
            ->selectRaw("{$column} as label, COUNT(*) as count, COUNT(DISTINCT visitor_id) as visitors")
            ->groupBy($column)
            ->orderByDesc('count')
            ->limit(8)
            ->get()
            ->map(fn ($row) => [
                'label' => $row->label,
                'count' => (int) $row->count,
                'visitors' => (int) $row->visitors,
            ])
            ->all();
    }

    private function countries(): array
    {
        return $this->query()
            ->whereNotNull('country')
            ->selectRaw('country as code, COUNT(*) as views, COUNT(DISTINCT visitor_id) as visitors')
            ->groupBy('country')
            ->orderByDesc('views')
            ->limit(50)
            ->get()
            ->map(fn ($row) => [
                'code' => $row->code,
                'views' => (int) $row->views,
                'visitors' => (int) $row->visitors,
            ])
            ->all();
    }

    /**
     * Goals evaluated against their current definitions - retroactive within
     * the raw retention window. Conversion rate is per consented visitor;
     * with anonymous-only traffic it's null (no honest people-denominator).
     */
    private function goals(): array
    {
        $goals = app(GoalRepository::class)->all();

        if ($goals->isEmpty()) {
            return [];
        }

        $totalVisitors = (int) $this->query()->distinct()->count('visitor_id');

        return $goals->map(function (Goal $goal) use ($totalVisitors) {
            $query = $goal->type === 'path'
                ? $this->query()->whereRaw("path like ? escape '!'", [$goal->likePattern()])
                : $this->eventQuery()->where('name', $goal->eventName());

            $row = $query->selectRaw('COUNT(*) as conversions, COUNT(DISTINCT visitor_id) as visitors')->first();

            return [
                'handle' => $goal->handle,
                'name' => $goal->name,
                'type' => $goal->type,
                'conversions' => (int) $row->conversions,
                'visitors' => (int) $row->visitors,
                'rate' => $totalVisitors > 0 ? round($row->visitors / $totalVisitors * 100, 1) : null,
            ];
        })->values()->all();
    }

    private function eventQuery()
    {
        return Event::query()
            ->whereBetween('visited_at', [$this->start, $this->end])
            ->when($this->site, fn ($query) => $query->where('site', $this->site));
    }

    // ------------------------------------------------------------------
    // Rollup source (long unfiltered ranges; today merged in live)
    // ------------------------------------------------------------------

    private function rollupQuery(string $table)
    {
        $query = DB::table($table)->whereBetween('date', [$this->start->toDateString(), $this->end->toDateString()]);

        if ($this->site) {
            $query->where('site', $this->site);
        }

        return $query;
    }

    private function includesToday(): bool
    {
        return $this->end->isToday() || $this->end->isFuture();
    }

    private function todayMetrics(): self
    {
        // A raw-source Metrics for just today, sharing the site filter.
        return new self('today', site: $this->site);
    }

    private function rollupTiles(): array
    {
        $sums = $this->rollupQuery('insights_daily_totals')
            ->selectRaw('SUM(views) as views, SUM(visitors) as visitors, SUM(sessions) as sessions')
            ->first();

        $current = [
            'views' => (int) $sums->views,
            'visitors' => (int) $sums->visitors,
            'sessions' => (int) $sums->sessions,
        ];

        if ($this->includesToday()) {
            $today = $this->totals($this->freshRangeQuery([today()->startOfDay(), now()]));

            foreach ($current as $key => $value) {
                $current[$key] = $value + $today[$key];
            }
        }

        $now = $this->activeNow();

        // No previous-period deltas: "all time" has no previous period, and
        // rebuilding one from rollups for 6m/12m costs more than it informs.
        // Visitors are a sum of daily uniques - flagged approximate.
        return [
            'pageviews' => ['value' => $current['views'], 'delta' => null],
            'visitors' => ['value' => $current['visitors'], 'delta' => null, 'approx' => true],
            'sessions' => ['value' => $current['sessions'], 'delta' => null],
            'bounce_rate' => ['value' => null, 'delta' => null, 'available' => false],
            'duration' => ['value' => null, 'delta' => null, 'available' => false],
            'now' => ['value' => $now['value'], 'unit' => $now['unit'], 'delta' => null],
        ];
    }

    private function rollupTimeseries(): array
    {
        $monthly = $this->start->diffInDays($this->end) > 400;

        $rows = $this->rollupQuery('insights_daily_totals')
            ->selectRaw('date, SUM(views) as views')
            ->groupBy('date')
            ->pluck('views', 'date')
            ->map(fn ($views) => (int) $views);

        if ($this->includesToday()) {
            $today = today()->toDateString();
            $rows[$today] = ($rows[$today] ?? 0) + $this->freshRangeQuery([today()->startOfDay(), now()])->count();
        }

        if (! $monthly) {
            return $this->buckets('day', fn ($bucket) => $rows[$bucket] ?? 0);
        }

        $byMonth = collect($rows)->groupBy(fn ($views, $date) => substr($date, 0, 7))->map->sum();

        return $this->buckets('month', fn ($bucket) => (int) ($byMonth[$bucket] ?? 0));
    }

    private function rollupPages(): array
    {
        $rows = $this->rollupQuery('insights_daily_pages')
            ->selectRaw('path, MAX(entry_id) as entry_id, SUM(views) as views, SUM(visitors) as visitors')
            ->groupBy('path')
            ->orderByDesc(DB::raw('SUM(views)'))
            ->limit(100)
            ->get()
            ->keyBy('path')
            ->map(fn ($row) => [
                'path' => $row->path,
                'entry_id' => $row->entry_id,
                'views' => (int) $row->views,
                'visitors' => (int) $row->visitors,
            ]);

        if ($this->includesToday()) {
            $today = $this->freshRangeQuery([today()->startOfDay(), now()])
                ->selectRaw('path, MAX(entry_id) as entry_id, COUNT(*) as views, COUNT(DISTINCT visitor_id) as visitors')
                ->groupBy('path')
                ->get();

            foreach ($today as $row) {
                $existing = $rows[$row->path] ?? ['path' => $row->path, 'entry_id' => $row->entry_id, 'views' => 0, 'visitors' => 0];
                $existing['views'] += (int) $row->views;
                $existing['visitors'] += (int) $row->visitors;
                $rows[$row->path] = $existing;
            }
        }

        return $rows
            ->sortByDesc('views')
            ->take(25)
            ->values()
            ->map(fn ($row) => [
                'path' => $row['path'],
                'title' => $this->entryTitle($row['entry_id']),
                'views' => $row['views'],
                'visitors' => $row['visitors'],
            ])
            ->all();
    }

    /**
     * A dimension list from the rollups merged with today's raw rows.
     *
     * @return array<array{value: string, secondary: ?string, views: int, visitors: int}>
     */
    private function rollupDimensionRows(string $dimension, string $rawColumn, ?string $rawSecondary = null): array
    {
        $rows = $this->rollupQuery('insights_daily_dims')
            ->where('dimension', $dimension)
            ->selectRaw('value, secondary, SUM(views) as views, SUM(visitors) as visitors')
            ->groupBy('value', 'secondary')
            ->get()
            ->keyBy(fn ($row) => $row->value.'|'.$row->secondary)
            ->map(fn ($row) => [
                'value' => $row->value,
                'secondary' => $row->secondary,
                'views' => (int) $row->views,
                'visitors' => (int) $row->visitors,
            ]);

        if ($this->includesToday()) {
            $secondarySelect = $rawSecondary ? "{$rawSecondary} as secondary" : 'NULL as secondary';

            $today = $this->freshRangeQuery([today()->startOfDay(), now()])
                ->whereNotNull($rawColumn)
                ->selectRaw("{$rawColumn} as value, {$secondarySelect}, COUNT(*) as views, COUNT(DISTINCT visitor_id) as visitors")
                ->groupBy($rawColumn, ...($rawSecondary ? [$rawSecondary] : []))
                ->get();

            foreach ($today as $row) {
                $key = $row->value.'|'.$row->secondary;
                $existing = $rows[$key] ?? ['value' => $row->value, 'secondary' => $row->secondary, 'views' => 0, 'visitors' => 0];
                $existing['views'] += (int) $row->views;
                $existing['visitors'] += (int) $row->visitors;
                $rows[$key] = $existing;
            }
        }

        return $rows->sortByDesc('views')->values()->all();
    }

    private function rollupDimension(string $dimension): array
    {
        $column = match ($dimension) {
            'referrer' => 'referrer_domain',
            'device' => 'device_type',
            default => $dimension,
        };

        $rows = collect($this->rollupDimensionRows($dimension, $column));

        if ($dimension === 'referrer') {
            return $rows->take(25)->map(fn ($row) => ['domain' => $row['value'], 'views' => $row['views']])->all();
        }

        return $rows->take(8)->map(fn ($row) => [
            'label' => $row['value'],
            'count' => $row['views'],
            'visitors' => $row['visitors'],
        ])->all();
    }

    private function rollupCountries(): array
    {
        return collect($this->rollupDimensionRows('country', 'country'))
            ->take(50)
            ->map(fn ($row) => [
                'code' => $row['value'],
                'views' => $row['views'],
                'visitors' => $row['visitors'],
            ])
            ->all();
    }

    private function rollupCampaigns(): array
    {
        return collect($this->rollupDimensionRows('campaign', 'utm_campaign', 'utm_source'))
            ->take(25)
            ->map(fn ($row) => [
                'campaign' => $row['value'],
                'source' => $row['secondary'],
                'views' => $row['views'],
                'visitors' => $row['visitors'],
            ])
            ->all();
    }

    private function rollupGoals(): array
    {
        $goals = app(GoalRepository::class)->all();

        if ($goals->isEmpty()) {
            return [];
        }

        $sums = $this->rollupQuery('insights_daily_goals')
            ->selectRaw('goal, SUM(conversions) as conversions, SUM(visitors) as visitors')
            ->groupBy('goal')
            ->get()
            ->keyBy('goal');

        $today = $this->includesToday()
            ? collect((new self('today', site: $this->site))->goals())->keyBy('handle')
            : collect();

        return $goals->map(fn (Goal $goal) => [
            'handle' => $goal->handle,
            'name' => $goal->name,
            'type' => $goal->type,
            'conversions' => (int) ($sums[$goal->handle]->conversions ?? 0) + (int) ($today[$goal->handle]['conversions'] ?? 0),
            'visitors' => (int) ($sums[$goal->handle]->visitors ?? 0) + (int) ($today[$goal->handle]['visitors'] ?? 0),
            // Rollup visitor sums are per-day uniques; no honest range-wide rate.
            'rate' => null,
        ])->values()->all();
    }
}
