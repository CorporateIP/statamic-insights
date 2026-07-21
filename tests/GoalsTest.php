<?php

namespace CorporateIp\Insights\Tests;

use CorporateIp\Insights\Dashboard\Metrics;
use CorporateIp\Insights\Goals\Goal;
use CorporateIp\Insights\Goals\GoalRepository;
use CorporateIp\Insights\Models\Event;
use CorporateIp\Insights\Models\Hit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

class GoalsTest extends TestCase
{
    use RefreshDatabase;

    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storage = sys_get_temp_dir().'/insights-tests-'.uniqid();
        config(['insights.storage_path' => $this->storage]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storage);

        parent::tearDown();
    }

    private function goals(): GoalRepository
    {
        return $this->app->make(GoalRepository::class);
    }

    public function test_goals_roundtrip_through_yaml(): void
    {
        $this->goals()->save(new Goal('bedankt', 'Bedankpagina', 'path', '/bedankt*'));
        $this->goals()->save(new Goal('signup', 'Signups', 'event', 'newsletter-signup'));

        $this->assertFileExists($this->storage.'/goals.yaml');

        // A fresh repository instance re-reads from disk.
        $reloaded = (new GoalRepository)->all();

        $this->assertCount(2, $reloaded);
        $this->assertSame('path', $reloaded->firstWhere(fn ($g) => $g->handle === 'bedankt')->type);

        $this->goals()->delete('signup');
        $this->assertCount(1, (new GoalRepository)->all());
    }

    public function test_handles_are_slugged_and_deduplicated(): void
    {
        $this->assertSame('lid-worden', $this->goals()->makeHandle('Lid worden'));

        $this->goals()->save(new Goal('lid-worden', 'Lid worden', 'path', '/lid-worden/bedankt'));

        $this->assertSame('lid-worden-2', $this->goals()->makeHandle('Lid worden'));
    }

    public function test_metrics_evaluate_path_event_and_form_goals(): void
    {
        $this->goals()->save(new Goal('bedankt', 'Bedankt', 'path', '/bedankt*'));
        $this->goals()->save(new Goal('signup', 'Signup', 'event', 'signup'));
        $this->goals()->save(new Goal('contact', 'Contact', 'form', 'contact'));

        $visitor = '3e2c5a9d-64f1-4c4e-9a51-2f8f4a1f0b7e';

        Hit::create(['visited_at' => now()->subHour(), 'path' => '/bedankt', 'visitor_id' => $visitor]);
        Hit::create(['visited_at' => now()->subHour(), 'path' => '/bedankt/lid']);
        Hit::create(['visited_at' => now()->subHour(), 'path' => '/', 'visitor_id' => $visitor]);
        Event::create(['visited_at' => now()->subHour(), 'name' => 'signup', 'path' => '/', 'visitor_id' => $visitor]);
        Event::create(['visited_at' => now()->subHour(), 'name' => 'form:contact', 'path' => '/contact']);

        $goals = collect(Metrics::make('7d')['goals'])->keyBy('handle');

        $this->assertSame(2, $goals['bedankt']['conversions']);
        $this->assertSame(1, $goals['bedankt']['visitors']);
        $this->assertSame(100.0, $goals['bedankt']['rate']); // 1 of 1 known visitors

        $this->assertSame(1, $goals['signup']['conversions']);
        $this->assertSame(1, $goals['contact']['conversions']);
        $this->assertSame(0, $goals['contact']['visitors']); // anonymous conversion
    }

    public function test_path_goal_wildcards_do_not_match_literal_specials(): void
    {
        $this->goals()->save(new Goal('exact', 'Exact', 'path', '/a_b'));

        Hit::create(['visited_at' => now()->subHour(), 'path' => '/a_b']);
        Hit::create(['visited_at' => now()->subHour(), 'path' => '/axb']); // _ must not act as a wildcard

        $goals = collect(Metrics::make('7d')['goals'])->keyBy('handle');

        $this->assertSame(1, $goals['exact']['conversions']);
    }
}
