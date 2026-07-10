# Changelog

All notable changes to Statamic Insights are documented here.

## Unreleased

### Added
- Country flags (bundled circle-flags, served first-party) and localized country names.
- Header icons on the stat strip and cards, using Statamic's own icon set.
- Scrollable Top pages / Sources / Countries cards with a minimal hover-only
  scrollbar and a frosted fade that hints at more content below.
- Country bars now show each country's share of the combined total, with percentages.
- 60-second caching for dashboard/widget metrics (the realtime slice always stays live).
- CI: PHPUnit + Pint run on every push via GitHub Actions.
- Dutch translations for the full dashboard and widget.

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
