<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cheque_collection_corrections', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('cheque_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_receipt_id')->constrained()->restrictOnDelete();
            $table->foreignId('financial_period_id')->constrained()->restrictOnDelete();
            $table->foreignId('posting_financial_period_id')->constrained('financial_periods')->restrictOnDelete();
            $table->foreignId('original_journal_entry_id')->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('reversal_journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->string('status', 20)->default('prepared');
            $table->string('treatment', 40);
            $table->date('posting_date');
            $table->string('bank_reference', 255);
            $table->text('reason');
            $table->text('evidence');
            $table->json('source_snapshot');
            $table->json('execution_snapshot')->nullable();
            $table->string('proposal_seal', 64);
            $table->string('execution_seal', 64)->nullable();
            $table->foreignId('prepared_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'cheque_id', 'status']);
        });
    }

    public function down(): void
    {
        if (DB::table('cheque_collection_corrections')->exists()) {
            throw new RuntimeException('Cheque collection correction history must be preserved.');
        }
        Schema::dropIfExists('cheque_collection_corrections');
    }
};
