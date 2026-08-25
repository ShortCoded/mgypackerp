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
        Schema::table('fixed_asset_category_mappings', function (Blueprint $table): void {
            $table->foreignId('disposal_clearing_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
        });
        Schema::table('fixed_asset_disposals', function (Blueprint $table): void {
            $table->string('settlement_path', 30)->default('direct_settlement');
            $table->decimal('tax_rate', 10, 4)->default(0);
            $table->decimal('tax_amount', 18, 4)->default(0);
            $table->decimal('gross_proceeds', 18, 4)->default(0);
            $table->foreignId('customer_invoice_id')->nullable()->constrained('customer_invoices')->restrictOnDelete();
            $table->foreignId('gain_loss_journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('gain_loss_reversal_journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('fixed_asset_disposals', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('gain_loss_reversal_journal_entry_id');
            $table->dropConstrainedForeignId('gain_loss_journal_entry_id');
            $table->dropConstrainedForeignId('customer_invoice_id');
            $table->dropColumn(['settlement_path', 'tax_rate', 'tax_amount', 'gross_proceeds']);
        });
        Schema::table('fixed_asset_category_mappings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('disposal_clearing_account_id');
        });
    }
};
