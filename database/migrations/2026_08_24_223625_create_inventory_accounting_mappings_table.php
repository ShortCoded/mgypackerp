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
        Schema::create('inventory_accounting_mappings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('valuation_method', 40)->default('moving_average');
            $table->foreignId('raw_material_inventory_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('packaging_inventory_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('semi_finished_inventory_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('finished_goods_inventory_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('wip_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('production_waste_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('recoverable_scrap_inventory_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->foreignId('warehouse_damage_loss_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('inventory_adjustment_gain_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('inventory_adjustment_loss_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('production_variance_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->foreignId('production_cost_center_id')->nullable()->constrained('cost_centers')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('company_id', 'inventory_accounting_mappings_company_unique');
            $table->index(['company_id', 'wip_account_id'], 'inventory_accounting_mappings_wip_index');
        });

        Schema::table('journal_entries', function (Blueprint $table): void {
            $table->unique(
                ['company_id', 'source_type', 'source_id'],
                'journal_entries_system_source_unique',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table): void {
            $table->dropUnique('journal_entries_system_source_unique');
        });

        Schema::dropIfExists('inventory_accounting_mappings');
    }
};
