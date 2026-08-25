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
        Schema::table('customer_invoices', function (Blueprint $table): void {
            $table->foreignId('sales_order_id')->nullable()->change();
            $table->string('source_type', 80)->nullable()->index();
            $table->unsignedBigInteger('source_id')->nullable()->index();
            $table->string('source_doc_num')->nullable();
            $table->decimal('credit_available_amount', 20, 4)->default(0);
            $table->decimal('credit_allocated_amount', 20, 4)->default(0);
            $table->decimal('credit_refunded_amount', 20, 4)->default(0);
        });

        Schema::table('sales_returns', function (Blueprint $table): void {
            $table->foreignId('quarantine_journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('disposition_journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
        });

        Schema::create('customer_credit_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('financial_period_id')->constrained('financial_periods')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->uuid('idempotency_key');
            $table->foreignId('credit_note_id')->constrained('customer_invoices')->restrictOnDelete();
            $table->foreignId('target_invoice_id')->constrained('customer_invoices')->restrictOnDelete();
            $table->foreignId('target_payment_schedule_id')->nullable()->constrained('customer_invoice_payment_schedules')->restrictOnDelete();
            $table->date('allocation_date');
            $table->decimal('amount', 20, 4);
            $table->string('status', 30)->default('applied');
            $table->text('notes')->nullable();
            $table->foreignId('applied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('applied_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'customer_id', 'status'], 'customer_credit_allocations_customer_index');
            $table->index(['credit_note_id', 'status'], 'customer_credit_allocations_credit_index');
            $table->index(['target_invoice_id', 'status'], 'customer_credit_allocations_target_index');
            $table->unique(['company_id', 'idempotency_key'], 'customer_credit_allocations_idempotency_unique');
        });

        Schema::create('customer_credit_refunds', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('doc_number');
            $table->string('doc_num');
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('financial_period_id')->constrained('financial_periods')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('credit_note_id')->constrained('customer_invoices')->restrictOnDelete();
            $table->uuid('idempotency_key');
            $table->date('refund_date');
            $table->string('payment_method', 20);
            $table->foreignId('cashbox_id')->nullable()->constrained('cashboxes')->restrictOnDelete();
            $table->foreignId('bank_account_id')->nullable()->constrained('bank_accounts')->restrictOnDelete();
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->decimal('exchange_rate', 20, 6)->default(1);
            $table->decimal('amount', 20, 4);
            $table->string('reference_no')->nullable();
            $table->string('status', 30)->default('posted');
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'doc_num']);
            $table->unique(['company_id', 'financial_period_id', 'doc_number'], 'customer_credit_refunds_context_number_unique');
            $table->index(['credit_note_id', 'status'], 'customer_credit_refunds_credit_index');
            $table->unique(['company_id', 'idempotency_key'], 'customer_credit_refunds_idempotency_unique');
        });

        Schema::create('electronic_invoice_submissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('customer_invoice_id')->constrained('customer_invoices')->restrictOnDelete();
            $table->string('provider', 80);
            $table->string('environment', 30);
            $table->string('payload_version', 40);
            $table->string('payload_hash', 64);
            $table->json('payload');
            $table->string('status', 40)->default('ready');
            $table->string('provider_reference')->nullable();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->string('error_classification', 80)->nullable();
            $table->text('error_message')->nullable();
            $table->json('response_metadata')->nullable();
            $table->timestamps();

            $table->unique(['customer_invoice_id', 'provider', 'environment', 'payload_hash'], 'electronic_invoice_submissions_idempotency_unique');
            $table->index(['company_id', 'status', 'created_at'], 'electronic_invoice_submissions_status_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('electronic_invoice_submissions');
        Schema::dropIfExists('customer_credit_refunds');
        Schema::dropIfExists('customer_credit_allocations');
        Schema::table('sales_returns', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('disposition_journal_entry_id');
            $table->dropConstrainedForeignId('quarantine_journal_entry_id');
        });
        Schema::table('customer_invoices', function (Blueprint $table): void {
            $table->dropColumn([
                'source_type', 'source_id', 'source_doc_num', 'credit_available_amount',
                'credit_allocated_amount', 'credit_refunded_amount',
            ]);
            $table->foreignId('sales_order_id')->nullable(false)->change();
        });
    }
};
