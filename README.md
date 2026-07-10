# Statamic Insights

First-party analytics for Statamic 6 with a full Control Panel dashboard. By Corporate IP.

- Hybrid tracking: complete pageview counts for everyone; unique visitors/sessions via a
  first-party cookie for visitors who accepted cookies.
- JS beacon (static-caching proof), server-side bot filtering, no third-party requests.
- Country stats from a local IP database - visitor IPs are never stored or shared.
- Dashboard: pageviews, uniques, sessions, realtime, top pages, referrers, countries,
  devices, UTM campaigns, per-entry stats. Plus a CP-home widget (`type: insights`).

## Installing into a site

1. `composer require corporateip/statamic-insights`
2. `php artisan migrate` (creates the hits + daily rollup tables).
3. Add the tracker to the site layout, just before `</body>` (see below).
4. `php artisan insights:geo-update` once for country stats (refreshes itself monthly).
5. Grant editors the **View Insights** permission; optionally add the dashboard widget to
   `config/statamic/cp.php`: `['type' => 'insights', 'width' => 100]`.

## The tracker and cookie consent

The minimal setup is just:

```antlers
{{ insights:tracker }}
```

This runs in anonymous mode: every pageview is counted, but no cookies are set and
nobody is tracked individually. The dashboard then shows pageviews, pages, referrers,
countries and devices, but "unique visitors" and "sessions" stay at zero.

To unlock visitor and session stats, Insights needs to know when a visitor has
accepted cookies. It never decides that itself: your cookie banner stays in charge,
and Insights only listens. There are two ways to tell it, and a typical site wires
both:

**1. The `consent_getter` parameter.** Name a global JavaScript function that answers
the question "are cookies allowed right now?". The tracker calls it on every pageview
and treats `true` or the string `'accepted'` as a yes. For example, if your banner
stores the visitor's choice in localStorage:

```js
// Your site's own code, whatever the cookie banner already uses:
window.cookieConsent = function () {
    return localStorage.getItem('cookie_consent'); // 'accepted' | 'declined' | null
};
```

then this is the whole integration:

```antlers
{{ insights:tracker consent_getter="cookieConsent" }}
```

Visitors whose getter answers yes get a first-party visitor cookie (13 months) and a
rolling 30-minute session cookie; everyone else keeps being counted anonymously.

**2. The `window._insights.consent()` call.** The getter only runs on the next
pageview, so the page where someone clicks Accept would not react until they
navigate. To apply a choice instantly, call the tracker directly from the banner's
button handlers:

```js
acceptButton.addEventListener('click', () => window._insights.consent(true));   // sets the cookies now
declineButton.addEventListener('click', () => window._insights.consent(false)); // deletes them now
```

`consent(false)` also covers withdrawal: if a visitor changes their mind later, the
cookies are removed on the spot and that visitor goes back to anonymous counting.

In short: the getter covers every future pageview, the direct call makes the current
page react immediately.

Maintenance is self-scheduled (needs the standard `schedule:run` cron): nightly
`insights:rollup` aggregates hits into daily tables and prunes raw rows past
`retention_days` (default 90 - aggregates are kept forever), monthly `insights:geo-update`.
Disable with `INSIGHTS_SCHEDULE=false` to schedule manually.

## Credits

- Country flags: [circle-flags](https://github.com/HatScripts/circle-flags) (MIT), bundled
  and served first-party from `/vendor/statamic-insights/flags/`.
- Country database: [DB-IP Country Lite](https://db-ip.com) (CC-BY-4.0), "IP Geolocation by DB-IP".

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
> assets - refresh it there or the CP keeps loading the previous bundle:
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
