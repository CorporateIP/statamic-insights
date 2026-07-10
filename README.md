# Statamic Insights

First-party analytics for Statamic 6 with a full Control Panel dashboard. By Corporate IP.

- Hybrid tracking: complete pageview counts for everyone; unique visitors/sessions via a
  first-party cookie for visitors who accepted cookies.
- JS beacon (static-caching proof), server-side bot filtering, no third-party requests.
- Country stats from a local IP database — visitor IPs are never stored or shared.
- Dashboard: pageviews, uniques, sessions, realtime, top pages, referrers, countries,
  devices, UTM campaigns, per-entry stats. Plus a CP-home widget (`type: insights`).

## Installing into a site

1. `composer require corporateip/statamic-insights` (path or VCS repository).
2. `php artisan migrate` — creates the hits + daily rollup tables.
3. Add the tracker to the site layout:
   `{{ insights:tracker consent_getter="yourConsentFunction" }}` — the getter is a window
   function returning `true`/`'accepted'` while cookies are allowed; the banner can also call
   `window._insights.consent(bool)` directly.
4. `php artisan insights:geo-update` once for country stats (refreshes itself monthly).
5. Grant editors the **View Insights** permission; optionally add the dashboard widget to
   `config/statamic/cp.php`: `['type' => 'insights', 'width' => 100]`.

Maintenance is self-scheduled (needs the standard `schedule:run` cron): nightly
`insights:rollup` aggregates hits into daily tables and prunes raw rows past
`retention_days` (default 90 — aggregates are kept forever), monthly `insights:geo-update`.
Disable with `INSIGHTS_SCHEDULE=false` to schedule manually.

## Tests

```bash
composer install
./vendor/bin/phpunit
```

## Local development

```bash
composer install               # brings statamic/cms (needed for the @statamic/cms npm package)
npm install
npm run build                  # compiled CP assets land in dist/ (committed)
```

> **After every `npm run build`**, the consuming site serves a *published copy* of the
> assets — refresh it there or the CP keeps loading the previous bundle:
>
> ```bash
> php artisan vendor:publish --tag=statamic-insights --force
> ```

Consume from a site via a composer path repository:

```json
"repositories": [{ "type": "path", "url": "../statamic-insights" }]
```

```bash
composer require corporateip/statamic-insights:@dev
```
