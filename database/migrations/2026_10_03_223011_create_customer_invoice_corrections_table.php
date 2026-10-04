<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('customer_invoice_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('posting_financial_period_id')->constrained('financial_periods')->restrictOnDelete();
            $table->date('posting_date');
            $table->string('status', 30)->default('prepared');
            $table->text('reason');
            $table->string('recovery_reference', 255);
            $table->json('source_snapshot');
            $table->string('source_fingerprint', 64);
            $table->string('proposal_fingerprint', 64);
            $table->string('approval_fingerprint', 64)->nullable();
            $table->json('execution_snapshot')->nullable();
            $table->string('execution_fingerprint', 64)->nullable();
            $table->foreignId('prepared_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('approval_reason')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->index(['company_id', 'customer_invoice_id', 'status'], 'customer_invoice_correction_scope');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('customer_invoice_corrections')->exists()) {
            throw new RuntimeException('Preserve invoice correction proposals and recovery evidence before rollback.');
        }
        Schema::dropIfExists('customer_invoice_corrections');
    }
};
