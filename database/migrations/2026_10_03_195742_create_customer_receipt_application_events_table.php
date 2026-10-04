<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('customer_receipt_application_events')) {
            return;
        }
        Schema::create('customer_receipt_application_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies');
            $table->foreignId('branch_id')->constrained('branches');
            $table->foreignId('currency_id')->constrained('currencies');
            $table->foreignId('receipt_id')->constrained('customer_receipts');
            $table->foreignId('allocation_id')->constrained('customer_receipt_allocations');
            $table->foreignId('invoice_id')->constrained('customer_invoices');
            $table->foreignId('schedule_id')->constrained('customer_invoice_payment_schedules');
            $table->foreignId('journal_entry_id')->constrained('journal_entries');
            $table->date('posting_date');
            $table->decimal('amount', 20, 4);
            $table->char('evidence_seal', 64);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('created_at');
            $table->unique(['allocation_id', 'journal_entry_id'], 'receipt_application_journal_unique');
            $table->index(['company_id', 'invoice_id', 'posting_date'], 'receipt_application_invoice_date');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('customer_receipt_application_events') && DB::table('customer_receipt_application_events')->exists()) {
            throw new RuntimeException('Posted receipt application evidence cannot be discarded by migration rollback.');
        }
        Schema::dropIfExists('customer_receipt_application_events');
    }
};
