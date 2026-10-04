<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_movement_corrections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('inventory_document_id')->constrained()->restrictOnDelete();
            $table->foreignId('source_financial_period_id')->constrained('financial_periods')->restrictOnDelete();
            $table->foreignId('posting_financial_period_id')->constrained('financial_periods')->restrictOnDelete();
            $table->foreignId('replacement_document_id')->nullable()->constrained('inventory_documents')->restrictOnDelete();
            $table->string('operation', 20);
            $table->string('status', 20)->default('prepared');
            $table->date('posting_date');
            $table->text('reason');
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
            $table->index(['company_id', 'inventory_document_id', 'status'], 'inventory_correction_scope');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        if (DB::table('inventory_movement_corrections')->exists()) {
            throw new RuntimeException('Preserve inventory correction evidence before rollback.');
        }
        Schema::dropIfExists('inventory_movement_corrections');
    }
};
