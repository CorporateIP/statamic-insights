<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Raw hit retention
    |--------------------------------------------------------------------------
    |
    | Raw pageview rows are rolled up into daily aggregates every night and
    | pruned after this many days. Aggregates are kept forever (they're tiny).
    |
    */

    'retention_days' => env('INSIGHTS_RETENTION_DAYS', 90),

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
        'lifetime_days' => 395, // ~13 months, the common analytics maximum
    ],

    /*
    |--------------------------------------------------------------------------
    | Country resolution
    |--------------------------------------------------------------------------
    |
    | Countries are resolved at ingest from a LOCAL IP database — the visitor
    | IP never leaves the server and is never stored. Download/refresh the
    | database with `php please insights:geo-update` (schedule it monthly).
    |
    */

    'geo' => [
        'enabled' => env('INSIGHTS_GEO_ENABLED', true),
        'database_path' => storage_path('app/insights/country.mmdb'),
    ],

];
