<?php

namespace CorporateIp\Insights;

use CorporateIp\Insights\Console\Commands\GeoUpdate;
use CorporateIp\Insights\Console\Commands\Rollup;
use CorporateIp\Insights\Widgets\InsightsWidget;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Support\Facades\Schedule;
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
        'actions' => __DIR__.'/../routes/actions.php',
    ];

    protected $commands = [
        GeoUpdate::class,
        Rollup::class,
    ];

    protected $widgets = [
        InsightsWidget::class,
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
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->publishes([
            __DIR__.'/../config/insights.php' => config_path('insights.php'),
        ], 'insights-config');

        // The tracker sets these cookies client-side, so they arrive unencrypted —
        // without this exception Laravel's cookie decryption would discard them.
        EncryptCookies::except([
            config('insights.cookie.name', '_insights_id'),
            config('insights.cookie.session_name', '_insights_s'),
        ]);

        $this->registerPermissions();
        $this->registerNav();
        $this->registerSchedule();
    }

    /**
     * Self-scheduling: nightly rollup + retention, monthly geo refresh. Sites
     * only need the standard `schedule:run` cron. Opt out via insights.schedule.
     */
    private function registerSchedule(): void
    {
        if (! config('insights.schedule', true) || ! $this->app->runningInConsole()) {
            return;
        }

        // Direct facade calls — an app->booted() wrapper here would register twice
        // (Statamic boots addons from inside a booted callback, and Laravel both
        // queues AND immediately fires callbacks added at that point).
        Schedule::command('insights:rollup')->dailyAt('03:17');
        Schedule::command('insights:geo-update')->monthlyOn(3, '04:07');
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
