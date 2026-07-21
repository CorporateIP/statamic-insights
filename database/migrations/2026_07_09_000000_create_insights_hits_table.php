<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('insights_hits', function (Blueprint $table) {
            $table->id();
            // DATETIME, not TIMESTAMP: a bare MySQL/MariaDB TIMESTAMP column
            // (the first one in a table, with explicit_defaults_for_timestamp
            // OFF) silently gets ON UPDATE CURRENT_TIMESTAMP, which rewrites the
            // value on any UPDATE to the row. DATETIME has no such behaviour.
            $table->dateTime('visited_at');
            $table->string('path');
            $table->string('entry_id', 36)->nullable();
            $table->string('referrer_domain')->nullable();
            $table->string('utm_source')->nullable();
            $table->string('utm_medium')->nullable();
            $table->string('utm_campaign')->nullable();
            $table->char('country', 2)->nullable();
            $table->string('device_type', 16)->nullable();
            $table->string('browser', 32)->nullable();
            $table->string('os', 32)->nullable();
            $table->uuid('visitor_id')->nullable();
            $table->uuid('session_id')->nullable();

            $table->index(['visited_at', 'path']);
            $table->index(['visited_at', 'visitor_id']);
            $table->index('entry_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('insights_hits');
    }
};
