<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Neutralises MySQL/MariaDB's implicit ON UPDATE CURRENT_TIMESTAMP on the
 * `visited_at` columns of already-migrated installs.
 *
 * When explicit_defaults_for_timestamp is OFF (the MariaDB default before
 * 10.10 - common on managed hosts), the first bare TIMESTAMP column in a table
 * silently becomes `DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`, so
 * ANY update to the row rewrites the timestamp to "now". The multisite
 * migration's site-backfill UPDATE tripped exactly that, collapsing every
 * historical hit time. Converting to DATETIME (which has no such behaviour)
 * makes it impossible to recur. Fresh installs already create these columns as
 * DATETIME; there this is a harmless no-op MODIFY.
 *
 * Only MySQL/MariaDB have the implicit ON UPDATE; SQLite and Postgres don't.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->isMysql()) {
            return;
        }

        DB::statement('ALTER TABLE insights_hits MODIFY visited_at DATETIME NOT NULL');

        if (Schema::hasTable('insights_events')) {
            DB::statement('ALTER TABLE insights_events MODIFY visited_at DATETIME NOT NULL');
        }
    }

    public function down(): void
    {
        if (! $this->isMysql()) {
            return;
        }

        DB::statement('ALTER TABLE insights_hits MODIFY visited_at TIMESTAMP NOT NULL');

        if (Schema::hasTable('insights_events')) {
            DB::statement('ALTER TABLE insights_events MODIFY visited_at TIMESTAMP NOT NULL');
        }
    }

    private function isMysql(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
