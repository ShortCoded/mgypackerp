<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cashbox_currencies') || ! in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            return;
        }

        $grammar = DB::getQueryGrammar();

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS '.$grammar->wrap('cashbox_currencies_unique_active').' ON '.$grammar->wrapTable('cashbox_currencies').' ('.$grammar->wrap('cashbox_id').', '.$grammar->wrap('currency_id').') WHERE '.$grammar->wrap('deleted_at').' IS NULL');
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement('DROP INDEX IF EXISTS '.DB::getQueryGrammar()->wrap('cashbox_currencies_unique_active'));
        }
    }
};
