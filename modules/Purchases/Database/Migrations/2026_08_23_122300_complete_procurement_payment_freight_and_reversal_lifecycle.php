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
        Schema::table('supplier_payment_contexts', function (Blueprint $table): void {
            $table->foreignId('cash_voucher_id')->nullable()->change();
            $table->unsignedInteger('doc_number')->nullable()->after('id');
            $table->string('doc_num', 100)->nullable()->after('doc_number');
            $table->string('payment_method', 20)->default('cash')->after('journal_entry_id');
            $table->date('payment_date')->nullable()->after('payment_method');
            $table->decimal('amount', 18, 4)->default(0)->after('payment_date');
            $table->foreignId('currency_id')->nullable()->after('amount')->constrained('currencies')->restrictOnDelete();
            $table->decimal('exchange_rate', 18, 6)->default(1)->after('currency_id');
            $table->foreignId('bank_account_id')->nullable()->after('exchange_rate')->constrained('bank_accounts')->restrictOnDelete();
            $table->foreignId('cheque_id')->nullable()->after('bank_account_id')->unique()->constrained('cheques')->restrictOnDelete();
            $table->string('status', 20)->default('draft')->after('cheque_id');
            $table->text('reason')->nullable()->after('allocated_amount');
            $table->text('notes')->nullable()->after('reason');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();

            $table->index(['company_id', 'payment_method', 'status'], 'supplier_payment_contexts_method_status_index');
            $table->index(['company_id', 'payment_date'], 'supplier_payment_contexts_payment_date_index');
            $table->unique(['company_id', 'financial_period_id', 'doc_number'], 'supplier_payment_contexts_period_number_unique');
        });

        Schema::table('purchase_invoices', function (Blueprint $table): void {
            $table->decimal('freight_amount', 18, 4)->default(0)->after('line_discount_amount');
            $table->decimal('freight_tax_rate', 8, 4)->default(0)->after('freight_amount');
            $table->decimal('freight_tax_amount', 18, 4)->default(0)->after('freight_tax_rate');
            $table->foreignId('reversal_journal_entry_id')->nullable()->after('journal_entry_id')->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->text('reversal_reason')->nullable();
        });

        $missingLegacyPurchaseReturnColumns = [
            'reversal_journal_entry_id' => ! Schema::hasColumn('purchase_returns', 'reversal_journal_entry_id'),
            'reversed_by' => ! Schema::hasColumn('purchase_returns', 'reversed_by'),
            'reversed_at' => ! Schema::hasColumn('purchase_returns', 'reversed_at'),
        ];

        Schema::table('purchase_returns', function (Blueprint $table) use ($missingLegacyPurchaseReturnColumns): void {
            // Legacy purchase returns already own these reversal fields.
            if ($missingLegacyPurchaseReturnColumns['reversal_journal_entry_id']) {
                $table->foreignId('reversal_journal_entry_id')->nullable()->after('journal_entry_id')->constrained('journal_entries')->nullOnDelete();
            }

            if ($missingLegacyPurchaseReturnColumns['reversed_by']) {
                $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            }

            if ($missingLegacyPurchaseReturnColumns['reversed_at']) {
                $table->timestamp('reversed_at')->nullable();
            }

            $table->text('reversal_reason')->nullable();
        });

        Schema::table('unpriced_inventory_receipts', function (Blueprint $table): void {
            $table->text('cancel_reason')->nullable()->after('cancelled_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('unpriced_inventory_receipts', function (Blueprint $table): void {
            $table->dropColumn('cancel_reason');
        });

        $hasLegacyPurchaseReturns = Schema::hasColumn('purchase_returns', 'source_purchase_invoice_id');

        Schema::table('purchase_returns', function (Blueprint $table) use ($hasLegacyPurchaseReturns): void {
            if (! $hasLegacyPurchaseReturns) {
                $table->dropConstrainedForeignId('reversal_journal_entry_id');
                $table->dropConstrainedForeignId('reversed_by');
                $table->dropColumn('reversed_at');
            }

            $table->dropColumn('reversal_reason');
        });

        Schema::table('purchase_invoices', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reversal_journal_entry_id');
            $table->dropConstrainedForeignId('reversed_by');
            $table->dropColumn([
                'freight_amount',
                'freight_tax_rate',
                'freight_tax_amount',
                'reversed_at',
                'reversal_reason',
            ]);
        });

        Schema::table('supplier_payment_contexts', function (Blueprint $table): void {
            $table->dropUnique('supplier_payment_contexts_period_number_unique');
            $table->dropIndex('supplier_payment_contexts_method_status_index');
            $table->dropIndex('supplier_payment_contexts_payment_date_index');
            $table->dropConstrainedForeignId('currency_id');
            $table->dropConstrainedForeignId('bank_account_id');
            $table->dropConstrainedForeignId('cheque_id');
            $table->dropConstrainedForeignId('approved_by');
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn([
                'doc_number',
                'doc_num',
                'payment_method',
                'payment_date',
                'amount',
                'exchange_rate',
                'status',
                'reason',
                'notes',
                'approved_at',
                'cancelled_at',
                'cancel_reason',
            ]);
            $table->foreignId('cash_voucher_id')->nullable(false)->change();
        });
    }
};
