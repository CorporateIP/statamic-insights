<?php

namespace CorporateIp\Insights\Support;

/**
 * Tiny regex-based user-agent classifier. Deliberately coarse: analytics needs
 * "which browser family / OS / device class", not exact versions - and a full
 * UA parser dependency is overkill for that.
 *
 * The full user agent string is never stored.
 */
class UserAgentParser
{
    /**
     * @return array{device_type: string, browser: ?string, os: ?string}
     */
    public static function parse(string $userAgent): array
    {
        return [
            'device_type' => self::deviceType($userAgent),
            'browser' => self::browser($userAgent),
            'os' => self::os($userAgent),
        ];
    }

    private static function deviceType(string $ua): string
    {
        if (preg_match('/iPad|Tablet|PlayBook|Silk|Android(?!.*Mobile)/i', $ua)) {
            return 'tablet';
        }

        if (preg_match('/Mobi|iPhone|iPod|Android.*Mobile|Windows Phone/i', $ua)) {
            return 'mobile';
        }

        return 'desktop';
    }

    private static function browser(string $ua): ?string
    {
        return match (true) {
            str_contains($ua, 'Edg/') || str_contains($ua, 'Edge/') => 'Edge',
            str_contains($ua, 'OPR/') || str_contains($ua, 'Opera') => 'Opera',
            str_contains($ua, 'SamsungBrowser') => 'Samsung Internet',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'CriOS') || str_contains($ua, 'Chrome/') => 'Chrome',
            str_contains($ua, 'Safari/') && str_contains($ua, 'Version/') => 'Safari',
            str_contains($ua, 'MSIE') || str_contains($ua, 'Trident/') => 'Internet Explorer',
            default => null,
        };
    }

    private static function os(string $ua): ?string
    {
        return match (true) {
            preg_match('/iPhone|iPad|iPod/', $ua) === 1 => 'iOS',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Windows NT') => 'Windows',
            str_contains($ua, 'Mac OS X') => 'macOS',
            str_contains($ua, 'CrOS') => 'ChromeOS',
            str_contains($ua, 'Linux') => 'Linux',
            default => null,
        };
    }
}
