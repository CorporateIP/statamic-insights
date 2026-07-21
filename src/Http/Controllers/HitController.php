<?php

namespace CorporateIp\Insights\Http\Controllers;

use CorporateIp\Insights\Models\Hit;
use CorporateIp\Insights\Support\Beacon;
use CorporateIp\Insights\Support\Geo;
use CorporateIp\Insights\Support\UserAgentParser;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Statamic\Facades\Entry;

class HitController extends Controller
{
    public function store(Request $request)
    {
        // Most bots don't run the tracker at all; Beacon catches headless ones
        // that do, plus excluded IPs and logged-in CP users.
        if (Beacon::shouldIgnore($request)) {
            return response()->noContent();
        }

        $data = $request->validate([
            'path' => ['required', 'string', 'max:2048'],
            'referrer' => ['nullable', 'string', 'max:2048'],
        ]);

        $path = Beacon::path($data['path']);

        if (Beacon::excludedPath($path)) {
            return response()->noContent();
        }

        parse_str(parse_url($data['path'])['query'] ?? '', $query);

        $agent = UserAgentParser::parse((string) $request->userAgent());

        Hit::create([
            'visited_at' => now(),
            'site' => Beacon::site($request, $path),
            'path' => $path,
            'entry_id' => rescue(fn () => Entry::findByUri($path === '/' ? '/' : rtrim($path, '/'))?->id(), null, false),
            'referrer_domain' => Beacon::referrerDomain($data['referrer'] ?? null, $request->getHost()),
            'utm_source' => Beacon::clean($query['utm_source'] ?? null),
            'utm_medium' => Beacon::clean($query['utm_medium'] ?? null),
            'utm_campaign' => Beacon::clean($query['utm_campaign'] ?? null),
            'country' => Geo::country($request->ip()),
            'device_type' => $agent['device_type'],
            'browser' => $agent['browser'],
            'os' => $agent['os'],
            'visitor_id' => Beacon::uuidOrNull($request->cookie(config('insights.cookie.name'))),
            'session_id' => Beacon::uuidOrNull($request->cookie(config('insights.cookie.session_name'))),
        ]);

        return response()->noContent();
    }
}
