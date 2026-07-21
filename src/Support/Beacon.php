<?php

namespace CorporateIp\Insights\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Jaybizzle\CrawlerDetect\CrawlerDetect;
use Statamic\Facades\Site;
use Statamic\Facades\User;

/**
 * Shared ingest guards + normalization for everything that records rows:
 * the hit/event beacons, the form-submission listener and the 404 recorder.
 */
class Beacon
{
    /**
     * Traffic that must never be counted: crawlers, excluded IPs, and (by
     * default) logged-in users who can access the CP - editors browsing
     * their own site are not visitors.
     */
    public static function shouldIgnore(Request $request): bool
    {
        $userAgent = (string) $request->userAgent();

        if ($userAgent === '' || app(CrawlerDetect::class)->isCrawler($userAgent)) {
            return true;
        }

        foreach (config('insights.exclude_ips', []) as $pattern) {
            if (Str::is($pattern, (string) $request->ip())) {
                return true;
            }
        }

        if (config('insights.exclude_cp_users', true)) {
            $user = rescue(fn () => User::current(), null, false);

            if ($user && $user->can('access cp')) {
                return true;
            }
        }

        return false;
    }

    public static function excludedPath(string $path): bool
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

    /**
     * Normalize a client-supplied path/URL to just its path component.
     */
    public static function path(string $raw): string
    {
        $path = parse_url($raw)['path'] ?? '/';

        return Str::limit('/'.ltrim($path, '/'), 255, '');
    }

    /**
     * The Statamic site the visited page belongs to, resolved from the request
     * host + page path (multisite can split by domain or path prefix).
     */
    public static function site(Request $request, string $path): ?string
    {
        return rescue(
            fn () => (Site::findByUrl($request->getSchemeAndHttpHost().$path) ?? Site::default())->handle(),
            null,
            false,
        );
    }

    public static function referrerDomain(?string $referrer, string $ownHost): ?string
    {
        $host = $referrer ? parse_url($referrer, PHP_URL_HOST) : null;

        // Internal navigation is not a referral.
        if (! $host || strcasecmp($host, $ownHost) === 0) {
            return null;
        }

        return Str::limit(Str::lower(Str::after($host, 'www.')), 255, '');
    }

    public static function clean(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? Str::limit(strip_tags($value), 255, '') : null;
    }

    public static function uuidOrNull(mixed $value): ?string
    {
        return is_string($value) && Str::isUuid($value) ? $value : null;
    }
}
