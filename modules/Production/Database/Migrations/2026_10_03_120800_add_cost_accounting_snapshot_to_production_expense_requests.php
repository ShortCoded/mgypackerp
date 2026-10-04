<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('production_expense_requests', function (Blueprint $table) {
            $table->json('cost_accounting_snapshot')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('production_expense_requests')->whereNotNull('cost_accounting_snapshot')->exists()) {
            throw new RuntimeException('Preserve posted production expense accounting snapshots before rollback.');
        }
        Schema::table('production_expense_requests', function (Blueprint $table) {
            $table->dropColumn('cost_accounting_snapshot');
        });
    }
};
