<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_receipt_cost_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_document_id')->constrained('inventory_documents')->restrictOnDelete();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('financial_period_id')->constrained('financial_periods')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->unsignedInteger('revision');
            $table->string('status', 20)->default('pending')->index();
            $table->string('basis', 20);
            $table->string('source_reference', 255)->nullable();
            $table->text('basis_note')->nullable();
            $table->json('line_snapshot');
            $table->string('source_file_path', 255)->nullable();
            $table->string('source_file_name', 180)->nullable();
            $table->string('source_file_sha256', 64)->nullable();
            $table->string('approval_reference', 255)->nullable();
            $table->text('rejection_reason')->nullable();
            $table->foreignId('prepared_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamps();

            $table->unique(['inventory_document_id', 'revision'], 'receipt_cost_proposals_document_revision_unique');
            $table->index(['company_id', 'financial_period_id', 'branch_id'], 'receipt_cost_proposals_scope_index');
        });
    }

    public function down(): void
    {
        if (DB::table('inventory_receipt_cost_proposals')->exists()) {
            throw new RuntimeException('Receipt-cost proposal evidence cannot be dropped.');
        }

        Schema::dropIfExists('inventory_receipt_cost_proposals');
    }
};
