<?php

namespace CorporateIp\Insights;

use Statamic\Facades\CP\Nav;
use Statamic\Facades\Permission;
use Statamic\Providers\AddonServiceProvider;

class ServiceProvider extends AddonServiceProvider
{
    protected $vite = [
        'publicDirectory' => 'dist',
        'hotFile' => 'vendor/insights/hot',
        'input' => [
            'resources/js/cp.js',
        ],
    ];

    protected $routes = [
        'cp' => __DIR__.'/../routes/cp.php',
    ];

    public function register()
    {
        parent::register();

        // Manual merge: the auto-boot convention would register the config under
        // the addon slug ('statamic-insights'); we want the cleaner 'insights' key.
        $this->mergeConfigFrom(__DIR__.'/../config/insights.php', 'insights');
    }

    public function bootAddon()
    {
        $this->publishes([
            __DIR__.'/../config/insights.php' => config_path('insights.php'),
        ], 'insights-config');

        $this->registerPermissions();
        $this->registerNav();
    }

    private function registerPermissions(): void
    {
        Permission::extend(function () {
            Permission::register('view insights')
                ->label(__('View Insights'));
        });
    }

    private function registerNav(): void
    {
        Nav::extend(function ($nav) {
            $nav->create(__('Insights'))
                ->section(__('Tools'))
                ->route('insights.dashboard')
                ->icon('chart-monitoring-indicator')
                ->can('view insights');
        });
    }
}
