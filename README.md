# Statamic Insights

First-party analytics for Statamic 6 with a full Control Panel dashboard. By Corporate IP.

- Hybrid tracking: complete pageview counts for everyone; unique visitors/sessions via a
  first-party cookie for visitors who accepted cookies.
- JS beacon (static-caching proof), server-side bot filtering, no third-party requests.
- Country stats from a local IP database - visitor IPs are never stored or shared.
- Dashboard: pageviews, uniques, sessions, realtime, top pages, referrers, countries,
  devices, UTM campaigns, per-entry stats. Plus a CP-home widget (`type: insights`).
- Speaks every language Statamic itself ships (28 + English) and follows each CP
  user's own language preference - labels, dates, numbers and country names.
- Goals & conversions (page visits, custom events, and Statamic form submissions -
  the latter tracked server-side with zero JavaScript), managed in the CP and
  stored as version-controlled YAML.
- Bounce rate, visit duration, one-click filtering, custom date ranges up to
  all-time (long ranges read tiny daily aggregates), CSV export, 404 tracking,
  multi-site support, and weekly/monthly email reports.
- Statamic-native: a "View in Insights" action on every entry, and a
  `{{ insights:popular }}` tag for "most read" lists on the front end.
- Editors browsing their own site aren't counted (logged-in CP users are
  excluded by default; IP list + per-browser opt-out included).

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
`retention_days` (default 90, clamped to a minimum of 90 - aggregates are kept
forever), monthly `insights:geo-update`. Disable with `INSIGHTS_SCHEDULE=false`
to schedule manually.

Two things worth knowing:

- **Timezone**: all buckets and windows follow your app's `timezone` config. On
  Laravel's default (`UTC`) the "Today" chart shows UTC hours - set
  `app.timezone` (e.g. `Europe/Amsterdam`) for local days.
- **Cookie policy**: after installing, document the two first-party cookies in
  your site's cookie policy: `_insights_id` (visitor recognition, 13 months) and
  `_insights_s` (session, 30 minutes) - both only set after consent.

## Goals, events and reports

**Goals** live in the CP (Insights → cog icon, needs the *Configure Insights*
permission) and are stored in `content/insights/goals.yaml`. Three types:

- **Page visit** - a path, wildcards allowed (`/bedankt`, `/docs/*`). Retroactive:
  a new goal immediately shows historic conversions within the retention window.
- **Custom event** - fired from your front end:
  `window._insights.event('newsletter-signup', { plan: 'pro' })`.
- **Form submission** - pick a Statamic form; submissions convert server-side,
  so no JavaScript is involved and the static cache doesn't matter.

**Email reports** (same settings screen): weekly and/or monthly digest to any
addresses - recipients don't need a CP account.

**Popular entries** on the front end:

```antlers
{{ insights:popular collection="blog" limit="5" days="30" }}
    <a href="{{ url }}">{{ title }}</a> ({{ views }})
{{ /insights:popular }}
```

**Excluding yourselves**: logged-in CP users are never counted (disable via
`insights.exclude_cp_users`), IPs can be listed in `insights.exclude_ips`, and
any individual browser opts out with
`localStorage.setItem('insights_ignore', 'true')`.

## Languages

The dashboard and widget render in the language each CP user has set (their
Statamic `locale` preference, falling back to the app locale) - not just the site
default. That covers UI labels, chart date labels, number formatting and localized
country names, including right-to-left languages (Arabic, Persian).

Insights ships translations for every language Statamic itself ships: `ar`, `az`,
`cs`, `da`, `de`, `de_CH`, `es`, `et`, `fa`, `fr`, `hu`, `id`, `it`, `ja`, `ms`,
`nb`, `nl`, `pl`, `pt`, `pt_BR`, `ru`, `sl`, `sv`, `tr`, `uk`, `vi`, `zh_CN`,
`zh_TW` (English is the source language). Untranslated locales fall back to
English.

To override a string (or add a locale we don't ship), put the English source
string as a key in your app's own `lang/{locale}.json` - app translations win
over the addon's:

```json
{
    "Pageviews": "Impressies"
}
```

`tests/TranslationsTest.php` enforces that every locale file stays complete, so a
new UI string cannot ship half-translated.

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
