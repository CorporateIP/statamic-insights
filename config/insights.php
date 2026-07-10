<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Raw hit retention
    |--------------------------------------------------------------------------
    |
    | Raw pageview rows are rolled up into daily aggregates every night and
    | pruned after this many days. Aggregates are kept forever (they're tiny).
    | Values below 90 are clamped to 90: the dashboard's longest range reads
    | raw rows, and pruning inside it would punch holes in the statistics.
    |
    */

    'retention_days' => env('INSIGHTS_RETENTION_DAYS', 90),

    /*
    |--------------------------------------------------------------------------
    | Scheduling
    |--------------------------------------------------------------------------
    |
    | The addon schedules its own maintenance (nightly insights:rollup, monthly
    | insights:geo-update) - the site only needs the standard schedule:run
    | cron. Disable to manage scheduling yourself.
    |
    */

    'schedule' => env('INSIGHTS_SCHEDULE', true),

    /*
    |--------------------------------------------------------------------------
    | Excluded paths
    |--------------------------------------------------------------------------
    |
    | Hits for matching paths are ignored. Wildcards supported. The CP, API and
    | Statamic action routes are excluded no matter what's listed here.
    |
    */

    'exclude_paths' => [
        // '/preview/*',
    ],

    /*
    |--------------------------------------------------------------------------
    | Visitor cookie (only set after the site reports consent)
    |--------------------------------------------------------------------------
    */

    'cookie' => [
        'name' => '_insights_id',
        'session_name' => '_insights_s',
        'lifetime_days' => 395, // ~13 months, the common analytics maximum
    ],

    /*
    |--------------------------------------------------------------------------
    | Consent getter (JavaScript)
    |--------------------------------------------------------------------------
    |
    | Name of a window function the tracker calls to know whether cookies are
    | allowed; it should return true (or the string 'accepted'). Can also be
    | set per-site on the tag: {{ insights:tracker consent_getter="..." }}.
    | Cookie banners can additionally call window._insights.consent(bool).
    |
    */

    'consent_js_getter' => null,

    /*
    |--------------------------------------------------------------------------
    | Country resolution
    |--------------------------------------------------------------------------
    |
    | Countries are resolved at ingest from a LOCAL IP database - the visitor
    | IP never leaves the server and is never stored. Download/refresh the
    | database with `php please insights:geo-update` (schedule it monthly).
    |
    */

    'geo' => [
        'enabled' => env('INSIGHTS_GEO_ENABLED', true),
        'database_path' => storage_path('app/insights/country.mmdb'),
    ],

];
