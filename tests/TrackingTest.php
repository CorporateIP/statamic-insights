<?php

namespace CorporateIp\Insights\Tests;

use CorporateIp\Insights\Models\Hit;
use Illuminate\Foundation\Testing\RefreshDatabase;

class TrackingTest extends TestCase
{
    use RefreshDatabase;

    private const CHROME_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36';

    public function test_a_hit_is_stored_with_parsed_fields(): void
    {
        $this->postJson('/!/statamic-insights/hit', [
            'path' => '/nieuws?utm_source=nb&utm_campaign=juli',
            'referrer' => 'https://www.google.com/',
        ], ['User-Agent' => self::CHROME_UA])->assertNoContent();

        $hit = Hit::sole();

        $this->assertSame('/nieuws', $hit->path);
        $this->assertSame('nb', $hit->utm_source);
        $this->assertSame('juli', $hit->utm_campaign);
        $this->assertSame('google.com', $hit->referrer_domain);
        $this->assertSame('desktop', $hit->device_type);
        $this->assertSame('Chrome', $hit->browser);
    }

    public function test_crawlers_are_ignored(): void
    {
        $this->postJson('/!/statamic-insights/hit', ['path' => '/'], [
            'User-Agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
        ])->assertNoContent();

        $this->assertSame(0, Hit::count());
    }

    public function test_the_tracker_script_is_served(): void
    {
        $this->get('/!/statamic-insights/tracker.js')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/javascript; charset=utf-8')
            ->assertSee('window._insights', false);
    }
}
