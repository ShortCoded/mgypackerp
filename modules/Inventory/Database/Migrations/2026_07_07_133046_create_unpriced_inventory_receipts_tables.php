<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unpriced_inventory_receipts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('doc_number')->nullable()->index();
            $table->string('doc_num')->nullable()->index();
            $table->date('document_date')->index();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('financial_period_id')->constrained('financial_periods')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('branch_hall_id')->nullable()->constrained('branch_halls')->nullOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->string('reference_number')->nullable()->index();
            $table->date('reference_date')->nullable()->index();
            $table->text('notes')->nullable();
            $table->boolean('approved')->default(false)->index();
            $table->boolean('is_closed')->default(false)->index();
            $table->string('status')->default('draft')->index();
            $table->string('pricing_status')->default('unpriced')->index();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('restored_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('restored_at')->nullable();
            $table->timestamps();
            $table->softDeletes()->index();

            $table->index('company_id');
            $table->index('financial_period_id');
            $table->index('branch_id');
            $table->index('branch_hall_id');
            $table->index('supplier_id');
            $table->index(['company_id', 'financial_period_id', 'branch_id'], 'unpriced_inventory_receipts_context_index');
            $table->index(['company_id', 'financial_period_id', 'doc_num'], 'unpriced_inventory_receipts_period_doc_num_index');
        });

        Schema::create('unpriced_inventory_receipt_lines', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('financial_period_id')->constrained('financial_periods')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('receipt_id')->constrained('unpriced_inventory_receipts')->cascadeOnDelete();
            $table->unsignedInteger('line_no');
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('item_units')->restrictOnDelete();
            $table->json('product_snapshot')->nullable();
            $table->decimal('quantity', 20, 8);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes()->index();

            $table->index('company_id');
            $table->index('financial_period_id');
            $table->index('branch_id');
            $table->index('receipt_id');
            $table->index('product_id');
            $table->index('unit_id');
            $table->index(['receipt_id', 'line_no']);
            $table->index(['company_id', 'financial_period_id', 'branch_id'], 'unpriced_inventory_receipt_lines_context_index');
        });

        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            $this->createActiveUniqueIndexes();
        }
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement('DROP INDEX IF EXISTS '.DB::getQueryGrammar()->wrap('unpriced_inventory_receipts_company_period_doc_num_unique_active'));
        }

        Schema::dropIfExists('unpriced_inventory_receipt_lines');
        Schema::dropIfExists('unpriced_inventory_receipts');
    }

    private function createActiveUniqueIndexes(): void
    {
        $grammar = DB::getQueryGrammar();
        $receiptsTable = $grammar->wrapTable('unpriced_inventory_receipts');
        $deletedAt = $grammar->wrap('deleted_at');

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS '.$grammar->wrap('unpriced_inventory_receipts_company_period_doc_num_unique_active').' ON '.$receiptsTable.' ('.$grammar->wrap('company_id').', '.$grammar->wrap('financial_period_id').', '.$grammar->wrap('doc_num').') WHERE '.$deletedAt.' IS NULL AND '.$grammar->wrap('doc_num').' IS NOT NULL');
    }
};
