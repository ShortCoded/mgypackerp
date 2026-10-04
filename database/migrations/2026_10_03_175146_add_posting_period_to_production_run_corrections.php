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
        Schema::table('production_run_corrections', function (Blueprint $table): void {
            $table->foreignId('posting_financial_period_id')->nullable()->constrained('financial_periods')->restrictOnDelete();
            $table->string('correction_mode', 30)->nullable();
        });
        Schema::table('production_runs', function (Blueprint $table): void {
            $table->foreignId('correction_posting_financial_period_id')->nullable()->constrained('financial_periods')->restrictOnDelete();
            $table->foreignId('active_correction_id')->nullable()->constrained('production_run_corrections')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('production_run_corrections')->whereNotNull('posting_financial_period_id')->orWhereNotNull('correction_mode')->exists()
            || DB::table('production_runs')->whereNotNull('correction_posting_financial_period_id')->orWhereNotNull('active_correction_id')->exists()) {
            throw new RuntimeException('Preserve approved production correction posting periods before rollback.');
        }
        Schema::table('production_runs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('active_correction_id');
            $table->dropConstrainedForeignId('correction_posting_financial_period_id');
        });
        Schema::table('production_run_corrections', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('posting_financial_period_id');
            $table->dropColumn('correction_mode');
        });
    }
};
