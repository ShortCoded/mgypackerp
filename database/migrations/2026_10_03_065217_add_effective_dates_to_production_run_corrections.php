<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_run_corrections', fn (Blueprint $table) => $table->date('posting_date')->nullable());
        Schema::table('production_runs', function (Blueprint $table): void {
            $table->date('correction_document_date')->nullable();
            $table->date('correction_manufacture_date')->nullable();
            $table->date('correction_expiry_date')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('production_run_corrections')->whereNotNull('posting_date')->exists()
            || DB::table('production_runs')->whereNotNull('correction_document_date')->exists()
            || DB::table('production_runs')->whereNotNull('correction_manufacture_date')->exists()
            || DB::table('production_runs')->whereNotNull('correction_expiry_date')->exists()) {
            throw new RuntimeException('Dated production correction evidence must be preserved.');
        }
        Schema::table('production_runs', fn (Blueprint $table) => $table->dropColumn(['correction_document_date', 'correction_manufacture_date', 'correction_expiry_date']));
        Schema::table('production_run_corrections', fn (Blueprint $table) => $table->dropColumn('posting_date'));
    }
};
