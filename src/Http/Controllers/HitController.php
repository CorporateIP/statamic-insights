<?php

namespace CorporateIp\Insights\Http\Controllers;

use CorporateIp\Insights\Models\Hit;
use CorporateIp\Insights\Support\Geo;
use CorporateIp\Insights\Support\UserAgentParser;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Jaybizzle\CrawlerDetect\CrawlerDetect;
use Statamic\Facades\Entry;

class HitController extends Controller
{
    public function store(Request $request)
    {
        $userAgent = (string) $request->userAgent();

        // No UA or a known crawler → acknowledge and drop. Most bots don't run
        // the tracker at all; this catches headless ones that do.
        if ($userAgent === '' || app(CrawlerDetect::class)->isCrawler($userAgent)) {
            return response()->noContent();
        }

        $data = $request->validate([
            'path' => ['required', 'string', 'max:2048'],
            'referrer' => ['nullable', 'string', 'max:2048'],
        ]);

        $url = parse_url($data['path']);
        $path = '/'.ltrim($url['path'] ?? '/', '/');

        if ($this->isExcluded($path)) {
            return response()->noContent();
        }

        parse_str($url['query'] ?? '', $query);

        $agent = UserAgentParser::parse($userAgent);

        Hit::create([
            'visited_at' => now(),
            'path' => Str::limit($path, 255, ''),
            'entry_id' => rescue(fn () => Entry::findByUri($path === '/' ? '/' : rtrim($path, '/'))?->id(), null, false),
            'referrer_domain' => $this->referrerDomain($data['referrer'] ?? null, $request->getHost()),
            'utm_source' => $this->clean($query['utm_source'] ?? null),
            'utm_medium' => $this->clean($query['utm_medium'] ?? null),
            'utm_campaign' => $this->clean($query['utm_campaign'] ?? null),
            'country' => Geo::country($request->ip()),
            'device_type' => $agent['device_type'],
            'browser' => $agent['browser'],
            'os' => $agent['os'],
            'visitor_id' => $this->uuidOrNull($request->cookie(config('insights.cookie.name'))),
            'session_id' => $this->uuidOrNull($request->cookie(config('insights.cookie.session_name'))),
        ]);

        return response()->noContent();
    }

    private function isExcluded(string $path): bool
    {
        $cpPrefix = '/'.trim(config('statamic.cp.route', 'cp'), '/');

        if ($path === $cpPrefix || str_starts_with($path, $cpPrefix.'/')) {
            return true;
        }

        foreach (config('insights.exclude_paths', []) as $pattern) {
            if (Str::is($pattern, $path)) {
                return true;
            }
        }

        return false;
    }

    private function referrerDomain(?string $referrer, string $ownHost): ?string
    {
        $host = $referrer ? parse_url($referrer, PHP_URL_HOST) : null;

        // Internal navigation is not a referral.
        if (! $host || strcasecmp($host, $ownHost) === 0) {
            return null;
        }

        return Str::limit(Str::lower(Str::after($host, 'www.')), 255, '');
    }

    private function clean(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? Str::limit(strip_tags($value), 255, '') : null;
    }

    private function uuidOrNull(mixed $value): ?string
    {
        return is_string($value) && Str::isUuid($value) ? $value : null;
    }
}
