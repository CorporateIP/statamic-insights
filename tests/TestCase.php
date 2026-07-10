<?php

namespace CorporateIp\Insights\Tests;

use CorporateIp\Insights\ServiceProvider;
use Statamic\Testing\AddonTestCase;

abstract class TestCase extends AddonTestCase
{
    protected string $addonServiceProvider = ServiceProvider::class;
}
