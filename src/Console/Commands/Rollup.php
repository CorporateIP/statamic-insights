<?php

namespace CorporateIp\Insights\Console\Commands;

use Carbon\CarbonPeriod;
use CorporateIp\Insights\Goals\Goal;
use CorporateIp\Insights\Goals\GoalRepository;
use CorporateIp\Insights\Models\Event;
use CorporateIp\Insights\Models\Hit;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Aggregates raw hits + events into the daily rollup tables, then prunes raw
 * rows past the retention window. Idempotent: a day is delete-and-rebuilt on
 * re-run, and pruning never touches days that haven't been rolled up yet.
 *
 * Every rollup row carries the site handle; "all sites" reads SUM across
 * sites (a same-day cross-site visitor counts once per site - negligible,
 * and arguably the honest per-site number).
 *
 * Scheduled nightly by the addon (config: insights.schedule).
 */
class Rollup extends Command
{
    protected $signature = 'insights:rollup {--date= : Roll up one specific day (Y-m-d) instead of every pending day}';

    protected $description = 'Aggregate raw Insights hits into daily rollups and prune rows past retention';

    public function handle(): int
    {
        $days = $this->pendingDays();

        foreach ($days as $day) {
            $this->components->task('Rolling up '.$day->toDateString(), fn () => $this->rollUpDay($day) ?? true);
        }

        $pruned = $this->prune();

        $this->components->info(sprintf('%d day(s) rolled up, %d raw row(s) pruned.', count($days), $pruned));

        return self::SUCCESS;
    }

    /** @return array<Carbon> */
    private function pendingDays(): array
    {
        if ($date = $this->option('date')) {
            return [Carbon::parse($date)->startOfDay()];
        }

        $end = today()->subDay(); // only complete days

        $lastRolled = DB::table('insights_daily_totals')->max('date');

        if ($lastRolled) {
            $start = Carbon::parse($lastRolled)->addDay();
        } else {
            $oldestHit = Hit::query()->min('visited_at');

            if (! $oldestHit) {
                return [];
            }

            $start = Carbon::parse($oldestHit)->startOfDay();
        }

        if ($start->gt($end)) {
            return [];
        }

        // Safety cap; the next nightly run picks up where this one stopped.
        return array_slice(iterator_to_array(CarbonPeriod::create($start, '1 day', $end)), 0, 400);
    }

