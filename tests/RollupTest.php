<?php

namespace CorporateIp\Insights\Tests;

use CorporateIp\Insights\Goals\Goal;
use CorporateIp\Insights\Goals\GoalRepository;
use CorporateIp\Insights\Models\Event;
use CorporateIp\Insights\Models\Hit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class RollupTest extends TestCase
{
    use RefreshDatabase;

    private function hit(array $overrides = []): void
    {
        Hit::create(array_merge([
            'visited_at' => now()->subDays(2),
            'path' => '/',
            'device_type' => 'desktop',
            'browser' => 'Chrome',
            'os' => 'Windows',
        ], $overrides));
    }

    public function test_complete_days_roll_up_into_daily_tables(): void
    {
        $visitor = '3e2c5a9d-64f1-4c4e-9a51-2f8f4a1f0b7e';

        $this->hit(['visited_at' => now()->subDays(2), 'path' => '/dossiers', 'visitor_id' => $visitor, 'country' => 'NL']);
        $this->hit(['visited_at' => now()->subDays(2), 'path' => '/dossiers', 'visitor_id' => $visitor, 'country' => 'NL']);
        $this->hit(['visited_at' => now()->subDays(1), 'utm_campaign' => 'juli', 'utm_source' => 'nb']);
        $this->hit(['visited_at' => now()]); // today: not rolled up yet

        $this->artisan('insights:rollup')->assertSuccessful();

        $twoDaysAgo = today()->subDays(2)->toDateString();

        $totals = DB::table('insights_daily_totals')->where('date', $twoDaysAgo)->first();
        $this->assertSame(2, (int) $totals->views);
        $this->assertSame(1, (int) $totals->visitors);

        $page = DB::table('insights_daily_pages')->where('date', $twoDaysAgo)->where('path', '/dossiers')->first();
        $this->assertSame(2, (int) $page->views);

        $country = DB::table('insights_daily_dims')->where('date', $twoDaysAgo)->where('dimension', 'country')->first();
        $this->assertSame('NL', $country->value);

        $campaign = DB::table('insights_daily_dims')->where('dimension', 'campaign')->first();
        $this->assertSame('juli', $campaign->value);
        $this->assertSame('nb', $campaign->secondary);

        $this->assertNull(DB::table('insights_daily_totals')->where('date', today()->toDateString())->first());
    }

    public function test_rollup_is_idempotent(): void
    {
        $this->hit(['visited_at' => now()->subDay()]);

        $this->artisan('insights:rollup')->assertSuccessful();
        $this->artisan('insights:rollup', ['--date' => today()->subDay()->toDateString()])->assertSuccessful();

        $this->assertSame(1, DB::table('insights_daily_totals')->where('date', today()->subDay()->toDateString())->count());
    }

    public function test_pruning_removes_only_rolled_up_days_past_retention(): void
    {
        $this->hit(['visited_at' => now()->subDays(95)]); // past the 90-day retention
        $this->hit(['visited_at' => now()->subDays(2)]);
        $this->hit(['visited_at' => now()->subHours(2)]); // today: kept

        $this->artisan('insights:rollup')->assertSuccessful();

        // Only the hit beyond retention is pruned; recent days stay raw.
        $this->assertSame(2, Hit::count());

        // The pruned day survives in the rollups.
        $this->assertSame(1, (int) DB::table('insights_daily_totals')->where('date', today()->subDays(95)->toDateString())->value('views'));
    }

    public function test_retention_below_the_longest_dashboard_range_is_clamped(): void
    {
        config(['insights.retention_days' => 1]);

        $this->hit(['visited_at' => now()->subDays(3)]);
        $this->hit(['visited_at' => now()->subDays(2)]);

        $this->artisan('insights:rollup')->assertSuccessful();

        // Nothing pruned: 1-day retention would punch holes in the 90-day view.
        $this->assertSame(2, Hit::count());
    }

    public function test_rollups_carry_the_site_and_split_per_site(): void
    {
        $this->hit(['site' => 'english', 'path' => '/about']);
        $this->hit(['site' => 'english', 'path' => '/about']);
        $this->hit(['site' => 'dutch', 'path' => '/over-ons']);

        $this->artisan('insights:rollup')->assertSuccessful();

        $date = today()->subDays(2)->toDateString();

        $this->assertSame(2, (int) DB::table('insights_daily_totals')->where('date', $date)->where('site', 'english')->value('views'));
        $this->assertSame(1, (int) DB::table('insights_daily_totals')->where('date', $date)->where('site', 'dutch')->value('views'));
        $this->assertSame('english', DB::table('insights_daily_pages')->where('path', '/about')->value('site'));
    }

    public function test_events_and_goals_roll_up_and_events_are_pruned(): void
    {
        $storage = sys_get_temp_dir().'/insights-tests-'.uniqid();
        config(['insights.storage_path' => $storage]);

        app(GoalRepository::class)->save(new Goal('bedankt', 'Bedankt', 'path', '/bedankt'));
        app(GoalRepository::class)->save(new Goal('signup', 'Signup', 'event', 'signup'));

        $this->hit(['visited_at' => now()->subDays(2), 'path' => '/bedankt']);
        Event::create(['visited_at' => now()->subDays(2), 'name' => 'signup', 'path' => '/', 'site' => 'default']);
        Event::create(['visited_at' => now()->subDays(95), 'name' => 'signup', 'path' => '/']); // beyond retention

        $this->artisan('insights:rollup')->assertSuccessful();

        $date = today()->subDays(2)->toDateString();

        $this->assertSame(1, (int) DB::table('insights_daily_goals')->where('date', $date)->where('goal', 'bedankt')->value('conversions'));
        $this->assertSame(1, (int) DB::table('insights_daily_goals')->where('date', $date)->where('goal', 'signup')->value('conversions'));
        $this->assertSame(1, (int) DB::table('insights_daily_dims')->where('date', $date)->where('dimension', 'event')->where('value', 'signup')->value('views'));

        // The 95-day-old event is pruned; the recent one stays raw.
        $this->assertSame(1, Event::count());

        File::deleteDirectory($storage);
    }
}
