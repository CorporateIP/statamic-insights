<?php

namespace CorporateIp\Insights\Tests;

use CorporateIp\Insights\Dashboard\Metrics;
use CorporateIp\Insights\Models\Hit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Statamic\Facades\User;

class DashboardV2Test extends TestCase
{
    use RefreshDatabase;

    private function hit(array $overrides = []): void
    {
        Hit::create(array_merge([
            'visited_at' => now()->subHours(2),
            'path' => '/',
            'site' => 'default',
            'device_type' => 'desktop',
            'browser' => 'Chrome',
            'os' => 'Windows',
        ], $overrides));
    }

    public function test_filters_narrow_every_dataset(): void
    {
        $this->hit(['country' => 'NL', 'path' => '/dossiers']);
        $this->hit(['country' => 'DE', 'path' => '/dossiers']);
        $this->hit(['country' => 'DE', 'path' => '/']);

        $payload = (new Metrics('7d', filters: ['country' => 'DE']))->payload();

        $this->assertSame(2, $payload['tiles']['pageviews']['value']);
        $this->assertCount(2, $payload['pages']);
        $this->assertSame([['code' => 'DE', 'views' => 2, 'visitors' => 0]], $payload['countries']);
    }

    public function test_custom_ranges_use_their_own_bounds(): void
    {
        $this->hit(['visited_at' => now()->subDays(10)]);
        $this->hit(['visited_at' => now()->subDays(5)]);
        $this->hit(['visited_at' => now()->subDays(1)]);

        $payload = (new Metrics(
            'custom',
            from: now()->subDays(6)->toDateString(),
            to: now()->subDays(3)->toDateString(),
        ))->payload();

        $this->assertSame(1, $payload['tiles']['pageviews']['value']);
        $this->assertSame('custom', $payload['range']['key']);
    }

    public function test_long_unfiltered_ranges_read_the_rollups_plus_today(): void
    {
        $this->hit(['visited_at' => now()->subDays(100), 'path' => '/oud']);
        $this->artisan('insights:rollup')->assertSuccessful();

        // The 100-day-old raw row is pruned - only the rollup remembers it.
        $this->assertSame(0, Hit::query()->where('path', '/oud')->count());

        $this->hit(['visited_at' => now()->subHour(), 'path' => '/vandaag']);

        $payload = (new Metrics('12m'))->payload();

        $this->assertSame('rollups', $payload['range']['source']);
        $this->assertSame(2, $payload['tiles']['pageviews']['value']);
        $this->assertTrue($payload['tiles']['visitors']['approx']);
        $this->assertFalse($payload['tiles']['bounce_rate']['available']);

        $paths = array_column($payload['pages'], 'path');
        $this->assertContains('/oud', $paths);
        $this->assertContains('/vandaag', $paths);
    }

    public function test_filtered_long_ranges_clamp_to_the_raw_window(): void
    {
        $payload = (new Metrics('12m', filters: ['country' => 'NL']))->payload();

        $this->assertSame('raw', $payload['range']['source']);
        $this->assertTrue($payload['range']['clamped']);
        $this->assertGreaterThanOrEqual(
            today()->subDays(91),
            Carbon::parse($payload['range']['from']),
        );
    }

    public function test_bounce_rate_and_duration_come_from_consented_sessions(): void
    {
        $engaged = '9b7c1d2e-3f40-4a5b-8c6d-7e8f9a0b1c2d';

        // Session A: two pageviews 60 seconds apart - not a bounce.
        $this->hit(['session_id' => $engaged, 'visited_at' => now()->subMinutes(10)]);
        $this->hit(['session_id' => $engaged, 'visited_at' => now()->subMinutes(9)]);

        // Session B: single pageview - a bounce with duration 0.
        $this->hit(['session_id' => '3e2c5a9d-64f1-4c4e-9a51-2f8f4a1f0b7e']);

        // Anonymous hits carry no session and stay out of the denominator.
        $this->hit([]);

        $tiles = (new Metrics('7d'))->payload()['tiles'];

        $this->assertSame(50.0, (float) $tiles['bounce_rate']['value']);
        $this->assertSame(30, $tiles['duration']['value']); // (60 + 0) / 2
        $this->assertTrue($tiles['duration']['available']);
    }

    public function test_datasets_export_as_csv(): void
    {
        $this->actingAs(tap(User::make()->makeSuper()->email('editor@example.com'))->save());

        $this->hit(['path' => '/dossiers']);

        $response = $this->get(cp_route('insights.export', ['dataset' => 'pages', 'range' => '7d']))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=utf-8');

        $csv = $response->streamedContent();

        $this->assertStringContainsString('path,title,visitors,views', $csv);
        $this->assertStringContainsString('/dossiers', $csv);

        $this->get(cp_route('insights.export', ['dataset' => 'nope']))->assertStatus(422);
    }
}
