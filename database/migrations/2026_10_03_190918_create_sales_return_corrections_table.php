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
        Schema::create('sales_return_corrections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('sales_return_id')->constrained()->restrictOnDelete();
            $table->foreignId('source_financial_period_id')->constrained('financial_periods')->restrictOnDelete();
            $table->foreignId('posting_financial_period_id')->constrained('financial_periods')->restrictOnDelete();
            $table->foreignId('allocation_id')->nullable()->constrained('customer_credit_allocations')->restrictOnDelete();
            $table->foreignId('refund_id')->nullable()->constrained('customer_credit_refunds')->restrictOnDelete();
            $table->foreignId('replacement_return_id')->nullable()->constrained('sales_returns')->restrictOnDelete();
            $table->string('operation', 30);
            $table->string('status', 30)->default('prepared');
            $table->date('posting_date');
            $table->text('reason');
            $table->string('recovery_reference', 255)->nullable();
            $table->json('replacement_payload');
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
            $table->index(['company_id', 'sales_return_id', 'status'], 'sales_return_correction_scope');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('sales_return_corrections')->exists()) {
            throw new RuntimeException('Preserve sales correction proposals and recovery evidence before rollback.');
        }
        Schema::dropIfExists('sales_return_corrections');
    }
};
