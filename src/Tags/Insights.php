<?php

namespace CorporateIp\Insights\Tags;

use CorporateIp\Insights\Models\Hit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Statamic\Facades\Entry;
use Statamic\Tags\Tags;

class Insights extends Tags
{
    /**
     * {{ insights:tracker consent_getter="cookieConsent" }}
     *
     * Renders the tracker script tag. `consent_getter` names a window function
     * that returns true|'accepted' while cookies are allowed - the tracker
     * checks it on every pageview and only then uses visitor/session cookies.
     */
    public function tracker(): string
    {
        $getter = $this->params->get('consent_getter', config('insights.consent_js_getter'));

        $attributes = $getter ? ' data-consent-getter="'.e($getter).'"' : '';

        return '<script src="/!/statamic-insights/tracker.js" defer'.$attributes.'></script>';
    }

    /**
     * {{ insights:popular limit="5" days="30" collection="blog" }}
     *   {{ title }} - {{ views }} views
     * {{ /insights:popular }}
     *
     * The most-viewed entries over the last N days, straight from the daily
     * rollups (+ raw rows for days not yet rolled up). Only pageviews that
     * resolved to an entry count; deleted or unpublished entries drop out.
     */
    public function popular(): array
    {
        $limit = (int) $this->params->get('limit', 5);
        $days = max((int) $this->params->get('days', 30), 1);
        $collection = $this->params->get('collection');
        $site = $this->params->get('site');

        $key = sprintf('insights.popular.%s', md5(json_encode([$limit, $days, $collection, $site])));

        return Cache::remember($key, 300, function () use ($limit, $days, $collection, $site) {
            $from = today()->subDays($days - 1);

            $views = collect(DB::table('insights_daily_pages')
                ->where('date', '>=', $from->toDateString())
                ->whereNotNull('entry_id')
                ->when($site, fn ($query) => $query->where('site', $site))
                ->selectRaw('entry_id, SUM(views) as views')
                ->groupBy('entry_id')
                ->pluck('views', 'entry_id'))
                ->map(fn ($views) => (int) $views);

            // Days the nightly rollup hasn't covered yet (always today, more if
            // the scheduler lagged) come from the raw table.
            $lastRolled = DB::table('insights_daily_totals')->max('date');
            $rawFrom = $lastRolled
                ? Carbon::parse($lastRolled)->addDay()->max($from)
                : $from;

            $raw = Hit::query()
                ->where('visited_at', '>=', $rawFrom->startOfDay())
                ->whereNotNull('entry_id')
                ->when($site, fn ($query) => $query->where('site', $site))
                ->selectRaw('entry_id, COUNT(*) as views')
                ->groupBy('entry_id')
                ->pluck('views', 'entry_id');

            foreach ($raw as $entryId => $count) {
                $views[$entryId] = ($views[$entryId] ?? 0) + (int) $count;
            }

            return $views
                ->sortDesc()
                ->take($limit * 3) // headroom for deleted/unpublished entries
                ->map(function ($views, $entryId) use ($collection) {
                    $entry = rescue(fn () => Entry::find($entryId), null, false);

                    if (! $entry || ! $entry->published() || ($collection && $entry->collectionHandle() !== $collection)) {
                        return null;
                    }

                    return array_merge($entry->toAugmentedArray(), ['views' => $views]);
                })
                ->filter()
                ->take($limit)
                ->values()
                ->all();
        });
    }
}
