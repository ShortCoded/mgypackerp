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
        Schema::create('posted_invoice_line_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('financial_period_id')->constrained()->restrictOnDelete();
            $table->string('kind', 16);
            $table->unsignedBigInteger('invoice_id');
            $table->string('doc_num');
            $table->string('status', 16)->default('prepared');
            $table->date('posting_date');
            $table->text('reason');
            $table->string('source_fingerprint', 64);
            $table->string('proposal_fingerprint', 64);
            $table->json('source_snapshot');
            $table->json('replacement_input');
            $table->json('impact');
            $table->unsignedBigInteger('sales_correction_id')->nullable();
            $table->unsignedBigInteger('replacement_invoice_id')->nullable();
            $table->foreignId('prepared_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('approval_reason')->nullable();
            $table->json('execution_snapshot')->nullable();
            $table->string('execution_fingerprint', 64)->nullable();
            $table->timestamps();
            $table->index(['company_id', 'kind', 'invoice_id', 'status'], 'posted_invoice_corrections_source_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('posted_invoice_line_corrections');
    }
};
