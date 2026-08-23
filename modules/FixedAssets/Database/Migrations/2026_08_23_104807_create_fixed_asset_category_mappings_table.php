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
        Schema::create('fixed_asset_category_mappings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('asset_group_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('accumulated_depreciation_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('depreciation_expense_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('disposal_gain_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('disposal_loss_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'asset_group_account_id'], 'fixed_asset_category_mapping_unique');
            $table->index(['company_id', 'accumulated_depreciation_account_id'], 'fixed_asset_mapping_accumulated_index');
            $table->index(['company_id', 'depreciation_expense_account_id'], 'fixed_asset_mapping_expense_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fixed_asset_category_mappings');
    }
};
