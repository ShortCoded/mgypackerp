<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['production_stage_transfers', 'production_stage_output_cost_owners'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->foreignId('reversal_posting_financial_period_id')->nullable()->constrained('financial_periods')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['production_stage_transfers', 'production_stage_output_cost_owners'] as $name) {
            if (DB::table($name)->whereNotNull('reversal_posting_financial_period_id')->exists()) {
                throw new RuntimeException('Posted stage-owner reversal periods must be preserved.');
            }
            Schema::table($name, function (Blueprint $table): void {
                $table->dropConstrainedForeignId('reversal_posting_financial_period_id');
            });
        }
    }
};
