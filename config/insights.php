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
    | Excluded traffic
    |--------------------------------------------------------------------------
    |
    | exclude_cp_users drops hits from logged-in users who can access the
    | Control Panel, so editors browsing their own site don't inflate the
    | numbers. exclude_ips drops hits from matching IPs (wildcards supported,
    | e.g. '192.168.*'). Individual visitors can also exclude their own browser
    | with: localStorage.setItem('insights_ignore', 'true').
    |
    */

    'exclude_cp_users' => env('INSIGHTS_EXCLUDE_CP_USERS', true),

    'exclude_ips' => [
        // '203.0.113.7',
    ],

    /*
    |--------------------------------------------------------------------------
    | 404 tracking
    |--------------------------------------------------------------------------
    |
    | Records page-not-found responses (as the built-in `404` event) so broken
    | links show up in the dashboard. Asset-like URLs (with a file extension)
    | and crawler traffic are ignored.
    |
    */

    'track_404' => env('INSIGHTS_TRACK_404', true),

    /*
    |--------------------------------------------------------------------------
    | Entry action
    |--------------------------------------------------------------------------
    |
    | Adds a "View in Insights" action to every entry (list + publish form)
    | that opens the dashboard filtered to that entry's URL.
    |
    */

    'entry_action' => true,

    /*
    |--------------------------------------------------------------------------
    | Flat-file storage
    |--------------------------------------------------------------------------
    |
    | Goals and Insights settings are stored as YAML here - version-controlled
    | alongside the rest of your Statamic content.
    |
    */

    'storage_path' => base_path('content/insights'),

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
