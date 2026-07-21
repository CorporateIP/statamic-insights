<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Statamic\Facades\Site;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('insights_hits', function (Blueprint $table) {
            $table->string('site', 32)->nullable();
            $table->index(['site', 'visited_at']);
        });

        DB::table('insights_hits')->whereNull('site')->update(['site' => $this->defaultSite()]);

        // Custom events (window._insights.event), form submissions (form:handle)
        // and 404s. Pageviews stay in insights_hits.
        Schema::create('insights_events', function (Blueprint $table) {
            $table->id();
            $table->timestamp('visited_at');
            $table->string('site', 32)->nullable();
            $table->string('name', 64);
            $table->string('path');
            $table->text('properties')->nullable();
            $table->uuid('visitor_id')->nullable();
            $table->uuid('session_id')->nullable();

            $table->index(['name', 'visited_at']);
            $table->index(['visited_at']);
        });

        // Rollups are derived data: rebuilt with a site dimension rather than
        // migrated in place (the totals primary key changes shape). The next
        // insights:rollup rebuilds every day still inside raw retention.
        Schema::dropIfExists('insights_daily_totals');
        Schema::dropIfExists('insights_daily_pages');
        Schema::dropIfExists('insights_daily_dims');

        // One row per (day, site). Days at or before MAX(date) are safe to
        // prune from the raw tables - that bookmark contract is unchanged.
        Schema::create('insights_daily_totals', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('site', 32)->nullable();
            $table->unsignedInteger('views');
            $table->unsignedInteger('visitors');
            $table->unsignedInteger('sessions');

            $table->unique(['date', 'site']);
        });

        Schema::create('insights_daily_pages', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('site', 32)->nullable();
            $table->string('path');
            $table->string('entry_id', 36)->nullable();
            $table->unsignedInteger('views');
            $table->unsignedInteger('visitors');

            $table->index(['date', 'path']);
        });

        // One flexible table for every breakdown (referrer / device / browser /
        // os / country / campaign / event) instead of near-identical ones.
        Schema::create('insights_daily_dims', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('site', 32)->nullable();
            $table->string('dimension', 32);
            $table->string('value');
            $table->string('secondary')->nullable(); // e.g. utm_source next to the campaign
            $table->unsignedInteger('views');
            $table->unsignedInteger('visitors');

            $table->index(['date', 'dimension']);
        });

        // Goal conversions per (day, site, goal handle). Goals are evaluated
        // against their CURRENT definition each night - raw-retention days stay
        // retroactive, older days keep whatever definition was live then.
        Schema::create('insights_daily_goals', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('site', 32)->nullable();
            $table->string('goal', 64);
            $table->unsignedInteger('conversions');
            $table->unsignedInteger('visitors');

            $table->unique(['date', 'site', 'goal']);
        });
    }

    public function down(): void
    {
        Schema::table('insights_hits', function (Blueprint $table) {
            $table->dropIndex(['site', 'visited_at']);
            $table->dropColumn('site');
        });

        Schema::dropIfExists('insights_events');
        Schema::dropIfExists('insights_daily_goals');

        // Leave the rollup tables in their new shape - they rebuild either way.
    }

    private function defaultSite(): string
    {
        return rescue(fn () => Site::default()->handle(), 'default', false);
    }
};
