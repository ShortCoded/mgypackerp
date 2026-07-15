<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_opening_stocks', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('doc_number')->nullable()->index();
            $table->string('doc_num')->nullable()->index();
            $table->date('document_date')->index();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('financial_period_id')->constrained('financial_periods')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('branch_hall_id')->nullable()->constrained('branch_halls')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->boolean('is_closed')->default(true)->index();
            $table->boolean('approved')->default(false)->index();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('closed')->index();
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
            $table->index(['company_id', 'financial_period_id', 'branch_id'], 'inventory_opening_stocks_context_index');
            $table->index(['company_id', 'financial_period_id', 'doc_num'], 'inventory_opening_stocks_period_doc_num_index');
        });

        Schema::create('inventory_opening_stock_lines', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('financial_period_id')->constrained('financial_periods')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('opening_stock_id')->constrained('inventory_opening_stocks')->cascadeOnDelete();
            $table->unsignedInteger('line_no');
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->decimal('quantity', 15, 4);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes()->index();

            $table->index('company_id');
            $table->index('financial_period_id');
            $table->index('branch_id');
            $table->index('opening_stock_id');
            $table->index('product_id');
            $table->index(['opening_stock_id', 'line_no']);
            $table->index(['company_id', 'financial_period_id', 'branch_id'], 'inventory_opening_stock_lines_context_index');
        });

        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            $this->createActiveUniqueIndexes();
        }
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            $grammar = DB::getQueryGrammar();
            DB::statement('DROP INDEX IF EXISTS '.$grammar->wrap('inventory_opening_stocks_company_period_doc_num_unique_active'));
            DB::statement('DROP INDEX IF EXISTS '.$grammar->wrap('inventory_opening_stock_lines_document_product_unique_active'));
        }

        Schema::dropIfExists('inventory_opening_stock_lines');
        Schema::dropIfExists('inventory_opening_stocks');
    }

    private function createActiveUniqueIndexes(): void
    {
        $grammar = DB::getQueryGrammar();
        $stocksTable = $grammar->wrapTable('inventory_opening_stocks');
        $linesTable = $grammar->wrapTable('inventory_opening_stock_lines');
        $deletedAt = $grammar->wrap('deleted_at');

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS '.$grammar->wrap('inventory_opening_stocks_company_period_doc_num_unique_active').' ON '.$stocksTable.' ('.$grammar->wrap('company_id').', '.$grammar->wrap('financial_period_id').', '.$grammar->wrap('doc_num').') WHERE '.$deletedAt.' IS NULL AND '.$grammar->wrap('doc_num').' IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS '.$grammar->wrap('inventory_opening_stock_lines_document_product_unique_active').' ON '.$linesTable.' ('.$grammar->wrap('opening_stock_id').', '.$grammar->wrap('product_id').') WHERE '.$deletedAt.' IS NULL');
    }
};
