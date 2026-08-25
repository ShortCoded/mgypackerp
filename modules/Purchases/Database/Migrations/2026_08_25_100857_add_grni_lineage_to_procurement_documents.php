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
        Schema::table('unpriced_inventory_receipts', function (Blueprint $table): void {
            $table->foreignId('grni_journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
        });
        Schema::table('unpriced_inventory_receipt_lines', function (Blueprint $table): void {
            $table->foreignId('grni_journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->decimal('provisional_unit_value', 20, 8)->default(0);
            $table->decimal('provisional_total_value', 20, 4)->default(0);
            $table->decimal('grni_cleared_quantity', 20, 8)->default(0);
            $table->decimal('grni_cleared_value', 20, 4)->default(0);
            $table->decimal('grni_returned_quantity', 20, 8)->default(0);
            $table->decimal('grni_returned_value', 20, 4)->default(0);
            $table->date('manufacture_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->index(['company_id', 'receipt_id', 'grni_cleared_quantity'], 'receipt_lines_grni_index');
        });
        Schema::table('purchase_invoice_lines', function (Blueprint $table): void {
            $table->decimal('grni_cleared_quantity', 20, 8)->default(0);
            $table->decimal('grni_cleared_value', 20, 4)->default(0);
            $table->decimal('purchase_price_variance', 20, 4)->default(0);
        });
        Schema::table('purchase_returns', function (Blueprint $table): void {
            $table->foreignId('grni_reversal_journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
        });
        Schema::table('purchase_return_lines', function (Blueprint $table): void {
            $table->decimal('grni_reversed_value', 20, 4)->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('purchase_return_lines', function (Blueprint $table): void {
            $table->dropColumn('grni_reversed_value');
        });
        Schema::table('purchase_returns', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('grni_reversal_journal_entry_id');
        });
        Schema::table('purchase_invoice_lines', function (Blueprint $table): void {
            $table->dropColumn(['grni_cleared_quantity', 'grni_cleared_value', 'purchase_price_variance']);
        });
        Schema::table('unpriced_inventory_receipt_lines', function (Blueprint $table): void {
            $table->dropIndex('receipt_lines_grni_index');
            $table->dropConstrainedForeignId('grni_journal_entry_id');
            $table->dropColumn([
                'provisional_unit_value', 'provisional_total_value', 'grni_cleared_quantity',
                'grni_cleared_value', 'grni_returned_quantity', 'grni_returned_value',
                'manufacture_date', 'expiry_date',
            ]);
        });
        Schema::table('unpriced_inventory_receipts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('grni_journal_entry_id');
        });
    }
};
