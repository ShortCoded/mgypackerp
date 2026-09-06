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
        Schema::table('fixed_asset_movements', function (Blueprint $table): void {
            $table->string('movement_type')->default('transfer')->index();
            $table->foreignId('financial_period_id')->nullable()->constrained('financial_periods')->restrictOnDelete();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('reversal_journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('opening_balance_id')->nullable()->constrained('opening_balances')->restrictOnDelete();
            $table->foreignId('counter_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->foreignId('currency_id')->nullable()->constrained('currencies')->restrictOnDelete();
            $table->decimal('exchange_rate', 18, 6)->nullable();
            $table->decimal('amount', 18, 4)->default(0);
            $table->decimal('base_amount', 18, 4)->default(0);
            $table->decimal('opening_accumulated', 18, 4)->default(0);
            $table->decimal('base_opening_accumulated', 18, 4)->default(0);
            $table->decimal('revised_useful_life', 10, 2)->nullable();
            $table->decimal('revised_residual_value', 18, 4)->nullable();
            $table->json('snapshot')->nullable();
            $table->foreignId('source_custodian_id')->nullable()->constrained('hr_employees')->restrictOnDelete();
            $table->foreignId('destination_custodian_id')->nullable()->constrained('hr_employees')->restrictOnDelete();
            $table->date('reversal_date')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reversal_reason')->nullable();
            $table->index(['fixed_asset_id', 'movement_type', 'status'], 'fixed_asset_movements_type_status');
        });
        Schema::table('fixed_asset_depreciations', function (Blueprint $table): void {
            $table->foreignId('accumulated_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->foreignId('expense_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
        });
        Schema::table('fixed_asset_disposals', function (Blueprint $table): void {
            $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->foreignId('cost_center_id')->nullable()->constrained('cost_centers')->restrictOnDelete();
            $table->foreignId('accumulated_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->decimal('disposal_expenses', 18, 4)->default(0);
            $table->decimal('base_disposal_expenses', 18, 4)->default(0);
            $table->decimal('net_proceeds', 18, 4)->nullable();
            $table->foreignId('expenses_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->foreignId('expenses_journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('expenses_reversal_journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('fixed_asset_depreciations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('accumulated_account_id');
            $table->dropConstrainedForeignId('expense_account_id');
        });
        Schema::table('fixed_asset_disposals', function (Blueprint $table): void {
            foreach (['branch_id', 'cost_center_id', 'accumulated_account_id', 'expenses_account_id', 'expenses_journal_entry_id', 'expenses_reversal_journal_entry_id'] as $column) {
                $table->dropConstrainedForeignId($column);
            }
            $table->dropColumn(['disposal_expenses', 'base_disposal_expenses', 'net_proceeds']);
        });
        Schema::table('fixed_asset_movements', function (Blueprint $table): void {
            $table->dropIndex('fixed_asset_movements_type_status');
            foreach (['financial_period_id', 'journal_entry_id', 'reversal_journal_entry_id', 'opening_balance_id', 'counter_account_id', 'currency_id', 'source_custodian_id', 'destination_custodian_id', 'reversed_by'] as $column) {
                $table->dropConstrainedForeignId($column);
            }
            $table->dropColumn(['movement_type', 'exchange_rate', 'amount', 'base_amount', 'opening_accumulated', 'base_opening_accumulated', 'revised_useful_life', 'revised_residual_value', 'snapshot', 'reversal_date', 'reversed_at', 'reversal_reason']);
        });
    }
};
