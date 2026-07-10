<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per fully-rolled-up day. Also acts as the rollup bookmark:
        // days at or before MAX(date) are safe to prune from the raw hits table.
        Schema::create('insights_daily_totals', function (Blueprint $table) {
            $table->date('date')->primary();
            $table->unsignedInteger('views');
            $table->unsignedInteger('visitors');
            $table->unsignedInteger('sessions');
        });

        Schema::create('insights_daily_pages', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('path');
            $table->string('entry_id', 36)->nullable();
            $table->unsignedInteger('views');
            $table->unsignedInteger('visitors');

            $table->index(['date', 'path']);
        });

        // One flexible table for every breakdown (referrer / device / browser /
        // os / country / campaign) instead of six near-identical ones.
        Schema::create('insights_daily_dims', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('dimension', 32);
            $table->string('value');
            $table->string('secondary')->nullable(); // e.g. utm_source next to the campaign
            $table->unsignedInteger('views');
            $table->unsignedInteger('visitors');

            $table->index(['date', 'dimension']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('insights_daily_totals');
        Schema::dropIfExists('insights_daily_pages');
        Schema::dropIfExists('insights_daily_dims');
    }
};
