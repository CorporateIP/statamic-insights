# Changelog

All notable changes to Statamic Insights are documented here.

## v1.0.2 - 2026-07-21

### Fixed
- **Path goals no longer 500 the dashboard and nightly rollup on MySQL/MariaDB.**
  The goal path `LIKE` query used `ESCAPE '\'`; SQLite accepts a lone backslash
  there but MariaDB/MySQL don't (the backslash escapes the closing quote → SQL
  syntax error 1064), so the dashboard errored as soon as a path goal existed and
  `insights:rollup` would fail whenever one was defined. The `LIKE` escape
  character is now `!`, which is literal on every driver. (SQLite-only test
  coverage didn't surface this.)

## v1.0.1 - 2026-07-21

### Fixed
- **`visited_at` no longer silently resets on MySQL/MariaDB.** With
  `explicit_defaults_for_timestamp` OFF (the MariaDB default before 10.10), a bare
  `TIMESTAMP` column implicitly gains `ON UPDATE CURRENT_TIMESTAMP`, so v1.0.0's
  multisite `site` backfill rewrote every historical hit time to the moment the
  migration ran. `insights_hits.visited_at` and `insights_events.visited_at` are
  now `DATETIME` (no auto-update); the site backfill assigns `visited_at` to itself
  to suppress the reset; and a new migration converts the columns to `DATETIME` on
  already-migrated installs. Times overwritten by v1.0.0 can't be recovered without
  a pre-upgrade database backup.

## v1.0.0

### Added
- **Goals & conversions**: CP-managed goals (page visit, custom event, or Statamic
  form submission), evaluated retroactively within retention and rolled up nightly.
  Stored as flat-file YAML in `content/insights/` - version-controlled like content.
- **Custom events**: `window._insights.event('name', {props})` beacon endpoint.
  Reserved names (`404`, `form:*`) can't be forged from the browser.
- **Form conversions with zero JavaScript**: every Statamic form submission is
  recorded server-side as a `form:{handle}` event - works behind the static cache.
- **404 tracking**: page-not-found responses recorded via terminable middleware
  (asset URLs and crawlers ignored; `insights.track_404` to disable).
- **Longer ranges**: 6/12 months, all time, and custom from-to ranges read the
  daily rollups (with today merged in live); rollup-sourced visitor counts are
  flagged approximate instead of pretending to deduplicate.
- **One-click filters**: click a page/referrer/country/campaign row to filter the
  whole dashboard; filters stack and read raw rows (range clamps to retention).
- **Bounce rate + average visit duration** tiles, computed from consented
  sessions with honest unavailability on aggregated ranges.
- **Multi-site**: hits/events/rollups carry the site handle; a site switcher
  appears on multisite installs.
- **Operating systems** donut alongside devices and browsers.
- **CSV export** on every dashboard card.
- **Email reports**: weekly/monthly digest to CP-managed recipients (no CP
  account needed), self-scheduled; `insights:send-report` command.
- **`{{ insights:popular }}`** Antlers tag: most-viewed entries for "most read"
  lists, straight from the rollups.
- **"View in Insights" entry action** on every entry, deep-linking to the
  dashboard filtered to that entry (`insights.entry_action` to disable).
- **Own-traffic exclusion**: logged-in CP users excluded by default
  (`insights.exclude_cp_users`), IP exclusion list (`insights.exclude_ips`),
  and a per-browser opt-out (`localStorage.insights_ignore`).
- New `configure insights` permission gating the settings screen.

### Changed
- **Upgrade note**: run `php artisan migrate`. The daily rollup tables are
  rebuilt with a site dimension - the next `insights:rollup` refills them from
  raw data automatically (only aggregates older than raw retention would be lost,
  which no install has yet).

### Added
- Country flags (bundled circle-flags, served first-party) and localized country names.
- Header icons on the stat strip and cards, using Statamic's own icon set.
- Scrollable Top pages / Sources / Countries cards with a minimal hover-only
  scrollbar and a frosted fade that hints at more content below.
- Country bars now show each country's share of the combined total, with percentages.
- 60-second caching for dashboard/widget metrics (the realtime slice always stays live).
- CI: PHPUnit + Pint run on every push via GitHub Actions.
- Localization: translations for all 28 languages Statamic ships (ar, az, cs, da,
  de, de_CH, es, et, fa, fr, hu, id, it, ja, ms, nb, nl, pl, pt, pt_BR, ru, sl,
  sv, tr, uk, vi, zh_CN, zh_TW). The dashboard and widget follow each CP user's
  own language preference; app-level `lang/{locale}.json` entries override addon
  strings. A test locks every locale file to the canonical key list.

### Fixed
- Addon translations now actually load: Statamic only auto-registers namespaced
  PHP translation files, so the JSON files (bare English keys) were never picked
  up - `lang/` is now registered as a JSON translation path for both PHP `__()`
  and the CP's JavaScript dictionary.
- Chart date labels translate month names via the CP locale (Laravel no longer
  syncs Carbon's locale), and metric caches are keyed per locale so users with
  different CP languages don't see each other's labels.
- Numbers and country names format in the CP language instead of the browser
  default; the widget sparkline tooltip label is translated too.

### Changed
- Date ranges snap to full days, so the first chart bucket is never a partial day.
- "Active now" reports its unit honestly: consented visitors when available,
  otherwise anonymous pageviews labeled as such (it no longer presents hits as people).
- Live page dots use a 5-minute window ("being viewed right now") instead of 30 minutes.
- `retention_days` below 90 is clamped (with a warning) until the dashboard reads
  the rollup tables for days older than the raw retention window.
- Animations respect `prefers-reduced-motion`.
- Full page URL is always visible next to the entry title in Top pages (grey,
  truncated, full path on hover).

## v0.1.0 - 2026-07-09

Initial release: hybrid consent tracking (anonymous pageviews for everyone,
first-party visitor/session cookies after consent), CP dashboard (stats with
period deltas, pageviews chart, top pages with live indicators, referrers,
campaigns, devices/browsers donuts, countries), CP-home widget, nightly rollup +
retention, local IP-to-country resolution (no IPs stored), self-scheduling,
per-placement permission.
