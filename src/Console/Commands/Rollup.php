<?php

namespace CorporateIp\Insights\Console\Commands;

use Carbon\CarbonPeriod;
use CorporateIp\Insights\Models\Hit;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Aggregates raw hits into the daily rollup tables, then prunes raw rows past
 * the retention window. Idempotent: a day is delete-and-rebuilt on re-run, and
 * pruning never touches days that haven't been rolled up yet.
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

        $this->components->info(sprintf('%d day(s) rolled up, %d raw hit(s) pruned.', count($days), $pruned));

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

        DB::transaction(function () use ($date, $hits) {
            DB::table('insights_daily_totals')->where('date', $date)->delete();
            DB::table('insights_daily_pages')->where('date', $date)->delete();
            DB::table('insights_daily_dims')->where('date', $date)->delete();

            $totals = $hits()
                ->selectRaw('COUNT(*) as views, COUNT(DISTINCT visitor_id) as visitors, COUNT(DISTINCT session_id) as sessions')
                ->first();

            // Zero-traffic days still get a totals row — it doubles as the
            // "this day is done" bookmark for pendingDays() and prune().
            DB::table('insights_daily_totals')->insert([
                'date' => $date,
                'views' => (int) $totals->views,
                'visitors' => (int) $totals->visitors,
                'sessions' => (int) $totals->sessions,
            ]);

            $pages = $hits()
                ->selectRaw('path, MAX(entry_id) as entry_id, COUNT(*) as views, COUNT(DISTINCT visitor_id) as visitors')
                ->groupBy('path')
                ->get()
                ->map(fn ($row) => [
                    'date' => $date,
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
                    ->selectRaw("{$column} as value, COUNT(*) as views, COUNT(DISTINCT visitor_id) as visitors")
                    ->groupBy($column)
                    ->get();

                foreach ($grouped as $row) {
                    $rows[] = [
                        'date' => $date,
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
                ->selectRaw('utm_campaign as value, utm_source as secondary, COUNT(*) as views, COUNT(DISTINCT visitor_id) as visitors')
                ->groupBy('utm_campaign', 'utm_source')
                ->get();

            foreach ($campaigns as $row) {
                $rows[] = [
                    'date' => $date,
                    'dimension' => 'campaign',
                    'value' => $row->value,
                    'secondary' => $row->secondary,
                    'views' => (int) $row->views,
                    'visitors' => (int) $row->visitors,
                ];
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('insights_daily_dims')->insert($chunk);
            }
        });
    }

    private function prune(): int
    {
        $lastRolled = DB::table('insights_daily_totals')->max('date');

        if (! $lastRolled) {
            return 0;
        }

        // Whichever is OLDER wins: the retention cutoff, or the first day that
        // hasn't been rolled up yet — un-rolled raw data is never deleted.
        $cutoff = today()
            ->subDays((int) config('insights.retention_days', 90))
            ->min(Carbon::parse($lastRolled)->addDay())
            ->startOfDay();

        return Hit::query()->where('visited_at', '<', $cutoff)->delete();
    }
}
