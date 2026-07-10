<?php

namespace CorporateIp\Insights\Dashboard;

use Carbon\CarbonPeriod;
use CorporateIp\Insights\Models\Hit;
use Illuminate\Support\Carbon;
use Statamic\Facades\Entry;

/**
 * Computes every dataset the dashboard needs for one date range, straight from
 * the raw hits table. (Daily rollup tables take over the long ranges in a later
 * stage; at current traffic volumes raw queries are plenty fast.)
 */
class Metrics
{
    public const RANGES = ['today', '7d', '30d', '90d'];

    private Carbon $start;

    private Carbon $end;

    public function __construct(private readonly string $range)
    {
        abort_unless(in_array($range, self::RANGES, true), 422, 'Unknown range.');

        $this->end = now();
        $this->start = match ($range) {
            'today' => now()->startOfDay(),
            '7d' => now()->subDays(7),
            '30d' => now()->subDays(30),
            '90d' => now()->subDays(90),
        };
    }

    public static function make(string $range): array
    {
        return (new self($range))->payload();
    }

    public function payload(): array
    {
        return [
            'range' => $this->range,
            'tiles' => $this->tiles(),
            'timeseries' => $this->timeseries(),
            'pages' => $this->pages(),
            'referrers' => $this->referrers(),
            'devices' => $this->breakdown('device_type'),
            'browsers' => $this->breakdown('browser'),
            'countries' => $this->countries(),
            'campaigns' => $this->campaigns(),
            'realtime' => $this->realtime(),
        ];
    }

    /**
     * Activity in the last 30 minutes — independent of the selected range.
     */
    public function realtime(): array
    {
        $pages = Hit::query()
            ->where('visited_at', '>=', now()->subMinutes(30))
            ->selectRaw('path, COUNT(*) as views')
            ->groupBy('path')
            ->orderByDesc('views')
            ->limit(6)
            ->get()
            ->map(fn ($row) => ['path' => $row->path, 'views' => (int) $row->views])
            ->all();

        return ['count' => $this->activeNow(), 'pages' => $pages];
    }

    private function query()
    {
        return Hit::query()->whereBetween('visited_at', [$this->start, $this->end]);
    }

    private function previousQuery()
    {
        $length = $this->start->diffInSeconds($this->end);

        return Hit::query()->whereBetween('visited_at', [
            $this->start->copy()->subSeconds($length),
            $this->start,
        ]);
    }

    private function tiles(): array
    {
        $current = $this->totals($this->query());
        $previous = $this->totals($this->previousQuery());

        $tile = fn (string $key) => [
            'value' => $current[$key],
            'delta' => $previous[$key] > 0
                ? (int) round(($current[$key] - $previous[$key]) / $previous[$key] * 100)
                : null,
        ];

        return [
            'pageviews' => $tile('views'),
            'visitors' => $tile('visitors'),
            'sessions' => $tile('sessions'),
            'now' => ['value' => $this->activeNow(), 'delta' => null],
        ];
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
     * Consented visitors active in the last 30 minutes; when there are none but
     * anonymous pageviews are coming in, show those so "now" never lies dead.
     */
    private function activeNow(): int
    {
        $window = Hit::query()->where('visited_at', '>=', now()->subMinutes(30));

        $visitors = (int) (clone $window)->distinct()->count('visitor_id');

        return $visitors > 0 ? $visitors : (int) $window->count();
    }

    private function timeseries(): array
    {
        $hourly = $this->range === 'today';

        $rows = $this->query()
            ->selectRaw($this->bucketExpression($hourly).' as bucket, COUNT(*) as views')
            ->groupBy('bucket')
            ->pluck('views', 'bucket');

        $labels = [];
        $views = [];

        $period = CarbonPeriod::create(
            $hourly ? $this->start : $this->start->copy()->startOfDay(),
            $hourly ? '1 hour' : '1 day',
            $this->end,
        );

        foreach ($period as $moment) {
            $bucket = $moment->format($hourly ? 'Y-m-d H:00' : 'Y-m-d');
            $labels[] = $hourly ? $moment->format('H:00') : $moment->format('j M');
            $views[] = (int) ($rows[$bucket] ?? 0);
        }

        return ['labels' => $labels, 'views' => $views];
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
            ->limit(10)
            ->get()
            ->map(fn ($row) => [
                'path' => $row->path,
                'title' => $row->entry_id ? rescue(fn () => Entry::find($row->entry_id)?->value('title'), null, false) : null,
                'views' => (int) $row->views,
                'visitors' => (int) $row->visitors,
            ])
            ->all();
    }

    private function campaigns(): array
    {
        return $this->query()
            ->whereNotNull('utm_campaign')
            ->selectRaw('utm_campaign as campaign, utm_source as source, COUNT(*) as views, COUNT(DISTINCT visitor_id) as visitors')
            ->groupBy('utm_campaign', 'utm_source')
            ->orderByDesc('views')
            ->limit(10)
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
            ->limit(10)
            ->get()
            ->map(fn ($row) => ['domain' => $row->domain, 'views' => (int) $row->views])
            ->all();
    }

    private function breakdown(string $column): array
    {
        return $this->query()
            ->whereNotNull($column)
            ->selectRaw("{$column} as label, COUNT(*) as count")
            ->groupBy($column)
            ->orderByDesc('count')
            ->limit(8)
            ->get()
            ->map(fn ($row) => ['label' => $row->label, 'count' => (int) $row->count])
            ->all();
    }

    private function countries(): array
    {
        return $this->query()
            ->whereNotNull('country')
            ->selectRaw('country as code, COUNT(*) as views')
            ->groupBy('country')
            ->orderByDesc('views')
            ->limit(10)
            ->get()
            ->map(fn ($row) => ['code' => $row->code, 'views' => (int) $row->views])
            ->all();
    }
}
