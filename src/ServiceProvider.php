<?php

namespace CorporateIp\Insights;

use CorporateIp\Insights\Actions\ViewInInsights;
use CorporateIp\Insights\Console\Commands\GeoUpdate;
use CorporateIp\Insights\Console\Commands\Rollup;
use CorporateIp\Insights\Console\Commands\SendReport;
use CorporateIp\Insights\Goals\GoalRepository;
use CorporateIp\Insights\Http\Middleware\RecordNotFound;
use CorporateIp\Insights\Listeners\RecordFormSubmission;
use CorporateIp\Insights\Support\Settings;
use CorporateIp\Insights\Widgets\InsightsWidget;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Support\Facades\Schedule;
use Statamic\Events\SubmissionCreated;
use Statamic\Facades\CP\Nav;
use Statamic\Facades\Permission;
use Statamic\Providers\AddonServiceProvider;

class ServiceProvider extends AddonServiceProvider
{
    protected $listen = [
        SubmissionCreated::class => [RecordFormSubmission::class],
    ];

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
        SendReport::class,
    ];

    protected $widgets = [
        InsightsWidget::class,
    ];

    protected $actions = [
        ViewInInsights::class,
    ];

    // Bundled circle-flags SVGs (MIT, HatScripts/circle-flags) served from
    // /vendor/statamic-insights/flags/{code}.svg - no third-party requests.
    protected $publishables = [
        __DIR__.'/../resources/flags' => 'flags',
    ];

    public function register()
    {
        parent::register();

        // Manual merge: the auto-boot convention would register the config under
        // the addon slug ('statamic-insights'); we want the cleaner 'insights' key.
        $this->mergeConfigFrom(__DIR__.'/../config/insights.php', 'insights');

        // Singletons: both cache their YAML file in memory per request.
        $this->app->singleton(GoalRepository::class);
        $this->app->singleton(Settings::class);
    }

    public function bootAddon()
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        // Statamic only registers addon lang dirs for NAMESPACED PHP files; our
        // bare-English-string keys live in lang/{locale}.json, which must be
        // registered as a JSON path to reach both PHP __() and the CP's JS
        // translation dictionary (Translator::toJson merges JSON paths into '*').
        $this->loadJsonTranslationsFrom(__DIR__.'/../lang');

        $this->publishes([
            __DIR__.'/../config/insights.php' => config_path('insights.php'),
        ], 'insights-config');

        // The tracker sets these cookies client-side, so they arrive unencrypted -
        // without this exception Laravel's cookie decryption would discard them.
        EncryptCookies::except([
            config('insights.cookie.name', '_insights_id'),
            config('insights.cookie.session_name', '_insights_s'),
        ]);

        // Global (not route) middleware: 404s are rendered by the exception
        // handler, so only global middleware reliably sees the final response.
        // All work happens in terminate(), after the response is sent.
        $this->app[Kernel::class]->pushMiddleware(RecordNotFound::class);

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

        // Direct facade calls - an app->booted() wrapper here would register twice
        // (Statamic boots addons from inside a booted callback, and Laravel both
        // queues AND immediately fires callbacks added at that point).
        Schedule::command('insights:rollup')->dailyAt('03:17');
        Schedule::command('insights:geo-update')->monthlyOn(3, '04:07');

        // The command checks the CP-managed toggles itself; a disabled
        // frequency is a no-op run.
        Schedule::command('insights:send-report weekly')->weeklyOn(1, '07:30');
        Schedule::command('insights:send-report monthly')->monthlyOn(1, '07:45');
    }

    private function registerPermissions(): void
    {
        Permission::extend(function () {
            Permission::register('view insights')
                ->label(__('View Insights'))
                ->children([
                    Permission::make('configure insights')
                        ->label(__('Configure Insights')),
                ]);
        });
    }

    private function registerNav(): void
    {
        Nav::extend(function ($nav) {
            // Section by its UNTRANSLATED key: Statamic matches sections by name
            // and translates at render time. Passing __('Tools') on a non-English
            // CP would register a duplicate section next to the core one.
            $nav->create(__('Insights'))
                ->section('Tools')
                ->route('insights.dashboard')
                ->icon('chart-monitoring-indicator')
                ->can('view insights');
        });
    }
}
