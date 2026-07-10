<?php

namespace CorporateIp\Insights\Tests;

use CorporateIp\Insights\Dashboard\Metrics;
use CorporateIp\Insights\Models\Hit;
use Illuminate\Foundation\Testing\RefreshDatabase;

class MetricsTest extends TestCase
{
    use RefreshDatabase;

    private function hit(array $overrides = []): void
    {
        Hit::create(array_merge([
            'visited_at' => now()->subHours(2),
            'path' => '/',
            'device_type' => 'desktop',
            'browser' => 'Chrome',
            'os' => 'Windows',
        ], $overrides));
    }

    public function test_payload_counts_views_visitors_and_sessions(): void
    {
        $visitor = '3e2c5a9d-64f1-4c4e-9a51-2f8f4a1f0b7e';
        $session = '9b7c1d2e-3f40-4a5b-8c6d-7e8f9a0b1c2d';

        $this->hit(['path' => '/dossiers', 'visitor_id' => $visitor, 'session_id' => $session]);
        $this->hit(['path' => '/dossiers', 'visitor_id' => $visitor, 'session_id' => $session]);
        $this->hit(['path' => '/steun-ons']); // anonymous

        $payload = Metrics::make('7d');

        $this->assertSame(3, $payload['tiles']['pageviews']['value']);
        $this->assertSame(1, $payload['tiles']['visitors']['value']);
        $this->assertSame(1, $payload['tiles']['sessions']['value']);

        $this->assertSame('/dossiers', $payload['pages'][0]['path']);
        $this->assertSame(2, $payload['pages'][0]['views']);
        $this->assertSame(1, $payload['pages'][0]['visitors']);
    }

    public function test_delta_compares_with_previous_period(): void
    {
        $this->hit(['visited_at' => now()->subDays(10)]); // previous window
        $this->hit(['visited_at' => now()->subDays(2)]);
        $this->hit(['visited_at' => now()->subDays(1)]);

        $payload = Metrics::make('7d');

        $this->assertSame(2, $payload['tiles']['pageviews']['value']);
        $this->assertSame(100, $payload['tiles']['pageviews']['delta']); // 1 -> 2
    }

    public function test_ranges_cover_full_days_and_gaps_are_zero_filled(): void
    {
        $this->hit(['visited_at' => now()->subDays(3)]);

        $payload = Metrics::make('7d');

        // 7 full-day buckets, today included; the first bucket starts at
        // midnight so no day is a silently partial one.
        $this->assertCount(7, $payload['timeseries']['labels']);
        $this->assertSame(1, array_sum($payload['timeseries']['views']));
        $this->assertContains(0, $payload['timeseries']['views']);
    }

    public function test_active_now_reports_visitors_or_falls_back_to_pageviews(): void
    {
        $this->hit(['visited_at' => now()->subMinutes(2)]);
        $this->hit(['visited_at' => now()->subMinutes(3)]);

        $now = Metrics::make('today')['tiles']['now'];
        $this->assertSame(2, $now['value']);
        $this->assertSame('views', $now['unit']); // anonymous hits are not people

        $this->hit(['visited_at' => now()->subMinutes(1), 'visitor_id' => '3e2c5a9d-64f1-4c4e-9a51-2f8f4a1f0b7e']);

        $now = Metrics::make('today')['tiles']['now'];
        $this->assertSame(1, $now['value']);
        $this->assertSame('visitors', $now['unit']);
    }

    public function test_breakdowns_group_devices_browsers_and_countries(): void
    {
        $this->hit(['device_type' => 'mobile', 'browser' => 'Safari', 'country' => 'NL']);
        $this->hit(['device_type' => 'mobile', 'browser' => 'Safari', 'country' => 'NL']);
        $this->hit(['device_type' => 'desktop', 'browser' => 'Firefox', 'country' => 'BE']);

        $payload = Metrics::make('30d');

        $this->assertSame(['label' => 'mobile', 'count' => 2], $payload['devices'][0]);
        $this->assertSame(['label' => 'Safari', 'count' => 2], $payload['browsers'][0]);
        $this->assertSame(['code' => 'NL', 'views' => 2], $payload['countries'][0]);
    }

    public function test_unknown_range_is_rejected(): void
    {
        $this->expectExceptionMessage('Unknown range.');

        Metrics::make('1y');
    }
}
