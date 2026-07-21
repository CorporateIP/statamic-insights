<?php

namespace CorporateIp\Insights\Tests;

use CorporateIp\Insights\Actions\ViewInInsights;
use CorporateIp\Insights\Dashboard\Metrics;
use CorporateIp\Insights\Goals\Goal;
use CorporateIp\Insights\Goals\GoalRepository;
use CorporateIp\Insights\Mail\InsightsReport;
use CorporateIp\Insights\Models\Hit;
use CorporateIp\Insights\Support\Settings;
use CorporateIp\Insights\Tags\Insights;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\User;

class StatamicNativeTest extends TestCase
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

    private function makeEntry(string $slug, string $title)
    {
        return tap(
            Entry::make()->collection('pages')->slug($slug)->data(['title' => $title])->published(true),
        )->save();
    }

    public function test_the_popular_tag_returns_most_viewed_entries(): void
    {
        Collection::make('pages')->routes('/{slug}')->save();

        $popular = $this->makeEntry('populair', 'Populair artikel');
        $quiet = $this->makeEntry('rustig', 'Rustig artikel');

        foreach (range(1, 3) as $i) {
            Hit::create(['visited_at' => now()->subHours($i), 'path' => '/populair', 'entry_id' => $popular->id()]);
        }
        Hit::create(['visited_at' => now()->subHours(1), 'path' => '/rustig', 'entry_id' => $quiet->id()]);

        $result = (new Insights)
            ->setContext([])
            ->setParameters(['limit' => 5, 'days' => 7])
            ->popular();

        $this->assertCount(2, $result);
        $this->assertSame('Populair artikel', (string) $result[0]['title']);
        $this->assertSame(3, $result[0]['views']);
        $this->assertSame(1, $result[1]['views']);
    }

    public function test_the_entry_action_deep_links_to_the_filtered_dashboard(): void
    {
        Collection::make('pages')->routes('/{slug}')->save();

        $entry = $this->makeEntry('over-ons', 'Over ons');

        $action = new ViewInInsights;

        $this->actingAs(tap(User::make()->makeSuper()->email('editor@example.com'))->save());

        $this->assertTrue($action->visibleTo($entry));

        $url = $action->redirect(collect([$entry]), []);

        $this->assertStringContainsString('filter_path='.urlencode('/over-ons'), $url);

        config(['insights.entry_action' => false]);
        $this->assertFalse($action->visibleTo($entry));
    }

    public function test_settings_endpoint_saves_goals_and_report_config(): void
    {
        $this->actingAs(tap(User::make()->makeSuper()->email('editor@example.com'))->save());

        $this->postJson(cp_route('insights.settings.save'), [
            'goals' => [
                ['handle' => '', 'name' => 'Lid worden', 'type' => 'path', 'value' => '/lid-worden/bedankt'],
                ['handle' => '', 'name' => 'Contact', 'type' => 'form', 'value' => 'contact'],
            ],
            'email' => [
                'recipients' => ['jimmy@example.com', 'info@example.com'],
                'weekly' => true,
                'monthly' => false,
            ],
        ])->assertOk();

        $goals = app(GoalRepository::class)->all();
        $this->assertCount(2, $goals);
        $this->assertSame('lid-worden', $goals[0]->handle);
        $this->assertSame('form', $goals[1]->type);

        $settings = app(Settings::class);
        $this->assertSame(['jimmy@example.com', 'info@example.com'], $settings->get('report_recipients'));
        $this->assertTrue($settings->get('report_weekly'));

        // Invalid recipients are rejected wholesale.
        $this->postJson(cp_route('insights.settings.save'), [
            'goals' => [],
            'email' => ['recipients' => ['not-an-email'], 'weekly' => false, 'monthly' => false],
        ])->assertStatus(422);
    }

    public function test_the_report_command_mails_configured_recipients(): void
    {
        Mail::fake();

        app(GoalRepository::class)->save(new Goal('bedankt', 'Bedankt', 'path', '/bedankt'));

        $settings = app(Settings::class);
        $settings->put('report_recipients', ['jimmy@example.com']);
        $settings->put('report_weekly', true);

        Hit::create(['visited_at' => now()->subDays(2), 'path' => '/bedankt']);

        $this->artisan('insights:send-report', ['period' => 'weekly'])->assertSuccessful();

        Mail::assertSent(InsightsReport::class, function (InsightsReport $mail) {
            return $mail->hasTo('jimmy@example.com')
                && $mail->period === 'weekly'
                && $mail->payload['tiles']['pageviews']['value'] === 1;
        });

        // A disabled frequency sends nothing.
        $settings->put('report_weekly', false);
        Mail::fake();

        $this->artisan('insights:send-report', ['period' => 'weekly'])->assertSuccessful();

        Mail::assertNothingSent();
    }

    public function test_the_report_email_renders(): void
    {
        Hit::create(['visited_at' => now()->subDays(2), 'path' => '/dossiers']);

        $metrics = new Metrics('7d');

        $html = (new InsightsReport('weekly', '1 Jul - 7 Jul', $metrics->payload()))->render();

        $this->assertStringContainsString('Pageviews', $html);
        $this->assertStringContainsString('/dossiers', $html);
    }
}
