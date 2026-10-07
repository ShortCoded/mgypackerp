<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());
        $schema->create('production_stage_transfers', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('financial_period_id')->constrained()->restrictOnDelete();
            $table->foreignId('source_run_id')->constrained('production_runs')->restrictOnDelete();
            $table->foreignId('target_run_id')->constrained('production_runs')->restrictOnDelete();
            $table->uuid('submission_token');
            $table->date('posting_date');
            $table->string('status', 20)->default('prepared');
            $table->decimal('base_quantity', 20, 8);
            $table->decimal('total_cost', 20, 8);
            $table->decimal('booked_amount', 20, 4);
            $table->json('source_snapshot');
            $table->json('posting_snapshot');
            $table->char('proposal_seal', 64);
            $table->text('reason');
            $table->text('evidence');
            $table->foreignId('prepared_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('reversal_journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'submission_token'], 'production_stage_transfer_token_unique');
            $table->index(['source_run_id', 'status']);
            $table->index(['target_run_id', 'status']);
        });
        $schema->create('production_stage_quality_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('production_stage_transfer_id')->constrained('production_stage_transfers')->restrictOnDelete();
            $table->foreignId('production_quality_output_batch_id')->constrained('production_quality_output_batches')->restrictOnDelete();
            $table->decimal('base_quantity', 20, 8);
            $table->timestamps();
            $table->unique(['production_stage_transfer_id', 'production_quality_output_batch_id'], 'production_stage_quality_unique');
        });
        $schema->create('production_stage_input_consumptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('production_stage_transfer_id')->constrained('production_stage_transfers')->restrictOnDelete();
            $table->foreignId('production_progress_entry_id')->constrained('production_progress_entries')->restrictOnDelete();
            $table->decimal('base_quantity', 20, 8);
            $table->decimal('total_cost', 20, 8);
            $table->char('evidence_seal', 64);
            $table->timestamps();
            $table->unique(['production_stage_transfer_id', 'production_progress_entry_id'], 'production_stage_input_unique');
        });
    }

    public function down(): void
    {
        if (DB::connection($this->getConnection())->table('production_stage_transfers')->exists()) {
            throw new RuntimeException('Prepared and executed stage transfer ownership must be preserved.');
        }
        $schema = Schema::connection($this->getConnection());
        $schema->dropIfExists('production_stage_input_consumptions');
        $schema->dropIfExists('production_stage_quality_allocations');
        $schema->dropIfExists('production_stage_transfers');
    }
};
