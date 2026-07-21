<?php

namespace CorporateIp\Insights\Http\Middleware;

use Closure;
use CorporateIp\Insights\Models\Event;
use CorporateIp\Insights\Support\Beacon;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records page-not-found responses as the built-in `404` event so broken links
 * surface in the dashboard. Runs as GLOBAL terminable middleware: the work
 * happens in terminate(), after the response has been sent, and exceptions
 * rendered by the handler still pass through here with their final status.
 */
class RecordNotFound
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if (! config('insights.track_404', true)) {
            return;
        }

        if (! $request->isMethod('GET') || $response->getStatusCode() !== 404) {
            return;
        }

        // Only page requests: browsers ask for pages with text/html, and a
        // missing image/asset (extension in the last segment) is not a page.
        if (! Str::contains((string) $request->header('Accept'), 'text/html')) {
            return;
        }

        $path = Beacon::path($request->path());

        if (Str::contains(basename($path), '.') || Beacon::excludedPath($path)) {
            return;
        }

        rescue(function () use ($request, $path) {
            if (Beacon::shouldIgnore($request)) {
                return;
            }

            Event::create([
                'visited_at' => now(),
                'site' => Beacon::site($request, $path),
                'name' => '404',
                'path' => $path,
                'properties' => array_filter([
                    'referrer' => Beacon::referrerDomain($request->headers->get('referer'), $request->getHost()),
                ]) ?: null,
                'visitor_id' => Beacon::uuidOrNull($request->cookie(config('insights.cookie.name'))),
                'session_id' => Beacon::uuidOrNull($request->cookie(config('insights.cookie.session_name'))),
            ]);
        }, report: false);
    }
}
