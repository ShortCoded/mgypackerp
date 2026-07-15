<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('opening_balances')) {
            return;
        }

        if (Schema::hasColumn('opening_balances', 'opening_balance_type')) {
            $driver = DB::connection()->getDriverName();

            if (in_array($driver, ['pgsql', 'sqlite'], true)) {
                DB::statement('DROP INDEX IF EXISTS opening_balances_opening_balance_type_index');
            } else {
                Schema::table('opening_balances', function (Blueprint $table): void {
                    $table->dropIndex('opening_balances_opening_balance_type_index');
                });
            }
        }

        Schema::table('opening_balances', function (Blueprint $table): void {
            if (Schema::hasColumn('opening_balances', 'opening_balance_type')) {
                $table->dropColumn('opening_balance_type');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('opening_balances')) {
            return;
        }

        Schema::table('opening_balances', function (Blueprint $table): void {
            if (! Schema::hasColumn('opening_balances', 'opening_balance_type')) {
                $table->string('opening_balance_type')->default('general')->index();
            }
        });
    }
};
