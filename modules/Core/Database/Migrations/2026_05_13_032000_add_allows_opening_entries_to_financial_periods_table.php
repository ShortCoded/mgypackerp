<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('financial_periods', function (Blueprint $table): void {
            if (! Schema::hasColumn('financial_periods', 'allows_opening_entries')) {
                $table->boolean('allows_opening_entries')->default(true)->after('is_closed')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('financial_periods', function (Blueprint $table): void {
            if (Schema::hasColumn('financial_periods', 'allows_opening_entries')) {
                $table->dropColumn('allows_opening_entries');
            }
        });
    }
};
