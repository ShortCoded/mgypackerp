<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_requisitions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('doc_number');
            $table->string('doc_num', 100);
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('financial_period_id')->constrained('financial_periods')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('branch_store_id')->nullable()->constrained('branch_stores')->nullOnDelete();
            $table->date('request_date');
            $table->date('required_by_date')->nullable();
            $table->string('department', 120)->nullable();
            $table->string('priority', 30)->default('normal');
            $table->string('status', 30)->default('draft');
            $table->text('notes')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'financial_period_id', 'status'], 'purchase_requisitions_context_status_index');
            $table->index(['company_id', 'required_by_date', 'status'], 'purchase_requisitions_due_status_index');
        });

        Schema::create('purchase_requisition_lines', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('purchase_requisition_id')->constrained('purchase_requisitions')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('financial_period_id')->constrained('financial_periods')->restrictOnDelete();
            $table->unsignedInteger('line_number');
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained('item_units')->nullOnDelete();
            $table->decimal('requested_quantity', 20, 8);
            $table->decimal('approved_quantity', 20, 8)->default(0);
            $table->date('required_date')->nullable();
            $table->string('source_type', 50)->default('manual');
            $table->string('source_doc_num', 100)->nullable();
            $table->string('source_line_reference', 100)->nullable();
            $table->text('specification')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['purchase_requisition_id', 'line_number'], 'purchase_requisition_lines_document_line_unique');
            $table->index(['company_id', 'source_type', 'source_doc_num'], 'purchase_requisition_lines_source_index');
        });

        Schema::create('request_for_quotations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('doc_number');
            $table->string('doc_num', 100);
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('financial_period_id')->constrained('financial_periods')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('purchase_requisition_id')->constrained('purchase_requisitions')->restrictOnDelete();
            $table->date('issue_date');
            $table->date('quotation_due_date')->nullable();
            $table->date('required_delivery_date')->nullable();
            $table->string('status', 30)->default('draft');
            $table->text('commercial_notes')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('issued_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'financial_period_id', 'status'], 'request_for_quotations_context_status_index');
        });

        Schema::create('request_for_quotation_suppliers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('request_for_quotation_id')->constrained('request_for_quotations')->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->string('status', 30)->default('invited');
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['request_for_quotation_id', 'supplier_id'], 'request_for_quotation_supplier_unique');
        });

        Schema::create('request_for_quotation_lines', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('request_for_quotation_id')->constrained('request_for_quotations')->cascadeOnDelete();
            $table->foreignId('purchase_requisition_line_id')->constrained('purchase_requisition_lines')->restrictOnDelete();
            $table->unsignedInteger('line_number');
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained('item_units')->nullOnDelete();
            $table->decimal('quantity', 20, 8);
            $table->text('specification')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['request_for_quotation_id', 'line_number'], 'request_for_quotation_lines_document_line_unique');
        });

        Schema::create('supplier_quotations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('doc_number');
            $table->string('doc_num', 100);
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('financial_period_id')->constrained('financial_periods')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('request_for_quotation_id')->constrained('request_for_quotations')->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->foreignId('currency_id')->nullable()->constrained('currencies')->nullOnDelete();
            $table->decimal('exchange_rate', 18, 6)->default(1);
            $table->string('supplier_reference', 120)->nullable();
            $table->date('quotation_date');
            $table->date('valid_until')->nullable();
            $table->unsignedInteger('lead_time_days')->nullable();
            $table->string('payment_terms', 255)->nullable();
            $table->decimal('freight_amount', 18, 4)->default(0);
            $table->decimal('subtotal_amount', 18, 4)->default(0);
            $table->decimal('discount_amount', 18, 4)->default(0);
            $table->decimal('tax_amount', 18, 4)->default(0);
            $table->decimal('total_amount', 18, 4)->default(0);
            $table->string('status', 30)->default('draft');
            $table->text('commercial_notes')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'request_for_quotation_id', 'status'], 'supplier_quotations_context_rfq_status_index');
        });

        Schema::create('supplier_quotation_lines', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('supplier_quotation_id')->constrained('supplier_quotations')->cascadeOnDelete();
            $table->foreignId('request_for_quotation_line_id')->constrained('request_for_quotation_lines')->restrictOnDelete();
            $table->unsignedInteger('line_number');
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained('item_units')->nullOnDelete();
            $table->decimal('offered_quantity', 20, 8);
            $table->decimal('unit_price', 18, 4);
            $table->decimal('discount_amount', 18, 4)->default(0);
            $table->decimal('tax_rate', 8, 4)->default(0);
            $table->decimal('tax_amount', 18, 4)->default(0);
            $table->decimal('line_total', 18, 4);
            $table->date('delivery_date')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['supplier_quotation_id', 'request_for_quotation_line_id'], 'supplier_quotation_lines_quote_rfq_line_unique');
        });

        Schema::create('supplier_selections', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('doc_number');
            $table->string('doc_num', 100);
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('financial_period_id')->constrained('financial_periods')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('request_for_quotation_id')->constrained('request_for_quotations')->restrictOnDelete();
            $table->date('selection_date');
            $table->string('status', 30)->default('draft');
            $table->text('selection_reason')->nullable();
            $table->foreignId('selected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'request_for_quotation_id', 'status'], 'supplier_selections_context_rfq_status_index');
        });

        Schema::create('supplier_selection_lines', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('supplier_selection_id')->constrained('supplier_selections')->cascadeOnDelete();
            $table->foreignId('supplier_quotation_line_id')->constrained('supplier_quotation_lines')->restrictOnDelete();
            $table->foreignId('purchase_requisition_line_id')->constrained('purchase_requisition_lines')->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained('item_units')->nullOnDelete();
            $table->decimal('selected_quantity', 20, 8);
            $table->decimal('unit_price', 18, 4);
            $table->decimal('discount_amount', 18, 4)->default(0);
            $table->decimal('tax_rate', 8, 4)->default(0);
            $table->decimal('tax_amount', 18, 4)->default(0);
            $table->decimal('line_total', 18, 4);
            $table->foreignId('purchase_order_id')->nullable()->constrained('purchase_orders')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['purchase_requisition_line_id', 'supplier_id'], 'supplier_selection_lines_requisition_supplier_index');
        });

        $this->extendPurchaseOrders();
        $this->createDocumentNumberIndexes();
    }

    public function down(): void
    {
        $hasLegacyPurchaseOrderLines = Schema::hasColumn('purchase_order_lines', 'work_order_id');
        $hasLegacyPurchaseOrders = Schema::hasColumn('purchase_orders', 'work_order_id');

        Schema::table('purchase_order_lines', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('supplier_selection_line_id');
            $table->dropConstrainedForeignId('supplier_quotation_line_id');
            $table->dropConstrainedForeignId('request_for_quotation_line_id');
            $table->dropConstrainedForeignId('purchase_requisition_line_id');
            $table->dropColumn([
                'subtotal_amount', 'total_before_tax', 'total_after_tax', 'required_delivery_date', 'specification',
            ]);
        });

        if (! $hasLegacyPurchaseOrderLines) {
            Schema::table('purchase_order_lines', function (Blueprint $table): void {
                $table->dropColumn(['description', 'discount_type', 'discount_value', 'discount_amount', 'tax_rate', 'tax_amount']);
            });
        }

        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('supplier_selection_id');
            $table->dropConstrainedForeignId('supplier_quotation_id');
            $table->dropConstrainedForeignId('request_for_quotation_id');
            $table->dropConstrainedForeignId('purchase_requisition_id');
            $table->dropColumn([
                'freight_amount', 'direct_procurement_override', 'direct_procurement_reason',
            ]);
        });

        if (! $hasLegacyPurchaseOrders) {
            Schema::table('purchase_orders', function (Blueprint $table): void {
                $table->dropColumn(['purchase_type', 'payment_terms', 'internal_reference']);
            });
        }

        Schema::dropIfExists('supplier_selection_lines');
        Schema::dropIfExists('supplier_selections');
        Schema::dropIfExists('supplier_quotation_lines');
        Schema::dropIfExists('supplier_quotations');
        Schema::dropIfExists('request_for_quotation_lines');
        Schema::dropIfExists('request_for_quotation_suppliers');
        Schema::dropIfExists('request_for_quotations');
        Schema::dropIfExists('purchase_requisition_lines');
        Schema::dropIfExists('purchase_requisitions');
    }

    private function extendPurchaseOrders(): void
    {
        $missingLegacyPurchaseOrderColumns = [
            'purchase_type' => ! Schema::hasColumn('purchase_orders', 'purchase_type'),
            'payment_terms' => ! Schema::hasColumn('purchase_orders', 'payment_terms'),
            'internal_reference' => ! Schema::hasColumn('purchase_orders', 'internal_reference'),
        ];

        Schema::table('purchase_orders', function (Blueprint $table) use ($missingLegacyPurchaseOrderColumns): void {
            $table->foreignId('purchase_requisition_id')->nullable()->constrained('purchase_requisitions')->nullOnDelete();
            $table->foreignId('request_for_quotation_id')->nullable()->constrained('request_for_quotations')->nullOnDelete();
            $table->foreignId('supplier_quotation_id')->nullable()->constrained('supplier_quotations')->nullOnDelete();
            $table->foreignId('supplier_selection_id')->nullable()->constrained('supplier_selections')->nullOnDelete();

            // These columns are owned by the legacy 2026_07_18 purchase-cycle migration,
            // which is recorded in upgraded databases but absent from the clean migration chain.
            if ($missingLegacyPurchaseOrderColumns['purchase_type']) {
                $table->string('purchase_type', 30)->default('stock');
            }

            if ($missingLegacyPurchaseOrderColumns['payment_terms']) {
                $table->text('payment_terms')->nullable();
            }

            if ($missingLegacyPurchaseOrderColumns['internal_reference']) {
                $table->string('internal_reference', 150)->nullable();
            }

            $table->decimal('freight_amount', 18, 4)->default(0);
            $table->boolean('direct_procurement_override')->default(false);
            $table->text('direct_procurement_reason')->nullable();
        });

        $missingLegacyPurchaseOrderLineColumns = [
            'description' => ! Schema::hasColumn('purchase_order_lines', 'description'),
            'discount_type' => ! Schema::hasColumn('purchase_order_lines', 'discount_type'),
            'discount_value' => ! Schema::hasColumn('purchase_order_lines', 'discount_value'),
            'discount_amount' => ! Schema::hasColumn('purchase_order_lines', 'discount_amount'),
            'tax_rate' => ! Schema::hasColumn('purchase_order_lines', 'tax_rate'),
            'tax_amount' => ! Schema::hasColumn('purchase_order_lines', 'tax_amount'),
        ];

        Schema::table('purchase_order_lines', function (Blueprint $table) use ($missingLegacyPurchaseOrderLineColumns): void {
            $table->foreignId('purchase_requisition_line_id')->nullable()->constrained('purchase_requisition_lines')->nullOnDelete();
            $table->foreignId('request_for_quotation_line_id')->nullable()->constrained('request_for_quotation_lines')->nullOnDelete();
            $table->foreignId('supplier_quotation_line_id')->nullable()->constrained('supplier_quotation_lines')->nullOnDelete();
            $table->foreignId('supplier_selection_line_id')->nullable()->constrained('supplier_selection_lines')->nullOnDelete();

            if ($missingLegacyPurchaseOrderLineColumns['description']) {
                $table->string('description')->nullable();
            }

            if ($missingLegacyPurchaseOrderLineColumns['discount_type']) {
                $table->string('discount_type', 20)->nullable();
            }

            if ($missingLegacyPurchaseOrderLineColumns['discount_value']) {
                $table->decimal('discount_value', 20, 4)->default(0);
            }

            if ($missingLegacyPurchaseOrderLineColumns['discount_amount']) {
                $table->decimal('discount_amount', 20, 4)->default(0);
            }

            if ($missingLegacyPurchaseOrderLineColumns['tax_rate']) {
                $table->decimal('tax_rate', 12, 4)->default(0);
            }

            if ($missingLegacyPurchaseOrderLineColumns['tax_amount']) {
                $table->decimal('tax_amount', 20, 4)->default(0);
            }

            $table->decimal('subtotal_amount', 18, 4)->default(0);
            $table->decimal('total_before_tax', 18, 4)->default(0);
            $table->decimal('total_after_tax', 18, 4)->default(0);
            $table->date('required_delivery_date')->nullable();
            $table->text('specification')->nullable();
        });
    }

    private function createDocumentNumberIndexes(): void
    {
        if (! in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            return;
        }

        foreach (['purchase_requisitions', 'request_for_quotations', 'supplier_quotations', 'supplier_selections'] as $table) {
            DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS {$table}_doc_number_unique_active ON {$table} (company_id, financial_period_id, doc_number) WHERE deleted_at IS NULL");
            DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS {$table}_doc_num_unique_active ON {$table} (company_id, doc_num) WHERE deleted_at IS NULL");
        }
    }
};
