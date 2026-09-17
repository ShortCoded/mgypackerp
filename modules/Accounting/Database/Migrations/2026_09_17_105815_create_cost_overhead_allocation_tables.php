<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('cost_overhead_allocation_rules', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->unsignedBigInteger('doc_number');
            $table->string('doc_num', 100);
            $table->string('name');
            $table->string('name_en')->nullable();
            $table->foreignId('source_cost_center_id')->constrained('cost_centers')->restrictOnDelete();
            $table->json('source_account_ids');
            $table->json('target_cost_center_ids')->nullable();
            $table->string('basis', 40);
            $table->string('fallback_basis', 40)->nullable();
            $table->string('cost_behavior', 20)->default('variable');
            $table->decimal('normal_capacity_hours', 20, 8)->nullable();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'doc_number'], 'cost_oh_rules_company_number_unique');
            $table->unique(['company_id', 'doc_num'], 'cost_oh_rules_company_doc_unique');
            $table->index(['company_id', 'branch_id', 'status'], 'cost_oh_rules_scope_status_index');
        });

        Schema::create('cost_overhead_allocation_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('financial_period_id')->constrained('financial_periods')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('rule_id')->constrained('cost_overhead_allocation_rules')->restrictOnDelete();
            $table->unsignedBigInteger('doc_number');
            $table->string('doc_num', 100);
            $table->date('from_date');
            $table->date('to_date');
            $table->string('status', 20)->default('draft')->index();
            $table->string('basis_used', 40)->nullable();
            $table->text('fallback_reason')->nullable();
            $table->decimal('eligible_cost', 20, 4)->default(0);
            $table->decimal('allocatable_cost', 20, 4)->default(0);
            $table->decimal('allocated_cost', 20, 4)->default(0);
            $table->decimal('unallocated_cost', 20, 4)->default(0);
            $table->decimal('actual_capacity', 20, 8)->nullable();
            $table->decimal('utilization_percent', 12, 4)->nullable();
            $table->string('input_fingerprint', 64);
            $table->string('idempotency_key', 64);
            $table->json('policy_snapshot');
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('reversal_journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'doc_number'], 'cost_oh_runs_company_number_unique');
            $table->unique(['company_id', 'doc_num'], 'cost_oh_runs_company_doc_unique');
            $table->unique(['company_id', 'idempotency_key'], 'cost_oh_runs_company_idempotency_unique');
            $table->index(['company_id', 'financial_period_id', 'branch_id', 'status'], 'cost_oh_runs_scope_status_index');
        });

        Schema::create('cost_overhead_allocation_sources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('allocation_run_id')->constrained('cost_overhead_allocation_runs')->cascadeOnDelete();
            $table->foreignId('journal_entry_line_id')->constrained('journal_entry_lines')->restrictOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->decimal('source_amount', 20, 4);
            $table->timestamps();

            $table->unique(['allocation_run_id', 'journal_entry_line_id'], 'cost_oh_sources_run_line_unique');
            $table->index(['journal_entry_line_id', 'allocation_run_id'], 'cost_oh_sources_line_run_index');
        });

        Schema::create('cost_overhead_allocation_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('allocation_run_id')->constrained('cost_overhead_allocation_runs')->cascadeOnDelete();
            $table->foreignId('production_run_id')->constrained('production_runs')->restrictOnDelete();
            $table->foreignId('cost_center_id')->nullable()->constrained('cost_centers')->restrictOnDelete();
            $table->decimal('machine_hours', 20, 8)->nullable();
            $table->decimal('labor_hours', 20, 8)->nullable();
            $table->decimal('direct_material_cost', 20, 4)->default(0);
            $table->decimal('basis_value', 20, 8)->default(0);
            $table->decimal('allocation_percent', 12, 8)->default(0);
            $table->decimal('allocated_amount', 20, 4)->default(0);
            $table->timestamps();

            $table->unique(['allocation_run_id', 'production_run_id'], 'cost_oh_lines_run_production_unique');
            $table->index(['production_run_id', 'allocation_run_id'], 'cost_oh_lines_production_run_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cost_overhead_allocation_lines');
        Schema::dropIfExists('cost_overhead_allocation_sources');
        Schema::dropIfExists('cost_overhead_allocation_runs');
        Schema::dropIfExists('cost_overhead_allocation_rules');
    }
};
