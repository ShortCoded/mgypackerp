<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_material_substitutions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('financial_period_id')->constrained()->restrictOnDelete();
            $table->foreignId('production_run_id')->constrained()->restrictOnDelete();
            $table->foreignId('original_requirement_id')->constrained('production_material_requirements')->restrictOnDelete();
            $table->foreignId('replacement_requirement_id')->nullable()->constrained('production_material_requirements')->restrictOnDelete();
            $table->foreignId('replacement_product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('branch_store_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 20, 8);
            $table->date('posting_date');
            $table->string('status', 30)->default('prepared');
            $table->text('reason');
            $table->string('fingerprint', 64);
            $table->string('proposal_seal', 64);
            $table->json('source_snapshot');
            $table->json('replacement_snapshot');
            $table->foreignId('prepared_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('recipe_approval_evidence')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->foreignId('return_document_id')->nullable()->constrained('inventory_documents')->restrictOnDelete();
            $table->foreignId('issue_document_id')->nullable()->constrained('inventory_documents')->restrictOnDelete();
            $table->json('execution_snapshot')->nullable();
            $table->string('execution_seal', 64)->nullable();
            $table->timestamps();
            $table->index(['production_run_id', 'status']);
        });
    }

    public function down(): void
    {
        if (DB::table('production_material_substitutions')->exists()) {
            throw new RuntimeException('Material substitution evidence exists; rollback would discard history.');
        }
        Schema::dropIfExists('production_material_substitutions');
    }
};
