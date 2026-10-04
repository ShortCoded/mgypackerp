<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_cost_policy_transitions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->foreignId('branch_store_id')->nullable()->constrained('branch_stores')->restrictOnDelete();
            $table->string('scope_key', 80);
            $table->date('effective_from');
            $table->string('status', 30);
            $table->string('input_fingerprint', 64);
            $table->decimal('total_quantity', 20, 8);
            $table->decimal('total_book_value', 20, 8);
            $table->text('reason')->nullable();
            $table->foreignId('prepared_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('prepared_at');
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('activated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->foreignId('inventory_cost_policy_id')->nullable()->constrained('inventory_cost_policies')->restrictOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'scope_key', 'effective_from'], 'inventory_cost_transitions_scope_date_index');
            $table->index(['company_id', 'status'], 'inventory_cost_transitions_status_index');
        });

        Schema::create('inventory_cost_policy_transition_bases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inventory_cost_policy_transition_id')->constrained('inventory_cost_policy_transitions')->restrictOnDelete();
            $table->foreignId('inventory_receipt_layer_id')->constrained('inventory_receipt_layers')->restrictOnDelete();
            $table->foreignId('branch_store_id')->constrained('branch_stores')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('warehouse_location_id')->nullable()->constrained('warehouse_locations')->restrictOnDelete();
            $table->unsignedBigInteger('production_run_id')->nullable();
            $table->string('stock_status', 30);
            $table->string('batch_lot', 100)->nullable();
            $table->decimal('original_quantity', 20, 8);
            $table->decimal('original_book_value', 20, 8);
            $table->decimal('basis_unit_cost', 20, 8);
            $table->decimal('remaining_quantity', 20, 8);
            $table->decimal('remaining_book_value', 20, 8);
            $table->timestamps();

            $table->unique(
                ['inventory_cost_policy_transition_id', 'inventory_receipt_layer_id'],
                'inventory_cost_transition_basis_layer_unique',
            );
            $table->index('inventory_receipt_layer_id', 'inventory_cost_transition_basis_receipt_index');
        });

        Schema::table('inventory_layer_allocations', function (Blueprint $table): void {
            $table->foreignId('inventory_cost_policy_transition_basis_id')
                ->nullable()->constrained('inventory_cost_policy_transition_bases')->restrictOnDelete();
            $table->decimal('cost_unit_snapshot', 20, 8)->nullable();
            $table->decimal('cost_total_snapshot', 20, 8)->nullable();
        });

        Schema::table('inventory_receipt_layers', function (Blueprint $table): void {
            $table->decimal('source_allocation_cost_snapshot', 20, 8)->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('inventory_cost_policy_transitions')->exists()
            || DB::table('inventory_layer_allocations')->whereNotNull('cost_total_snapshot')->exists()
            || DB::table('inventory_receipt_layers')->whereNotNull('source_allocation_cost_snapshot')->exists()) {
            throw new RuntimeException('Inventory cost-policy transition evidence exists; rollback would discard valuation history.');
        }

        Schema::table('inventory_receipt_layers', function (Blueprint $table): void {
            $table->dropColumn('source_allocation_cost_snapshot');
        });
        Schema::table('inventory_layer_allocations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('inventory_cost_policy_transition_basis_id');
            $table->dropColumn(['cost_unit_snapshot', 'cost_total_snapshot']);
        });
        Schema::dropIfExists('inventory_cost_policy_transition_bases');
        Schema::dropIfExists('inventory_cost_policy_transitions');
    }
};
