<?php

namespace CorporateIp\Insights\Tests;

use CorporateIp\Insights\Listeners\RecordFormSubmission;
use CorporateIp\Insights\Models\Event;
use CorporateIp\Insights\Models\Hit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Statamic\Events\SubmissionCreated;
use Statamic\Facades\Form;
use Statamic\Facades\Site;
use Statamic\Facades\User;

class EventsTest extends TestCase
{
    use RefreshDatabase;

    private const CHROME_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36';

    public function test_a_custom_event_is_stored(): void
    {
        $this->postJson('/!/statamic-insights/event', [
            'name' => 'newsletter-signup',
            'path' => '/nieuws?utm_source=x',
            'props' => ['plan' => 'pro', 'nested' => ['dropped' => true]],
        ], ['User-Agent' => self::CHROME_UA])->assertNoContent();

        $event = Event::sole();

        $this->assertSame('newsletter-signup', $event->name);
        $this->assertSame('/nieuws', $event->path);
        $this->assertSame(Site::default()->handle(), $event->site);
        $this->assertSame(['plan' => 'pro'], $event->properties);
    }

    public function test_reserved_event_names_cannot_be_forged_from_the_browser(): void
    {
        foreach (['404', 'form:contact'] as $name) {
            $this->postJson('/!/statamic-insights/event', [
                'name' => $name,
                'path' => '/',
            ], ['User-Agent' => self::CHROME_UA])->assertNoContent();
        }

        $this->assertSame(0, Event::count());
    }

    public function test_hits_record_the_site_handle(): void
    {
        $this->postJson('/!/statamic-insights/hit', ['path' => '/'], ['User-Agent' => self::CHROME_UA])
            ->assertNoContent();

        $this->assertSame(Site::default()->handle(), Hit::sole()->site);
    }

    public function test_cp_users_are_excluded_by_default(): void
    {
        $this->actingAs(tap(User::make()->makeSuper()->email('editor@example.com'))->save());

        $this->postJson('/!/statamic-insights/hit', ['path' => '/'], ['User-Agent' => self::CHROME_UA])
            ->assertNoContent();

        $this->assertSame(0, Hit::count());

        config(['insights.exclude_cp_users' => false]);

        $this->postJson('/!/statamic-insights/hit', ['path' => '/'], ['User-Agent' => self::CHROME_UA])
            ->assertNoContent();

        $this->assertSame(1, Hit::count());
    }

    public function test_excluded_ips_are_dropped(): void
    {
        config(['insights.exclude_ips' => ['127.0.0.*']]);

        $this->postJson('/!/statamic-insights/hit', ['path' => '/'], ['User-Agent' => self::CHROME_UA])
            ->assertNoContent();

        $this->assertSame(0, Hit::count());
    }

    public function test_a_form_submission_is_recorded_as_a_form_event(): void
    {
        $form = tap(Form::make('contact'))->save();

        $this->app->instance('request', Request::create('/!/forms/contact', 'POST', server: [
            'HTTP_USER_AGENT' => self::CHROME_UA,
            'HTTP_REFERER' => 'http://localhost/contact?utm_source=x',
        ]));

        (new RecordFormSubmission)->handle(new SubmissionCreated($form->makeSubmission()));

        $event = Event::sole();

        $this->assertSame('form:contact', $event->name);
        $this->assertSame('/contact', $event->path);
    }

    public function test_a_page_not_found_is_recorded_as_a_404_event(): void
    {
        $this->get('/does-not-exist', [
            'User-Agent' => self::CHROME_UA,
            'Accept' => 'text/html,application/xhtml+xml',
        ])->assertNotFound();

        $event = Event::sole();

        $this->assertSame('404', $event->name);
        $this->assertSame('/does-not-exist', $event->path);
    }

    public function test_missing_assets_and_disabled_tracking_record_no_404(): void
    {
        $this->get('/images/missing.png', [
            'User-Agent' => self::CHROME_UA,
            'Accept' => 'image/avif,image/webp,*/*',
        ])->assertNotFound();

        config(['insights.track_404' => false]);

        $this->get('/also-missing', [
            'User-Agent' => self::CHROME_UA,
            'Accept' => 'text/html',
        ])->assertNotFound();

        $this->assertSame(0, Event::count());
    }
}
