<?php

namespace CorporateIp\Insights\Support;

use MaxMind\Db\Reader;

/**
 * Country resolution from a LOCAL IP database (DB-IP Country Lite / MaxMind
 * GeoLite2, refreshed via `insights:geo-update`). The IP is used in-memory for
 * this one lookup and never stored or sent anywhere.
 */
class Geo
{
    private static ?Reader $reader = null;

    private static bool $unavailable = false;

    public static function country(?string $ip): ?string
    {
        if (! $ip || ! config('insights.geo.enabled')) {
            return null;
        }

        // Private / reserved ranges (local dev, proxies misconfigured) have no country.
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return null;
        }

        try {
            return self::reader()?->get($ip)['country']['iso_code'] ?? null;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function reader(): ?Reader
    {
        if (self::$unavailable) {
            return null;
        }

        if (self::$reader) {
            return self::$reader;
        }

        $path = config('insights.geo.database_path');

        if (! $path || ! is_file($path)) {
            self::$unavailable = true;

            return null;
        }

        return self::$reader = new Reader($path);
    }

    /**
     * Reset the memoized reader (used after geo-update replaces the database, and in tests).
     */
    public static function flush(): void
    {
        self::$reader = null;
        self::$unavailable = false;
    }
}
