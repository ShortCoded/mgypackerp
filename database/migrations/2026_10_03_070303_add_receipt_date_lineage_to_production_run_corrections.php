<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_run_corrections', function (Blueprint $table): void {
            $table->json('receipt_date_basis')->nullable();
            $table->text('receipt_date_evidence')->nullable();
        });
        Schema::table('production_runs', fn (Blueprint $table) => $table->json('correction_receipt_basis')->nullable());
    }

    public function down(): void
    {
        if (DB::table('production_run_corrections')->whereNotNull('receipt_date_basis')->exists()
            || DB::table('production_run_corrections')->whereNotNull('receipt_date_evidence')->exists()
            || DB::table('production_runs')->whereNotNull('correction_receipt_basis')->exists()) {
            throw new RuntimeException('Production receipt date lineage must be preserved.');
        }
        Schema::table('production_runs', fn (Blueprint $table) => $table->dropColumn('correction_receipt_basis'));
        Schema::table('production_run_corrections', fn (Blueprint $table) => $table->dropColumn(['receipt_date_basis', 'receipt_date_evidence']));
    }
};
