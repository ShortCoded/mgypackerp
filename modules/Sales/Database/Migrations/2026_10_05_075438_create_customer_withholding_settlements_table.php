<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_withholding_settlements', function (Blueprint $table): void {
            $table->id();
            $table->uuid('doc_num')->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('currency_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_schedule_id')->constrained('customer_invoice_payment_schedules')->restrictOnDelete();
            $table->foreignId('customer_receipt_id')->constrained()->restrictOnDelete();
            $table->foreignId('certificate_file_id')->constrained('archive_files')->restrictOnDelete();
            $table->string('certificate_reference');
            $table->date('certificate_date');
            $table->date('posting_date');
            $table->foreignId('financial_period_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 20, 4);
            $table->decimal('exchange_rate', 20, 6);
            $table->decimal('expected_amount', 20, 4);
            $table->text('reason');
            $table->string('status', 20)->default('prepared');
            $table->json('source_snapshot');
            $table->string('source_fingerprint', 64);
            $table->string('proposal_fingerprint', 64);
            $table->json('execution_snapshot')->nullable();
            $table->string('execution_fingerprint', 64)->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('prepared_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('approval_reason')->nullable();
            $table->foreignId('reversal_journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->date('reversal_date')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->string('recovery_reference')->nullable();
            $table->foreignId('recovery_file_id')->nullable()->constrained('archive_files')->restrictOnDelete();
            $table->text('reversal_reason')->nullable();
            $table->json('reversal_snapshot')->nullable();
            $table->string('reversal_fingerprint', 64)->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'customer_id', 'certificate_reference'], 'customer_wht_certificate_unique');
            $table->index(['company_id', 'customer_invoice_id', 'status'], 'customer_wht_invoice_status');
        });
        foreach (['customer_invoices', 'customer_invoice_payment_schedules'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->decimal('actual_withholding_amount', 20, 4)->default(0));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_withholding_settlements');
        foreach (['customer_invoice_payment_schedules', 'customer_invoices'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('actual_withholding_amount'));
        }
    }
};
