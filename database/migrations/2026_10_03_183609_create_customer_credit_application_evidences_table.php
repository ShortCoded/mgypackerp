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
        Schema::create('customer_credit_application_evidences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('credit_note_id')->constrained('customer_invoices')->restrictOnDelete();
            $table->foreignId('original_invoice_id')->constrained('customer_invoices')->restrictOnDelete();
            $table->string('status', 30)->default('pending');
            $table->string('source_reference');
            $table->text('reason');
            $table->char('source_fingerprint', 64);
            $table->char('proposal_fingerprint', 64);
            $table->char('approval_fingerprint', 64)->nullable();
            $table->json('source_snapshot');
            $table->json('application_snapshot');
            $table->foreignId('prepared_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('approval_reason')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'credit_note_id', 'status'], 'credit_application_evidence_scope');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('customer_credit_application_evidences')->exists()) {
            throw new RuntimeException('Preserve customer credit application evidence and approvals before rollback.');
        }
        Schema::dropIfExists('customer_credit_application_evidences');
    }
};
