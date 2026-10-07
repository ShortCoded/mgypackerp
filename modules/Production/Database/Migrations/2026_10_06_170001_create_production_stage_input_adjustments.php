<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection($this->getConnection())->create('production_stage_input_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('production_run_correction_id')->constrained('production_run_corrections')->restrictOnDelete();
            $table->foreignId('production_stage_transfer_id')->constrained('production_stage_transfers')->restrictOnDelete();
            $table->foreignId('production_progress_entry_id')->constrained('production_progress_entries')->restrictOnDelete();
            $table->decimal('base_quantity', 20, 8);
            $table->decimal('total_cost', 20, 8);
            $table->json('cost_components');
            $table->timestamps();
            $table->unique(['production_run_correction_id', 'production_stage_transfer_id'], 'production_stage_input_correction_unique');
        });
    }

    public function down(): void
    {
        if (DB::connection($this->getConnection())->table('production_stage_input_adjustments')->exists()) {
            throw new RuntimeException('Audited stage input adjustment history must be preserved.');
        }
        Schema::connection($this->getConnection())->dropIfExists('production_stage_input_adjustments');
    }
};
