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
        $legacyReturns = DB::table('inventory_receipt_layers as layer')
            ->join('inventory_transactions as movement', 'movement.id', '=', 'layer.receipt_transaction_id')
            ->whereIn('movement.transaction_type', ['sales_return_receipt', 'maintenance_material_return'])
            ->where('movement.is_reversal', false)
            ->exists();

        if ($legacyReturns) {
            throw new RuntimeException('Legacy return receipt layers require a reconciled allocation-lineage backfill before installing inventory cost policies.');
        }

        Schema::create('inventory_cost_policies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->foreignId('branch_store_id')->nullable()->constrained('branch_stores')->restrictOnDelete();
            $table->string('scope_key', 80);
            $table->string('method', 40);
            $table->date('effective_from');
            $table->text('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'scope_key', 'effective_from'], 'inventory_cost_policies_scope_date_unique');
            $table->index(['company_id', 'effective_from'], 'inventory_cost_policies_effective_index');
        });

        Schema::table('inventory_transactions', function (Blueprint $table): void {
            $table->foreignId('cost_policy_id')->nullable()->constrained('inventory_cost_policies')->restrictOnDelete();
            $table->string('cost_method', 40)->nullable();
            $table->string('cost_basis', 40)->nullable();
        });

        Schema::table('inventory_receipt_layers', function (Blueprint $table): void {
            $table->foreignId('source_allocation_id')->nullable()->constrained('inventory_layer_allocations')->restrictOnDelete();
            $table->index('source_allocation_id', 'inventory_receipt_layers_source_allocation_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('inventory_cost_policies')->exists()
            || DB::table('inventory_transactions')->whereNotNull('cost_policy_id')->exists()
            || DB::table('inventory_transactions')->whereNotNull('cost_method')->exists()
            || DB::table('inventory_transactions')->whereNotNull('cost_basis')->exists()
            || DB::table('inventory_receipt_layers')->whereNotNull('source_allocation_id')->exists()) {
            throw new RuntimeException('Inventory cost-policy and allocation history is populated; rollback would discard audit evidence.');
        }

        Schema::table('inventory_receipt_layers', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('source_allocation_id');
        });

        Schema::table('inventory_transactions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('cost_policy_id');
            $table->dropColumn(['cost_method', 'cost_basis']);
        });

        Schema::dropIfExists('inventory_cost_policies');
    }
};
