<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        /** SQLite table rebuilds for new foreign keys discard partial index predicates. */
        DB::statement('DROP INDEX IF EXISTS fixed_asset_depreciations_period_posted_unique');
        DB::statement("CREATE UNIQUE INDEX fixed_asset_depreciations_period_posted_unique ON fixed_asset_depreciations (fixed_asset_id, period_end) WHERE status = 'posted'");
    }

    public function down(): void
    {
        /** Preserve the original posted-only contract when rolling back this repair. */
    }
};
