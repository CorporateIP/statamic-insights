# Statamic Insights

First-party analytics for Statamic 6 with a full Control Panel dashboard. By Corporate IP.

- Hybrid tracking: complete pageview counts for everyone; unique visitors/sessions via a
  first-party cookie for visitors who accepted cookies.
- JS beacon (static-caching proof), server-side bot filtering, no third-party requests.
- Country stats from a local IP database — visitor IPs are never stored or shared.
- Dashboard: pageviews, uniques, sessions, realtime, top pages, referrers, countries,
  devices, UTM campaigns, per-entry stats.

## Local development

```bash
composer install               # brings statamic/cms (needed for the @statamic/cms npm package)
npm install
npm run build                  # compiled CP assets land in dist/ (committed)
```

Consume from a site via a composer path repository:

```json
"repositories": [{ "type": "path", "url": "../statamic-insights" }]
```

```bash
composer require corporateip/statamic-insights:@dev
```
