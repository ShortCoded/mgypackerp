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
        DB::table('hr_payroll_posting_column_ownership')->insertOrIgnore([
            'column_name' => 'hr_payroll_corrections.correction_mode',
            'created_by_migration' => ! Schema::hasColumn('hr_payroll_corrections', 'correction_mode'),
        ]);
        if (! Schema::hasColumn('hr_payroll_corrections', 'correction_mode')) {
            Schema::table('hr_payroll_corrections', function (Blueprint $table): void {
                $table->string('correction_mode', 30)->nullable();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('hr_payroll_posting_column_ownership')) {
            throw new RuntimeException('Payroll correction column ownership is missing; retain its evidence.');
        }
        $owned = DB::table('hr_payroll_posting_column_ownership')->where('column_name', 'hr_payroll_corrections.correction_mode')->value('created_by_migration');
        if ($owned && DB::table('hr_payroll_corrections')->whereNotNull('correction_mode')->exists()) {
            throw new RuntimeException('Payroll correction mode and approval evidence must be preserved.');
        }
        if ($owned) {
            Schema::table('hr_payroll_corrections', function (Blueprint $table): void {
                $table->dropColumn('correction_mode');
            });
        }
        DB::table('hr_payroll_posting_column_ownership')->where('column_name', 'hr_payroll_corrections.correction_mode')->delete();
    }
};
