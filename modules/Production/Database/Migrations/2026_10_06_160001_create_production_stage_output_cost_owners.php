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
        $schema->table('production_stage_input_consumptions', function (Blueprint $table): void {
            $table->json('cost_components')->nullable();
            $table->json('output_quantities')->nullable();
        });
        $schema->create('production_stage_output_cost_owners', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('financial_period_id')->constrained()->restrictOnDelete();
            $table->foreignId('production_run_id')->constrained('production_runs')->restrictOnDelete();
            $table->foreignId('parent_owner_id')->nullable()->constrained('production_stage_output_cost_owners')->restrictOnDelete();
            $table->foreignId('quality_inspection_id')->nullable()->constrained('quality_inspections')->restrictOnDelete();
            $table->uuid('submission_token');
            $table->string('kind', 20);
            $table->string('output_kind', 20)->nullable();
            $table->string('status', 20)->default('prepared');
            $table->date('posting_date');
            $table->decimal('base_quantity', 20, 8);
            $table->decimal('total_cost', 20, 8);
            $table->decimal('booked_loss_amount', 20, 4)->default(0);
            $table->json('source_snapshot');
            $table->json('posting_snapshot');
            $table->char('proposal_seal', 64);
            $table->json('execution_snapshot')->nullable();
            $table->char('execution_seal', 64)->nullable();
            $table->json('reversal_execution_snapshot')->nullable();
            $table->char('reversal_execution_seal', 64)->nullable();
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
            $table->unique(['company_id', 'submission_token'], 'production_stage_output_token_unique');
            $table->index(['production_run_id', 'kind', 'status'], 'production_stage_output_run_index');
        });
        $schema->table('production_progress_entries', function (Blueprint $table): void {
            $table->foreignId('stage_output_cost_owner_id')->nullable()->constrained('production_stage_output_cost_owners')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        $connection = DB::connection($this->getConnection());
        if ($connection->table('production_stage_output_cost_owners')->exists()
            || $connection->table('production_stage_input_consumptions')->whereNotNull('cost_components')->exists()) {
            throw new RuntimeException('Stage component and output ownership history must be preserved.');
        }
        $schema = Schema::connection($this->getConnection());
        $schema->table('production_progress_entries', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('stage_output_cost_owner_id');
        });
        $schema->dropIfExists('production_stage_output_cost_owners');
        $schema->table('production_stage_input_consumptions', function (Blueprint $table): void {
            $table->dropColumn(['cost_components', 'output_quantities']);
        });
    }
};
