<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->ensureCanonicalFoundationTables();
        $this->extendSalesOrders();
        $this->extendInventoryDocuments();
        $this->extendCustomerInvoices();
        $this->extendCustomerReceipts();

        Schema::create('customer_commercial_agreements', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('currency_id')->nullable()->constrained('currencies')->restrictOnDelete();
            $table->string('customer_type', 20)->default('credit')->index();
            $table->decimal('credit_limit', 20, 4)->default(0);
            $table->boolean('include_open_orders')->default(true);
            $table->decimal('required_advance_percentage', 12, 4)->default(0);
            $table->decimal('required_advance_minimum', 20, 4)->default(0);
            $table->unsignedInteger('payment_terms_days')->default(0);
            $table->boolean('blocking_enabled')->default(true);
            $table->boolean('temporary_override_allowed')->default(true);
            $table->date('effective_from')->nullable()->index();
            $table->date('effective_to')->nullable()->index();
            $table->string('status', 20)->default('active')->index();
            $table->json('installment_terms')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes()->index();

            $table->index(['company_id', 'customer_id', 'status'], 'customer_commercial_agreements_context_index');
        });

        Schema::create('sales_order_credit_overrides', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('sales_order_id')->constrained('sales_orders')->cascadeOnDelete();
            $table->json('blocking_condition');
            $table->text('reason');
            $table->string('resulting_action', 40)->default('released');
            $table->foreignId('overridden_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('overridden_at');
            $table->timestamps();

            $table->index(['sales_order_id', 'overridden_at']);
        });

        Schema::create('inventory_reservations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('financial_period_id')->constrained('financial_periods')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('branch_store_id')->constrained('branch_stores')->restrictOnDelete();
            $table->foreignId('sales_order_id')->constrained('sales_orders')->cascadeOnDelete();
            $table->foreignId('sales_order_line_id')->constrained('sales_order_lines')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained('item_units')->restrictOnDelete();
            $table->decimal('quantity', 20, 8);
            $table->decimal('consumed_quantity', 20, 8)->default(0);
            $table->decimal('released_quantity', 20, 8)->default(0);
            $table->string('status', 20)->default('active')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('released_at')->nullable();
            $table->text('release_reason')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'branch_store_id', 'product_id', 'status'], 'inventory_reservations_availability_index');
            $table->index(['sales_order_line_id', 'status']);
        });

        Schema::create('customer_invoice_payment_schedules', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('customer_invoice_id')->constrained('customer_invoices')->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->date('due_date')->index();
            $table->decimal('amount', 20, 4);
            $table->decimal('collected_amount', 20, 4)->default(0);
            $table->decimal('credited_amount', 20, 4)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['customer_invoice_id', 'sequence'], 'customer_invoice_schedule_sequence_unique');
        });

        Schema::create('sales_returns', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('doc_number');
            $table->string('doc_num', 100);
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('financial_period_id')->constrained('financial_periods')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('branch_store_id')->nullable()->constrained('branch_stores')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('sales_order_id')->nullable()->constrained('sales_orders')->restrictOnDelete();
            $table->foreignId('customer_invoice_id')->constrained('customer_invoices')->restrictOnDelete();
            $table->foreignId('delivery_document_id')->nullable()->constrained('inventory_documents')->restrictOnDelete();
            $table->foreignId('return_inventory_document_id')->nullable()->constrained('inventory_documents')->nullOnDelete();
            $table->date('return_date')->index();
            $table->string('reason_code', 60)->index();
            $table->text('reason_details')->nullable();
            $table->string('status', 40)->default('pending_authorization')->index();
            $table->decimal('subtotal_amount', 20, 4)->default(0);
            $table->decimal('tax_amount', 20, 4)->default(0);
            $table->decimal('total_amount', 20, 4)->default(0);
            $table->foreignId('credit_note_id')->nullable()->constrained('customer_invoices')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('authorized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('authorized_at')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('received_at')->nullable();
            $table->foreignId('inspected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('inspected_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->timestamps();
            $table->softDeletes()->index();

            $table->unique(['company_id', 'doc_num']);
            $table->unique(['company_id', 'financial_period_id', 'doc_number'], 'sales_returns_context_number_unique');
            $table->index(['customer_invoice_id', 'status']);
        });

        Schema::create('sales_return_lines', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('sales_return_id')->constrained('sales_returns')->cascadeOnDelete();
            $table->unsignedInteger('line_number');
            $table->foreignId('customer_invoice_line_id')->constrained('customer_invoice_lines')->restrictOnDelete();
            $table->foreignId('delivery_line_id')->nullable()->constrained('inventory_document_lines')->restrictOnDelete();
            $table->foreignId('sales_order_line_id')->nullable()->constrained('sales_order_lines')->restrictOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->restrictOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained('item_units')->restrictOnDelete();
            $table->decimal('quantity', 20, 8);
            $table->decimal('unit_price', 20, 4)->default(0);
            $table->decimal('tax_amount', 20, 4)->default(0);
            $table->decimal('line_total', 20, 4)->default(0);
            $table->boolean('is_service')->default(false);
            $table->string('quality_disposition', 30)->nullable()->index();
            $table->decimal('saleable_quantity', 20, 8)->default(0);
            $table->decimal('quarantine_quantity', 20, 8)->default(0);
            $table->decimal('rework_quantity', 20, 8)->default(0);
            $table->decimal('scrap_quantity', 20, 8)->default(0);
            $table->decimal('original_unit_cost', 20, 8)->default(0);
            $table->text('inspection_notes')->nullable();
            $table->json('source_snapshot')->nullable();
            $table->timestamps();

            $table->unique(['sales_return_id', 'line_number']);
        });

        Schema::create('sales_return_status_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sales_return_id')->constrained('sales_returns')->cascadeOnDelete();
            $table->string('from_status', 40)->nullable();
            $table->string('to_status', 40);
            $table->text('reason')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('changed_at');
            $table->timestamps();
        });

        Schema::table('customer_invoices', function (Blueprint $table): void {
            if (! Schema::hasColumn('customer_invoices', 'sales_return_id')) {
                $table->foreignId('sales_return_id')->nullable()->constrained('sales_returns')->nullOnDelete();
            }
        });

        Schema::table('customer_receipt_allocations', function (Blueprint $table): void {
            if (! Schema::hasColumn('customer_receipt_allocations', 'customer_invoice_payment_schedule_id')) {
                $table->foreignId('customer_invoice_payment_schedule_id')
                    ->nullable()
                    ->constrained('customer_invoice_payment_schedules')
                    ->restrictOnDelete();
            }
        });
    }

    private function ensureCanonicalFoundationTables(): void
    {
        if (! Schema::hasTable('sales_orders')) {
            Schema::create('sales_orders', function (Blueprint $table): void {
                $table->id();
                $table->unsignedInteger('doc_number');
                $table->string('doc_num');
                $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
                $table->foreignId('financial_period_id')->constrained('financial_periods')->restrictOnDelete();
                $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
                $table->foreignId('quotation_id')->nullable()->constrained('quotations')->restrictOnDelete();
                $table->foreignId('quotation_revision_id')->nullable()->constrained('quotation_revisions')->restrictOnDelete();
                $table->unsignedBigInteger('sales_project_id')->nullable();
                $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
                $table->foreignId('sales_employee_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
                $table->date('order_date');
                $table->date('expected_delivery_date');
                $table->unsignedInteger('delivery_days_estimate')->nullable();
                $table->string('sales_channel')->default('projects');
                $table->text('delivery_address')->nullable();
                $table->string('delivery_contact_name')->nullable();
                $table->string('delivery_contact_phone')->nullable();
                $table->decimal('exchange_rate', 20, 6)->default(1);
                $table->decimal('subtotal_amount', 20, 4)->default(0);
                $table->decimal('discount_amount', 20, 4)->default(0);
                $table->decimal('tax_amount', 20, 4)->default(0);
                $table->decimal('total_amount', 20, 4)->default(0);
                $table->text('terms_snapshot')->nullable();
                $table->text('payment_terms_snapshot')->nullable();
                $table->text('execution_terms_snapshot')->nullable();
                $table->text('warranty_terms_snapshot')->nullable();
                $table->text('technical_notes_snapshot')->nullable();
                $table->text('delivery_terms_snapshot')->nullable();
                $table->boolean('payment_schedule_bypassed')->default(false);
                $table->text('payment_schedule_bypass_reason')->nullable();
                $table->string('status')->default('draft')->index();
                $table->text('notes')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('confirmed_at')->nullable();
                $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('cancelled_at')->nullable();
                $table->text('cancel_reason')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->unique(['company_id', 'doc_num']);
                $table->unique(['company_id', 'financial_period_id', 'doc_number'], 'sales_orders_context_number_unique');
            });
        }

        if (! Schema::hasTable('sales_order_lines')) {
            Schema::create('sales_order_lines', function (Blueprint $table): void {
                $table->id();
                $table->uuid('public_id')->unique();
                $table->foreignId('sales_order_id')->constrained('sales_orders')->cascadeOnDelete();
                $table->foreignId('quotation_revision_line_id')->nullable()->constrained('quotation_revision_lines')->restrictOnDelete();
                $table->unsignedInteger('line_number');
                $table->foreignId('product_id')->nullable()->constrained('products')->restrictOnDelete();
                $table->foreignId('unit_id')->nullable()->constrained('item_units')->restrictOnDelete();
                $table->text('description');
                $table->decimal('quantity', 20, 8);
                $table->decimal('unit_price', 20, 4)->default(0);
                $table->decimal('discount_amount', 20, 4)->default(0);
                $table->decimal('tax_amount', 20, 4)->default(0);
                $table->decimal('line_total', 20, 4)->default(0);
                $table->json('specifications')->nullable();
                $table->text('customer_notes')->nullable();
                $table->text('production_notes')->nullable();
                $table->timestamps();
                $table->unique(['sales_order_id', 'line_number']);
            });
        }

        if (! Schema::hasTable('sales_order_payment_schedules')) {
            Schema::create('sales_order_payment_schedules', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('sales_order_id')->constrained('sales_orders')->cascadeOnDelete();
                $table->unsignedInteger('line_number');
                $table->string('installment_type')->default('custom');
                $table->string('title');
                $table->text('description')->nullable();
                $table->decimal('percentage', 12, 4)->default(0);
                $table->decimal('amount', 20, 4);
                $table->date('due_date')->nullable();
                $table->string('due_condition')->default('custom');
                $table->string('status')->default('pending');
                $table->decimal('collected_amount', 20, 4)->default(0);
                $table->decimal('remaining_amount', 20, 4)->default(0);
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->unique(['sales_order_id', 'line_number']);
            });
        }

        if (! Schema::hasTable('sales_order_status_histories')) {
            Schema::create('sales_order_status_histories', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('sales_order_id')->constrained('sales_orders')->cascadeOnDelete();
                $table->string('from_status')->nullable();
                $table->string('to_status');
                $table->text('reason')->nullable();
                $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('changed_at');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('production_orders')) {
            Schema::create('production_orders', function (Blueprint $table): void {
                $table->id();
                $table->unsignedInteger('doc_number');
                $table->string('doc_num');
                $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
                $table->foreignId('financial_period_id')->constrained('financial_periods')->restrictOnDelete();
                $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
                $table->foreignId('sales_order_id')->constrained('sales_orders')->restrictOnDelete();
                $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
                $table->foreignId('technical_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->date('production_order_date');
                $table->date('expected_start_date')->nullable();
                $table->date('expected_finish_date')->nullable();
                $table->date('expected_delivery_date');
                $table->string('status')->default('draft')->index();
                $table->text('technical_notes')->nullable();
                $table->text('production_notes')->nullable();
                $table->boolean('drawing_approval_overridden')->default(false);
                $table->text('drawing_override_reason')->nullable();
                $table->foreignId('drawing_override_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('drawing_override_at')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('technical_approved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('technical_approved_at')->nullable();
                $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('released_at')->nullable();
                $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('cancelled_at')->nullable();
                $table->text('cancel_reason')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->unique(['company_id', 'doc_num']);
                $table->unique(['company_id', 'financial_period_id', 'doc_number'], 'production_orders_context_number_unique');
            });
        }

        if (! Schema::hasTable('production_order_lines')) {
            Schema::create('production_order_lines', function (Blueprint $table): void {
                $table->id();
                $table->uuid('public_id')->unique();
                $table->foreignId('production_order_id')->constrained('production_orders')->cascadeOnDelete();
                $table->foreignId('sales_order_line_id')->constrained('sales_order_lines')->restrictOnDelete();
                $table->unsignedInteger('line_number');
                $table->foreignId('product_id')->nullable()->constrained('products')->restrictOnDelete();
                $table->foreignId('unit_id')->nullable()->constrained('item_units')->restrictOnDelete();
                $table->text('description');
                $table->decimal('quantity', 20, 8);
                $table->json('specifications')->nullable();
                $table->text('production_notes')->nullable();
                $table->boolean('mandatory_specs_resolved')->default(false);
                $table->text('spec_resolution_notes')->nullable();
                $table->timestamps();
                $table->unique(['production_order_id', 'line_number']);
            });
        }

        if (! Schema::hasTable('inventory_documents')) {
            Schema::create('inventory_documents', function (Blueprint $table): void {
                $table->id();
                $table->unsignedInteger('doc_number');
                $table->string('doc_num');
                $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
                $table->foreignId('financial_period_id')->constrained('financial_periods')->restrictOnDelete();
                $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
                $table->foreignId('branch_store_id')->constrained('branch_stores')->restrictOnDelete();
                $table->unsignedBigInteger('branch_hall_id')->nullable();
                $table->string('document_type')->index();
                $table->date('document_date');
                $table->string('purpose')->nullable();
                $table->string('source_document_type')->nullable();
                $table->unsignedBigInteger('source_document_id')->nullable();
                $table->string('source_doc_num')->nullable();
                $table->unsignedBigInteger('responsible_employee_id')->nullable();
                $table->string('responsible_name')->nullable();
                $table->string('status')->default('draft')->index();
                $table->text('notes')->nullable();
                foreach (['created_by', 'updated_by', 'approved_by', 'closed_by', 'cancelled_by', 'reversed_by', 'deleted_by', 'restored_by'] as $column) {
                    $table->foreignId($column)->nullable()->constrained('users')->nullOnDelete();
                }
                $table->timestamp('approved_at')->nullable();
                $table->timestamp('closed_at')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->text('cancel_reason')->nullable();
                $table->timestamp('reversed_at')->nullable();
                $table->timestamp('restored_at')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->unsignedBigInteger('production_material_request_id')->nullable();
                $table->foreignId('production_order_id')->nullable()->constrained('production_orders')->nullOnDelete();
                $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
                $table->foreignId('reversal_journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
                $table->unique(['company_id', 'doc_num']);
                $table->unique(['company_id', 'financial_period_id', 'doc_number'], 'inventory_documents_context_number_unique');
            });
        }

        if (! Schema::hasTable('inventory_document_lines')) {
            Schema::create('inventory_document_lines', function (Blueprint $table): void {
                $table->id();
                $table->uuid('public_id')->unique();
                $table->foreignId('inventory_document_id')->constrained('inventory_documents')->cascadeOnDelete();
                $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
                $table->foreignId('financial_period_id')->constrained('financial_periods')->restrictOnDelete();
                $table->unsignedInteger('line_number');
                $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
                $table->foreignId('unit_id')->nullable()->constrained('item_units')->restrictOnDelete();
                $table->string('source_line_type')->nullable();
                $table->unsignedBigInteger('source_line_id')->nullable();
                $table->uuid('source_line_public_id')->nullable();
                $table->decimal('reference_quantity', 20, 8)->default(0);
                $table->decimal('previous_quantity', 20, 8)->default(0);
                $table->decimal('quantity', 20, 8);
                $table->decimal('rejected_quantity', 20, 8)->default(0);
                $table->json('product_snapshot')->nullable();
                $table->text('notes')->nullable();
                foreach (['created_by', 'updated_by', 'deleted_by'] as $column) {
                    $table->foreignId($column)->nullable()->constrained('users')->nullOnDelete();
                }
                $table->timestamps();
                $table->softDeletes();
                $table->unsignedBigInteger('production_material_request_line_id')->nullable();
                $table->foreignId('production_order_id')->nullable()->constrained('production_orders')->nullOnDelete();
                $table->decimal('unit_cost', 20, 8)->nullable();
                $table->decimal('total_cost', 20, 8)->nullable();
                $table->unique(['inventory_document_id', 'line_number']);
            });
        }

        if (! Schema::hasTable('inventory_transactions')) {
            Schema::create('inventory_transactions', function (Blueprint $table): void {
                $table->id();
                $table->string('posting_key')->unique();
                $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
                $table->foreignId('financial_period_id')->constrained('financial_periods')->restrictOnDelete();
                $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
                $table->foreignId('branch_store_id')->constrained('branch_stores')->restrictOnDelete();
                $table->unsignedBigInteger('branch_hall_id')->nullable();
                $table->date('transaction_date');
                $table->string('transaction_type')->index();
                $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
                $table->foreignId('unit_id')->nullable()->constrained('item_units')->restrictOnDelete();
                $table->decimal('quantity_in', 20, 8)->default(0);
                $table->decimal('quantity_out', 20, 8)->default(0);
                $table->string('source_type');
                $table->unsignedBigInteger('source_id');
                $table->string('source_doc_num');
                $table->string('source_line_type')->nullable();
                $table->unsignedBigInteger('source_line_id')->nullable();
                $table->unsignedBigInteger('supplier_id')->nullable();
                $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
                $table->boolean('is_reversal')->default(false);
                $table->foreignId('reversal_of_id')->nullable()->constrained('inventory_transactions')->nullOnDelete();
                $table->text('notes')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->foreignId('production_order_id')->nullable()->constrained('production_orders')->nullOnDelete();
                $table->decimal('unit_cost', 20, 8)->nullable();
                $table->decimal('total_cost', 20, 8)->nullable();
                $table->index(['company_id', 'branch_store_id', 'product_id', 'transaction_date'], 'inventory_transactions_stock_index');
            });
        }

        if (! Schema::hasTable('customer_invoices')) {
            Schema::create('customer_invoices', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('doc_number');
                $table->string('doc_num');
                $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
                $table->foreignId('financial_period_id')->constrained('financial_periods')->restrictOnDelete();
                $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
                $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
                $table->foreignId('sales_order_id')->constrained('sales_orders')->restrictOnDelete();
                $table->foreignId('production_order_id')->nullable()->constrained('production_orders')->restrictOnDelete();
                $table->date('invoice_date');
                $table->date('due_date')->nullable();
                $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
                $table->decimal('exchange_rate', 20, 6)->default(1);
                foreach (['subtotal_amount', 'discount_amount', 'taxable_amount', 'tax_amount', 'total_amount', 'applied_advance_amount', 'paid_amount', 'remaining_amount'] as $column) {
                    $table->decimal($column, 20, 4)->default(0);
                }
                $table->string('status')->default('draft')->index();
                $table->text('notes')->nullable();
                $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
                $table->foreignId('reversal_journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
                foreach (['issued_by', 'cancelled_by', 'created_by', 'updated_by'] as $column) {
                    $table->foreignId($column)->nullable()->constrained('users')->nullOnDelete();
                }
                $table->timestamp('issued_at')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->text('cancel_reason')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->unique(['company_id', 'doc_num']);
                $table->unique(['company_id', 'financial_period_id', 'doc_number'], 'customer_invoices_context_number_unique');
            });
        }

        if (! Schema::hasTable('customer_invoice_lines')) {
            Schema::create('customer_invoice_lines', function (Blueprint $table): void {
                $table->id();
                $table->uuid('public_id')->unique();
                $table->foreignId('customer_invoice_id')->constrained('customer_invoices')->cascadeOnDelete();
                $table->foreignId('sales_order_line_id')->nullable()->constrained('sales_order_lines')->restrictOnDelete();
                $table->foreignId('product_id')->nullable()->constrained('products')->restrictOnDelete();
                $table->foreignId('unit_id')->nullable()->constrained('item_units')->restrictOnDelete();
                $table->unsignedInteger('line_number');
                $table->text('description');
                $table->decimal('quantity', 20, 8);
                $table->decimal('unit_price', 20, 4)->default(0);
                $table->decimal('discount_amount', 20, 4)->default(0);
                $table->decimal('tax_amount', 20, 4)->default(0);
                $table->decimal('line_total', 20, 4)->default(0);
                $table->json('source_snapshot')->nullable();
                $table->timestamps();
                $table->unique(['customer_invoice_id', 'line_number']);
            });
        }

        if (! Schema::hasTable('customer_receipts')) {
            Schema::create('customer_receipts', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('doc_number');
                $table->string('doc_num');
                $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
                $table->foreignId('financial_period_id')->constrained('financial_periods')->restrictOnDelete();
                $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
                $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
                $table->foreignId('sales_order_id')->nullable()->constrained('sales_orders')->restrictOnDelete();
                $table->date('receipt_date');
                $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
                $table->decimal('exchange_rate', 20, 6)->default(1);
                $table->string('payment_method');
                $table->foreignId('cashbox_id')->nullable()->constrained('cashboxes')->restrictOnDelete();
                $table->foreignId('bank_account_id')->nullable()->constrained('bank_accounts')->restrictOnDelete();
                $table->decimal('amount', 20, 4);
                $table->string('receipt_type');
                $table->string('reference_no')->nullable();
                $table->string('status')->default('draft')->index();
                $table->text('notes')->nullable();
                $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
                $table->foreignId('reversal_journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
                foreach (['approved_by', 'cancelled_by', 'created_by', 'updated_by'] as $column) {
                    $table->foreignId($column)->nullable()->constrained('users')->nullOnDelete();
                }
                $table->timestamp('approved_at')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->text('cancel_reason')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->unique(['company_id', 'doc_num']);
                $table->unique(['company_id', 'financial_period_id', 'doc_number'], 'customer_receipts_context_number_unique');
            });
        }

        if (! Schema::hasTable('customer_receipt_allocations')) {
            Schema::create('customer_receipt_allocations', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('customer_receipt_id')->constrained('customer_receipts')->cascadeOnDelete();
                $table->foreignId('customer_invoice_id')->nullable()->constrained('customer_invoices')->restrictOnDelete();
                $table->foreignId('sales_order_payment_schedule_id')->nullable()->constrained('sales_order_payment_schedules')->restrictOnDelete();
                $table->decimal('allocated_amount', 20, 4);
                $table->foreignId('application_journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
                $table->foreignId('applied_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('applied_at')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::table('customer_receipt_allocations', function (Blueprint $table): void {
            if (Schema::hasColumn('customer_receipt_allocations', 'customer_invoice_payment_schedule_id')) {
                $table->dropConstrainedForeignId('customer_invoice_payment_schedule_id');
            }
        });

        Schema::table('customer_invoices', function (Blueprint $table): void {
            if (Schema::hasColumn('customer_invoices', 'sales_return_id')) {
                $table->dropConstrainedForeignId('sales_return_id');
            }
        });

        Schema::dropIfExists('sales_return_status_histories');
        Schema::dropIfExists('sales_return_lines');
        Schema::dropIfExists('sales_returns');
        Schema::dropIfExists('customer_invoice_payment_schedules');
        Schema::dropIfExists('inventory_reservations');
        Schema::dropIfExists('sales_order_credit_overrides');
        Schema::dropIfExists('customer_commercial_agreements');

        $this->removeCustomerReceiptExtensions();
        $this->removeCustomerInvoiceExtensions();
        $this->removeInventoryDocumentExtensions();
        $this->removeSalesOrderExtensions();
    }

    private function extendSalesOrders(): void
    {
        Schema::table('sales_orders', function (Blueprint $table): void {
            if (! Schema::hasColumn('sales_orders', 'branch_store_id')) {
                $table->foreignId('branch_store_id')->nullable()->constrained('branch_stores')->restrictOnDelete();
            }
            if (! Schema::hasColumn('sales_orders', 'customer_reference')) {
                $table->string('customer_reference', 160)->nullable();
            }
            if (! Schema::hasColumn('sales_orders', 'internal_notes')) {
                $table->text('internal_notes')->nullable();
            }
            if (! Schema::hasColumn('sales_orders', 'agreement_snapshot')) {
                $table->json('agreement_snapshot')->nullable();
            }
            if (! Schema::hasColumn('sales_orders', 'credit_limit_snapshot')) {
                $table->decimal('credit_limit_snapshot', 20, 4)->default(0);
            }
            if (! Schema::hasColumn('sales_orders', 'required_advance_amount')) {
                $table->decimal('required_advance_amount', 20, 4)->default(0);
            }
            if (! Schema::hasColumn('sales_orders', 'credit_status')) {
                $table->string('credit_status', 30)->default('pending')->index();
            }
            if (! Schema::hasColumn('sales_orders', 'approved_by')) {
                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('sales_orders', 'approved_at')) {
                $table->timestamp('approved_at')->nullable();
            }
            if (! Schema::hasColumn('sales_orders', 'rejected_by')) {
                $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('sales_orders', 'rejected_at')) {
                $table->timestamp('rejected_at')->nullable();
            }
            if (! Schema::hasColumn('sales_orders', 'rejection_reason')) {
                $table->text('rejection_reason')->nullable();
            }
            if (! Schema::hasColumn('sales_orders', 'reopened_by')) {
                $table->foreignId('reopened_by')->nullable()->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('sales_orders', 'reopened_at')) {
                $table->timestamp('reopened_at')->nullable();
            }
            if (! Schema::hasColumn('sales_orders', 'reopen_reason')) {
                $table->text('reopen_reason')->nullable();
            }
        });

        Schema::table('sales_order_lines', function (Blueprint $table): void {
            foreach ([
                'reserved_quantity',
                'production_requested_quantity',
                'produced_quantity',
                'delivered_quantity',
                'invoiced_quantity',
                'returned_quantity',
            ] as $column) {
                if (! Schema::hasColumn('sales_order_lines', $column)) {
                    $table->decimal($column, 20, 8)->default(0);
                }
            }
            if (! Schema::hasColumn('sales_order_lines', 'requested_date')) {
                $table->date('requested_date')->nullable()->index();
            }
            if (! Schema::hasColumn('sales_order_lines', 'product_classification_snapshot')) {
                $table->string('product_classification_snapshot', 40)->nullable()->index();
            }
            if (! Schema::hasColumn('sales_order_lines', 'warehouse_notes')) {
                $table->text('warehouse_notes')->nullable();
            }
        });
    }

    private function extendInventoryDocuments(): void
    {
        Schema::table('inventory_documents', function (Blueprint $table): void {
            if (! Schema::hasColumn('inventory_documents', 'customer_id')) {
                $table->foreignId('customer_id')->nullable()->constrained('customers')->restrictOnDelete();
            }
            if (! Schema::hasColumn('inventory_documents', 'recipient_name')) {
                $table->string('recipient_name')->nullable();
            }
            if (! Schema::hasColumn('inventory_documents', 'recipient_phone')) {
                $table->string('recipient_phone', 60)->nullable();
            }
            if (! Schema::hasColumn('inventory_documents', 'vehicle_number')) {
                $table->string('vehicle_number', 80)->nullable();
            }
            if (! Schema::hasColumn('inventory_documents', 'driver_name')) {
                $table->string('driver_name')->nullable();
            }
            if (! Schema::hasColumn('inventory_documents', 'is_closed')) {
                $table->boolean('is_closed')->default(false)->index();
            }
        });
    }

    private function extendCustomerInvoices(): void
    {
        Schema::table('customer_invoices', function (Blueprint $table): void {
            if (! Schema::hasColumn('customer_invoices', 'document_type')) {
                $table->string('document_type', 30)->default('invoice')->index();
            }
            if (! Schema::hasColumn('customer_invoices', 'original_invoice_id')) {
                $table->foreignId('original_invoice_id')->nullable()->constrained('customer_invoices')->restrictOnDelete();
            }
            if (! Schema::hasColumn('customer_invoices', 'delivery_document_id')) {
                $table->foreignId('delivery_document_id')->nullable()->constrained('inventory_documents')->restrictOnDelete();
            }
            if (! Schema::hasColumn('customer_invoices', 'payment_terms_snapshot')) {
                $table->text('payment_terms_snapshot')->nullable();
            }
            if (! Schema::hasColumn('customer_invoices', 'credited_amount')) {
                $table->decimal('credited_amount', 20, 4)->default(0);
            }
            if (! Schema::hasColumn('customer_invoices', 'posting_status')) {
                $table->string('posting_status', 30)->default('unposted')->index();
            }
            if (! Schema::hasColumn('customer_invoices', 'is_closed')) {
                $table->boolean('is_closed')->default(false)->index();
            }
            if (! Schema::hasColumn('customer_invoices', 'reopened_by')) {
                $table->foreignId('reopened_by')->nullable()->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('customer_invoices', 'reopened_at')) {
                $table->timestamp('reopened_at')->nullable();
            }
            if (! Schema::hasColumn('customer_invoices', 'reopen_reason')) {
                $table->text('reopen_reason')->nullable();
            }
        });

        Schema::table('customer_invoice_lines', function (Blueprint $table): void {
            if (! Schema::hasColumn('customer_invoice_lines', 'delivery_line_id')) {
                $table->foreignId('delivery_line_id')->nullable()->constrained('inventory_document_lines')->restrictOnDelete();
            }
            if (! Schema::hasColumn('customer_invoice_lines', 'is_service')) {
                $table->boolean('is_service')->default(false)->index();
            }
            if (! Schema::hasColumn('customer_invoice_lines', 'unit_cost')) {
                $table->decimal('unit_cost', 20, 8)->default(0);
            }
        });
    }

    private function extendCustomerReceipts(): void
    {
        Schema::table('customer_receipts', function (Blueprint $table): void {
            if (! Schema::hasColumn('customer_receipts', 'unallocated_amount')) {
                $table->decimal('unallocated_amount', 20, 4)->default(0);
            }
            if (! Schema::hasColumn('customer_receipts', 'is_closed')) {
                $table->boolean('is_closed')->default(false)->index();
            }
            if (! Schema::hasColumn('customer_receipts', 'reopened_by')) {
                $table->foreignId('reopened_by')->nullable()->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('customer_receipts', 'reopened_at')) {
                $table->timestamp('reopened_at')->nullable();
            }
            if (! Schema::hasColumn('customer_receipts', 'reopen_reason')) {
                $table->text('reopen_reason')->nullable();
            }
        });
    }

    private function removeSalesOrderExtensions(): void
    {
        Schema::table('sales_order_lines', function (Blueprint $table): void {
            $table->dropColumn([
                'reserved_quantity',
                'production_requested_quantity',
                'produced_quantity',
                'delivered_quantity',
                'invoiced_quantity',
                'returned_quantity',
                'requested_date',
                'product_classification_snapshot',
                'warehouse_notes',
            ]);
        });

        Schema::table('sales_orders', function (Blueprint $table): void {
            foreach (['branch_store_id', 'approved_by', 'rejected_by', 'reopened_by'] as $column) {
                if (Schema::hasColumn('sales_orders', $column)) {
                    $table->dropConstrainedForeignId($column);
                }
            }
            $table->dropColumn([
                'customer_reference',
                'internal_notes',
                'agreement_snapshot',
                'credit_limit_snapshot',
                'required_advance_amount',
                'credit_status',
                'approved_at',
                'rejected_at',
                'rejection_reason',
                'reopened_at',
                'reopen_reason',
            ]);
        });
    }

    private function removeInventoryDocumentExtensions(): void
    {
        Schema::table('inventory_documents', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('customer_id');
            $table->dropColumn(['recipient_name', 'recipient_phone', 'vehicle_number', 'driver_name', 'is_closed']);
        });
    }

    private function removeCustomerInvoiceExtensions(): void
    {
        Schema::table('customer_invoice_lines', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('delivery_line_id');
            $table->dropColumn(['is_service', 'unit_cost']);
        });
        Schema::table('customer_invoices', function (Blueprint $table): void {
            foreach (['original_invoice_id', 'delivery_document_id', 'reopened_by'] as $column) {
                $table->dropConstrainedForeignId($column);
            }
            $table->dropColumn([
                'document_type',
                'payment_terms_snapshot',
                'credited_amount',
                'posting_status',
                'is_closed',
                'reopened_at',
                'reopen_reason',
            ]);
        });
    }

    private function removeCustomerReceiptExtensions(): void
    {
        Schema::table('customer_receipts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reopened_by');
            $table->dropColumn(['unallocated_amount', 'is_closed', 'reopened_at', 'reopen_reason']);
        });
    }
};
