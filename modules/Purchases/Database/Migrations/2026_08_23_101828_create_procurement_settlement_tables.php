<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $missingLegacyPurchaseInvoiceColumns = [
            'purchase_order_id' => ! Schema::hasColumn('purchase_invoices', 'purchase_order_id'),
            'purchase_type' => ! Schema::hasColumn('purchase_invoices', 'purchase_type'),
        ];

        Schema::table('purchase_invoices', function (Blueprint $table) use ($missingLegacyPurchaseInvoiceColumns): void {
            // Preserve the columns and SET NULL relationship owned by the legacy
            // 2026_07_18 purchase-cycle migration when upgrading that schema.
            if ($missingLegacyPurchaseInvoiceColumns['purchase_order_id']) {
                $table->foreignId('purchase_order_id')->nullable()->constrained('purchase_orders')->nullOnDelete();
            }

            if ($missingLegacyPurchaseInvoiceColumns['purchase_type']) {
                $table->string('purchase_type', 30)->default('stock');
            }

            $table->string('matching_status', 30)->default('not_matched');
            $table->text('matching_notes')->nullable();
            $table->boolean('direct_procurement_override')->default(false);
            $table->text('direct_procurement_reason')->nullable();
            $table->decimal('credited_amount', 18, 4)->default(0);

            $table->index(['company_id', 'purchase_order_id', 'status'], 'purchase_invoices_po_status_index');
            $table->index(['company_id', 'matching_status'], 'purchase_invoices_matching_status_index');
        });

        $missingLegacyPurchaseInvoiceLineColumns = [
            'purchase_order_line_id' => ! Schema::hasColumn('purchase_invoice_lines', 'purchase_order_line_id'),
            'description' => ! Schema::hasColumn('purchase_invoice_lines', 'description'),
        ];

        Schema::table('purchase_invoice_lines', function (Blueprint $table) use ($missingLegacyPurchaseInvoiceLineColumns): void {
            if ($missingLegacyPurchaseInvoiceLineColumns['purchase_order_line_id']) {
                $table->foreignId('purchase_order_line_id')->nullable()->constrained('purchase_order_lines')->nullOnDelete();
            }

            if ($missingLegacyPurchaseInvoiceLineColumns['description']) {
                $table->string('description')->nullable();
            }

            $table->foreignId('receipt_line_id')->nullable()->constrained('unpriced_inventory_receipt_lines')->restrictOnDelete();
            $table->decimal('matched_quantity', 20, 8)->default(0);

            $table->index(['purchase_order_line_id', 'receipt_line_id'], 'purchase_invoice_lines_procurement_source_index');
        });

        Schema::table('purchase_invoice_payment_schedules', function (Blueprint $table): void {
            $table->decimal('paid_amount', 18, 4)->default(0);
            $table->decimal('credited_amount', 18, 4)->default(0);
        });

        Schema::create('purchase_order_change_requests', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('doc_number');
            $table->string('doc_num', 100);
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('financial_period_id')->constrained('financial_periods')->restrictOnDelete();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->restrictOnDelete();
            $table->date('request_date');
            $table->json('original_values');
            $table->json('requested_values');
            $table->text('reason');
            $table->string('status', 30)->default('pending');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'purchase_order_id', 'status'], 'purchase_order_change_requests_po_status_index');
        });

        $this->createOrExtendPurchaseReturns();

        Schema::create('supplier_payment_contexts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cash_voucher_id')->unique()->constrained('cash_vouchers')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('financial_period_id')->constrained('financial_periods')->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->foreignId('purchase_order_id')->nullable()->constrained('purchase_orders')->nullOnDelete();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->boolean('is_advance')->default(false);
            $table->decimal('allocated_amount', 18, 4)->default(0);
            $table->timestamps();

            $table->index(['company_id', 'supplier_id', 'is_advance'], 'supplier_payment_contexts_supplier_advance_index');
        });

        Schema::create('supplier_payment_allocations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('supplier_payment_context_id')->constrained('supplier_payment_contexts')->cascadeOnDelete();
            $table->foreignId('purchase_invoice_id')->constrained('purchase_invoices')->restrictOnDelete();
            $table->foreignId('payment_schedule_id')->nullable()->constrained('purchase_invoice_payment_schedules')->nullOnDelete();
            $table->decimal('amount', 18, 4);
            $table->foreignId('allocated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('allocated_at');
            $table->timestamps();

            $table->unique(['supplier_payment_context_id', 'purchase_invoice_id', 'payment_schedule_id'], 'supplier_payment_allocations_source_unique');
            $table->index(['purchase_invoice_id', 'allocated_at'], 'supplier_payment_allocations_invoice_date_index');
        });

        $this->backfillLegacyCashVoucherAllocations();
        $this->createActiveUniqueIndexes();
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_payment_allocations');
        Schema::dropIfExists('supplier_payment_contexts');
        $this->dropOwnedPurchaseReturnSchema();
        Schema::dropIfExists('purchase_order_change_requests');

        Schema::table('purchase_invoice_payment_schedules', function (Blueprint $table): void {
            $table->dropColumn(['paid_amount', 'credited_amount']);
        });

        $hasLegacyPurchaseInvoiceLines = Schema::hasColumn('purchase_invoice_lines', 'goods_receipt_line_id');
        $hasLegacyPurchaseInvoices = Schema::hasColumn('purchase_invoices', 'work_order_id');

        Schema::table('purchase_invoice_lines', function (Blueprint $table) use ($hasLegacyPurchaseInvoiceLines): void {
            $table->dropConstrainedForeignId('receipt_line_id');

            if (! $hasLegacyPurchaseInvoiceLines) {
                $table->dropConstrainedForeignId('purchase_order_line_id');
                $table->dropColumn('description');
            }

            $table->dropColumn('matched_quantity');
        });

        Schema::table('purchase_invoices', function (Blueprint $table) use ($hasLegacyPurchaseInvoices): void {
            if (! $hasLegacyPurchaseInvoices) {
                $table->dropConstrainedForeignId('purchase_order_id');
                $table->dropColumn('purchase_type');
            }

            $table->dropColumn(['matching_status', 'matching_notes', 'direct_procurement_override', 'direct_procurement_reason', 'credited_amount']);
        });
    }

    private function createOrExtendPurchaseReturns(): void
    {
        if (Schema::hasTable('purchase_returns')) {
            $this->extendLegacyPurchaseReturns();

            return;
        }

        Schema::create('purchase_returns', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('doc_number');
            $table->string('doc_num', 100);
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('financial_period_id')->constrained('financial_periods')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('branch_store_id')->constrained('branch_stores')->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->restrictOnDelete();
            $table->foreignId('receipt_id')->nullable()->constrained('unpriced_inventory_receipts')->nullOnDelete();
            $table->foreignId('purchase_invoice_id')->nullable()->constrained('purchase_invoices')->nullOnDelete();
            $table->date('return_date');
            $table->string('reason_code', 50);
            $table->string('status', 30)->default('draft');
            $table->decimal('total_quantity', 20, 8)->default(0);
            $table->decimal('total_amount', 18, 4)->default(0);
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'supplier_id', 'return_date'], 'purchase_returns_supplier_date_index');
            $table->index(['company_id', 'status'], 'purchase_returns_context_status_index');
        });

        Schema::create('purchase_return_lines', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('purchase_return_id')->constrained('purchase_returns')->cascadeOnDelete();
            $table->foreignId('purchase_order_line_id')->constrained('purchase_order_lines')->restrictOnDelete();
            $table->foreignId('receipt_line_id')->constrained('unpriced_inventory_receipt_lines')->restrictOnDelete();
            $table->foreignId('purchase_invoice_line_id')->nullable()->constrained('purchase_invoice_lines')->nullOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained('item_units')->nullOnDelete();
            $table->decimal('quantity', 20, 8);
            $table->boolean('from_quarantine')->default(false);
            $table->decimal('unit_price', 18, 4)->default(0);
            $table->decimal('tax_amount', 18, 4)->default(0);
            $table->decimal('line_total', 18, 4)->default(0);
            $table->text('reason')->nullable();
            $table->timestamps();

            $table->index(['receipt_line_id', 'product_id'], 'purchase_return_lines_receipt_product_index');
        });
    }

    private function extendLegacyPurchaseReturns(): void
    {
        Schema::table('purchase_returns', function (Blueprint $table): void {
            $table->foreignId('purchase_order_id')->nullable()->constrained('purchase_orders')->nullOnDelete();
            $table->foreignId('receipt_id')->nullable()->constrained('unpriced_inventory_receipts')->nullOnDelete();
            $table->foreignId('purchase_invoice_id')->nullable()->constrained('purchase_invoices')->nullOnDelete();
            $table->string('reason_code', 50)->nullable();
            $table->decimal('total_quantity', 20, 8)->default(0);
            $table->decimal('total_amount', 18, 4)->default(0);
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();

            $table->index(['company_id', 'status'], 'purchase_returns_context_status_index');
        });

        Schema::table('purchase_return_lines', function (Blueprint $table): void {
            $table->foreignId('purchase_order_line_id')->nullable()->constrained('purchase_order_lines')->nullOnDelete();
            $table->foreignId('receipt_line_id')->nullable()->constrained('unpriced_inventory_receipt_lines')->nullOnDelete();
            $table->foreignId('purchase_invoice_line_id')->nullable()->constrained('purchase_invoice_lines')->nullOnDelete();
            $table->boolean('from_quarantine')->default(false);
            $table->decimal('tax_amount', 18, 4)->default(0);
            $table->text('reason')->nullable();

            $table->index(['receipt_line_id', 'product_id'], 'purchase_return_lines_receipt_product_index');
        });
    }

    private function dropOwnedPurchaseReturnSchema(): void
    {
        if (! Schema::hasColumn('purchase_return_lines', 'company_id')) {
            Schema::dropIfExists('purchase_return_lines');
            Schema::dropIfExists('purchase_returns');

            return;
        }

        Schema::table('purchase_return_lines', function (Blueprint $table): void {
            $table->dropIndex('purchase_return_lines_receipt_product_index');
            $table->dropConstrainedForeignId('purchase_invoice_line_id');
            $table->dropConstrainedForeignId('receipt_line_id');
            $table->dropConstrainedForeignId('purchase_order_line_id');
            $table->dropColumn(['from_quarantine', 'tax_amount', 'reason']);
        });

        Schema::table('purchase_returns', function (Blueprint $table): void {
            $table->dropIndex('purchase_returns_context_status_index');
            $table->dropConstrainedForeignId('posted_by');
            $table->dropConstrainedForeignId('purchase_invoice_id');
            $table->dropConstrainedForeignId('receipt_id');
            $table->dropConstrainedForeignId('purchase_order_id');
            $table->dropColumn(['reason_code', 'total_quantity', 'total_amount', 'posted_at']);
        });
    }

    private function createActiveUniqueIndexes(): void
    {
        if (! in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            return;
        }

        DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS purchase_invoices_supplier_reference_unique_active ON purchase_invoices (company_id, supplier_id, supplier_invoice_number) WHERE deleted_at IS NULL AND supplier_invoice_number IS NOT NULL AND supplier_invoice_number <> ''");
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS purchase_order_change_requests_doc_num_unique_active ON purchase_order_change_requests (company_id, doc_num) WHERE deleted_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS purchase_returns_doc_number_unique_active ON purchase_returns (company_id, financial_period_id, doc_number) WHERE deleted_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS purchase_returns_doc_num_unique_active ON purchase_returns (company_id, doc_num) WHERE deleted_at IS NULL');
    }

    private function backfillLegacyCashVoucherAllocations(): void
    {
        $legacySchedules = DB::table('purchase_invoice_payment_schedules as schedules')
            ->join('purchase_invoices as invoices', 'invoices.id', '=', 'schedules.purchase_invoice_id')
            ->join('cash_vouchers as vouchers', 'vouchers.id', '=', 'schedules.cash_voucher_id')
            ->whereNotNull('schedules.cash_voucher_id')
            ->whereNull('schedules.deleted_at')
            ->select([
                'schedules.id as schedule_id',
                'schedules.purchase_invoice_id',
                'schedules.amount as schedule_amount',
                'invoices.company_id',
                'invoices.financial_period_id',
                'invoices.branch_id',
                'invoices.supplier_id',
                'vouchers.id as voucher_id',
                'vouchers.amount as voucher_amount',
            ])
            ->orderBy('schedules.id')
            ->get();

        foreach ($legacySchedules->groupBy('voucher_id') as $voucherSchedules) {
            $first = $voucherSchedules->first();
            $paymentContextId = DB::table('supplier_payment_contexts')->insertGetId([
                'cash_voucher_id' => $first->voucher_id,
                'company_id' => $first->company_id,
                'financial_period_id' => $first->financial_period_id,
                'branch_id' => $first->branch_id,
                'supplier_id' => $first->supplier_id,
                'purchase_order_id' => null,
                'is_advance' => false,
                'allocated_amount' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $remainingVoucherAmount = (float) $first->voucher_amount;
            $allocatedAmount = 0.0;

            foreach ($voucherSchedules as $schedule) {
                $amount = min((float) $schedule->schedule_amount, $remainingVoucherAmount);
                if ($amount <= 0) {
                    continue;
                }

                DB::table('supplier_payment_allocations')->insert([
                    'public_id' => (string) Str::uuid(),
                    'supplier_payment_context_id' => $paymentContextId,
                    'purchase_invoice_id' => $schedule->purchase_invoice_id,
                    'payment_schedule_id' => $schedule->schedule_id,
                    'amount' => $amount,
                    'allocated_by' => null,
                    'allocated_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                DB::table('purchase_invoice_payment_schedules')->where('id', $schedule->schedule_id)->update(['paid_amount' => $amount]);
                $remainingVoucherAmount -= $amount;
                $allocatedAmount += $amount;
            }

            DB::table('supplier_payment_contexts')->where('id', $paymentContextId)->update([
                'allocated_amount' => $allocatedAmount,
                'updated_at' => now(),
            ]);
        }
    }
};
