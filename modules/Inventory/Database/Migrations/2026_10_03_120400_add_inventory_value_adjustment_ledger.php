<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_value_adjustment_column_ownership', function (Blueprint $table): void {
            $table->string('column_name')->primary();
            $table->boolean('created_by_migration');
        });
        foreach (['value_delta', 'unvalued_quantity_delta'] as $column) {
            $owned = ! Schema::hasColumn('inventory_transactions', $column);
            DB::table('inventory_value_adjustment_column_ownership')->insert(['column_name' => $column, 'created_by_migration' => $owned]);
            if ($owned) {
                Schema::table('inventory_transactions', fn (Blueprint $table) => $table->decimal($column, 20, 8)->nullable());
            }
        }
        Schema::create('inventory_value_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('financial_period_id')->constrained('financial_periods')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('source_type');
            $table->unsignedBigInteger('source_id');
            $table->string('source_doc_num');
            $table->string('status', 20);
            $table->date('posting_date');
            $table->json('source_snapshot');
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('reversal_journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at');
            $table->foreignId('reversed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->date('reversal_date')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->timestamps();
            $table->unique(['source_type', 'source_id'], 'inventory_value_adjustment_source_unique');
        });
        Schema::create('inventory_value_adjustment_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inventory_value_adjustment_id')->constrained('inventory_value_adjustments')->restrictOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('cost_center_id')->nullable()->constrained('cost_centers')->restrictOnDelete();
            $table->foreignId('source_transaction_id')->nullable()->constrained('inventory_transactions')->restrictOnDelete();
            $table->foreignId('inventory_transaction_id')->nullable()->constrained('inventory_transactions')->restrictOnDelete();
            $table->foreignId('production_run_id')->nullable()->constrained('production_runs')->restrictOnDelete();
            $table->foreignId('inventory_document_line_id')->nullable()->constrained('inventory_document_lines')->restrictOnDelete();
            $table->string('production_cost_role', 20)->nullable();
            $table->decimal('production_cost_delta', 20, 8)->nullable();
            $table->string('effect', 40);
            $table->decimal('amount', 20, 8);
            $table->decimal('unvalued_quantity_delta', 20, 8)->default(0);
            $table->json('source_snapshot');
            $table->timestamps();
        });
        Schema::table('inventory_receipt_cost_proposals', function (Blueprint $table): void {
            $table->date('posting_date')->nullable();
            $table->foreignId('posting_period_id')->nullable()->constrained('financial_periods')->restrictOnDelete();
            $table->foreignId('counterpart_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->json('impact_snapshot')->nullable();
            $table->string('impact_sha256', 64)->nullable();
        });
        Schema::create('inventory_receipt_cost_bases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inventory_value_adjustment_id')->constrained('inventory_value_adjustments')->restrictOnDelete();
            $table->foreignId('inventory_receipt_layer_id')->constrained('inventory_receipt_layers')->restrictOnDelete();
            $table->decimal('original_total_cost', 20, 8)->nullable();
            $table->decimal('completed_total_cost', 20, 8);
            $table->decimal('remaining_quantity', 20, 8);
            $table->decimal('remaining_value', 20, 8);
            $table->timestamps();
            $table->unique(['inventory_value_adjustment_id', 'inventory_receipt_layer_id'], 'receipt_cost_basis_source_unique');
        });
        Schema::create('inventory_allocation_cost_completions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inventory_value_adjustment_id')->constrained('inventory_value_adjustments')->restrictOnDelete();
            $table->foreignId('inventory_layer_allocation_id')->constrained('inventory_layer_allocations')->restrictOnDelete();
            $table->decimal('original_total_cost', 20, 8)->nullable();
            $table->decimal('completed_total_cost', 20, 8);
            $table->timestamps();
            $table->unique(['inventory_value_adjustment_id', 'inventory_layer_allocation_id'], 'allocation_cost_completion_source_unique');
        });
    }

    public function down(): void
    {
        if (DB::table('inventory_value_adjustments')->exists() || DB::table('inventory_value_adjustment_lines')->exists()
            || DB::table('inventory_receipt_cost_proposals')->whereNotNull('posting_date')->exists()) {
            throw new RuntimeException('Inventory value-adjustment source and approval history must be preserved.');
        }
        foreach (['value_delta', 'unvalued_quantity_delta'] as $column) {
            if (! Schema::hasTable('inventory_value_adjustment_column_ownership')
                || ! DB::table('inventory_value_adjustment_column_ownership')->where('column_name', $column)->exists()) {
                throw new RuntimeException('Inventory value-adjustment column ownership is missing.');
            }
            if (DB::table('inventory_value_adjustment_column_ownership')->where('column_name', $column)->value('created_by_migration')
                && DB::table('inventory_transactions')->whereNotNull($column)->exists()) {
                throw new RuntimeException('Inventory value-adjustment evidence is in use.');
            }
        }
        Schema::drop('inventory_allocation_cost_completions');
        Schema::drop('inventory_receipt_cost_bases');
        Schema::table('inventory_receipt_cost_proposals', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('posting_period_id');
            $table->dropConstrainedForeignId('counterpart_account_id');
            $table->dropColumn(['posting_date', 'impact_snapshot', 'impact_sha256']);
        });
        Schema::drop('inventory_value_adjustment_lines');
        Schema::drop('inventory_value_adjustments');
        foreach (['value_delta', 'unvalued_quantity_delta'] as $column) {
            if (DB::table('inventory_value_adjustment_column_ownership')->where('column_name', $column)->value('created_by_migration')) {
                Schema::table('inventory_transactions', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
        Schema::drop('inventory_value_adjustment_column_ownership');
    }
};
