<?php

namespace CorporateIp\Insights\Tests;

use CorporateIp\Insights\ServiceProvider;
use Statamic\Testing\AddonTestCase;

abstract class TestCase extends AddonTestCase
{
    protected string $addonServiceProvider = ServiceProvider::class;

    protected function resolveApplicationConfiguration($app)
    {
        parent::resolveApplicationConfiguration($app);

        // Tests create users; without Pro, Statamic's CountUsers middleware
        // rejects any CP request once a second user exists.
        $app['config']->set('statamic.editions.pro', true);
    }

    protected function tearDown(): void
    {
        // Statamic users are flat-file: without cleanup they leak across
        // tests (and across whole test runs) via the fixtures directory.
        foreach (glob(__DIR__.'/__fixtures__/users/*.yaml') as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }
}
