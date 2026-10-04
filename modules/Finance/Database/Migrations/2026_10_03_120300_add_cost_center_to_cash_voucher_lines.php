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
        if (! Schema::hasTable('cash_voucher_cost_center_column_ownership')) {
            Schema::create('cash_voucher_cost_center_column_ownership', function (Blueprint $table): void {
                $table->string('column_name')->primary();
                $table->boolean('created_by_migration');
            });
        }
        DB::table('cash_voucher_cost_center_column_ownership')->insertOrIgnore([
            'column_name' => 'cost_center_id', 'created_by_migration' => ! Schema::hasColumn('cash_voucher_lines', 'cost_center_id'),
        ]);
        if (! Schema::hasColumn('cash_voucher_lines', 'cost_center_id')) {
            Schema::table('cash_voucher_lines', function (Blueprint $table): void {
                $table->foreignId('cost_center_id')->nullable()->constrained('cost_centers')->restrictOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('cash_voucher_cost_center_column_ownership')
            || ! DB::table('cash_voucher_cost_center_column_ownership')->where('column_name', 'cost_center_id')->exists()) {
            throw new RuntimeException('Cash voucher cost-center column ownership is missing; preserve costing history.');
        }
        if (DB::table('cash_voucher_cost_center_column_ownership')->where('column_name', 'cost_center_id')->value('created_by_migration')) {
            if (DB::table('cash_voucher_lines')->whereNotNull('cost_center_id')->exists()) {
                throw new RuntimeException('Cash voucher cost centers are in use; rollback would discard cost attribution.');
            }
            Schema::table('cash_voucher_lines', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('cost_center_id');
            });
        }
        DB::table('cash_voucher_cost_center_column_ownership')->where('column_name', 'cost_center_id')->delete();
    }
};