    private function rollUpDay(Carbon $day): void
    {
        $date = $day->toDateString();
        $window = [$day->copy()->startOfDay(), $day->copy()->endOfDay()];
        $hits = fn () => Hit::query()->whereBetween('visited_at', $window);
        $events = fn () => Event::query()->whereBetween('visited_at', $window);

        DB::transaction(function () use ($date, $hits, $events, $window) {
            foreach (['insights_daily_totals', 'insights_daily_pages', 'insights_daily_dims', 'insights_daily_goals'] as $table) {
                DB::table($table)->where('date', $date)->delete();
            }

            $totals = $hits()
                ->selectRaw('site, COUNT(*) as views, COUNT(DISTINCT visitor_id) as visitors, COUNT(DISTINCT session_id) as sessions')
                ->groupBy('site')
                ->get()
                ->map(fn ($row) => [
                    'date' => $date,
                    'site' => $row->site,
                    'views' => (int) $row->views,
                    'visitors' => (int) $row->visitors,
                    'sessions' => (int) $row->sessions,
                ])
                ->all();

            // Zero-traffic days still get a totals row - it doubles as the
            // "this day is done" bookmark for pendingDays() and prune().
            DB::table('insights_daily_totals')->insert($totals ?: [[
                'date' => $date,
                'site' => null,
                'views' => 0,
                'visitors' => 0,
                'sessions' => 0,
            ]]);

            $pages = $hits()
                ->selectRaw('site, path, MAX(entry_id) as entry_id, COUNT(*) as views, COUNT(DISTINCT visitor_id) as visitors')
                ->groupBy('site', 'path')
                ->get()
                ->map(fn ($row) => [
                    'date' => $date,
                    'site' => $row->site,
                    'path' => $row->path,
                    'entry_id' => $row->entry_id,
                    'views' => (int) $row->views,
                    'visitors' => (int) $row->visitors,
                ])
                ->all();

            foreach (array_chunk($pages, 500) as $chunk) {
                DB::table('insights_daily_pages')->insert($chunk);
            }

            $dimensions = [
                'referrer' => 'referrer_domain',
                'device' => 'device_type',
                'browser' => 'browser',
                'os' => 'os',
                'country' => 'country',
            ];

            $rows = [];

            foreach ($dimensions as $dimension => $column) {
                $grouped = $hits()
                    ->whereNotNull($column)
                    ->selectRaw("site, {$column} as value, COUNT(*) as views, COUNT(DISTINCT visitor_id) as visitors")
                    ->groupBy('site', $column)
                    ->get();

                foreach ($grouped as $row) {
                    $rows[] = [
                        'date' => $date,
                        'site' => $row->site,
                        'dimension' => $dimension,
                        'value' => $row->value,
                        'secondary' => null,
                        'views' => (int) $row->views,
                        'visitors' => (int) $row->visitors,
                    ];
                }
            }

            $campaigns = $hits()
                ->whereNotNull('utm_campaign')
                ->selectRaw('site, utm_campaign as value, utm_source as secondary, COUNT(*) as views, COUNT(DISTINCT visitor_id) as visitors')
                ->groupBy('site', 'utm_campaign', 'utm_source')
                ->get();

            foreach ($campaigns as $row) {
                $rows[] = [
                    'date' => $date,
                    'site' => $row->site,
                    'dimension' => 'campaign',
                    'value' => $row->value,
                    'secondary' => $row->secondary,
                    'views' => (int) $row->views,
                    'visitors' => (int) $row->visitors,
                ];
            }

            // Custom events keep long-range counts as an 'event' dimension.
            $eventCounts = $events()
                ->selectRaw('site, name as value, COUNT(*) as views, COUNT(DISTINCT visitor_id) as visitors')
                ->groupBy('site', 'name')
                ->get();

            foreach ($eventCounts as $row) {
                $rows[] = [
                    'date' => $date,
                    'site' => $row->site,
                    'dimension' => 'event',
                    'value' => $row->value,
                    'secondary' => null,
                    'views' => (int) $row->views,
                    'visitors' => (int) $row->visitors,
                ];
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('insights_daily_dims')->insert($chunk);
            }

            $this->rollUpGoals($date, $window);
        });
    }

    /**
     * Goals are evaluated against their CURRENT definitions - days inside raw
     * retention stay retroactive on re-rollup; older days keep whatever
     * definition was live when they were rolled up.
     */
    private function rollUpGoals(string $date, array $window): void
    {
        $rows = [];

        foreach (app(GoalRepository::class)->all() as $goal) {
            /** @var Goal $goal */
            $query = $goal->type === 'path'
                ? Hit::query()->whereBetween('visited_at', $window)->whereRaw("path like ? escape '\\'", [$goal->likePattern()])
                : Event::query()->whereBetween('visited_at', $window)->where('name', $goal->eventName());

            $grouped = $query
                ->selectRaw('site, COUNT(*) as conversions, COUNT(DISTINCT visitor_id) as visitors')
                ->groupBy('site')
                ->get();

            foreach ($grouped as $row) {
                $rows[] = [
                    'date' => $date,
                    'site' => $row->site,
                    'goal' => $goal->handle,
                    'conversions' => (int) $row->conversions,
                    'visitors' => (int) $row->visitors,
                ];
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('insights_daily_goals')->insert($chunk);
        }
    }

    private function prune(): int
    {
        $lastRolled = DB::table('insights_daily_totals')->max('date');

        if (! $lastRolled) {
            return 0;
        }

        // Filtered dashboard views read RAW rows only; pruning below the 90-day
        // preset would punch holes in them. Clamp to at least 90.
        $configured = (int) config('insights.retention_days', 90);
        $retention = max($configured, 90);

        if ($configured < $retention) {
            $this->components->warn("retention_days={$configured} is below the dashboard's longest raw range; using {$retention}.");
        }

        // Whichever is OLDER wins: the retention cutoff, or the first day that
        // hasn't been rolled up yet - un-rolled raw data is never deleted.
        $cutoff = today()
            ->subDays($retention)
            ->min(Carbon::parse($lastRolled)->addDay())
            ->startOfDay();

        return Hit::query()->where('visited_at', '<', $cutoff)->delete()
            + Event::query()->where('visited_at', '<', $cutoff)->delete();
    }
}
