<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_opening_stock_pricings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('doc_number')->nullable()->index();
            $table->string('doc_num')->nullable()->index();
            $table->date('document_date')->index();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('financial_period_id')->constrained('financial_periods')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('branch_hall_id')->nullable()->constrained('branch_halls')->nullOnDelete();
            $table->foreignId('opening_stock_id')->constrained('inventory_opening_stocks')->restrictOnDelete();
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->decimal('exchange_rate', 18, 6)->default(1);
            $table->decimal('total_amount', 15, 4)->default(0);
            $table->text('notes')->nullable();
            $table->boolean('is_closed')->default(true)->index();
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
            $table->index('opening_stock_id');
            $table->index('currency_id');
            $table->index(['company_id', 'financial_period_id'], 'inventory_opening_stock_pricings_context_index');
            $table->index(['company_id', 'financial_period_id', 'doc_num'], 'inventory_opening_stock_pricings_period_doc_num_index');
        });

        Schema::create('inventory_opening_stock_pricing_lines', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('financial_period_id')->constrained('financial_periods')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('pricing_id')->constrained('inventory_opening_stock_pricings')->cascadeOnDelete();
            $table->foreignId('opening_stock_line_id')->constrained('inventory_opening_stock_lines')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->jsonb('product_snapshot')->nullable();
            $table->decimal('quantity', 15, 4);
            $table->decimal('unit_price', 15, 4);
            $table->decimal('line_total', 15, 4);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes()->index();

            $table->index('company_id');
            $table->index('financial_period_id');
            $table->index('branch_id');
            $table->index('pricing_id');
            $table->index('opening_stock_line_id');
            $table->index('product_id');
            $table->index(['company_id', 'financial_period_id', 'branch_id'], 'inventory_opening_stock_pricing_lines_context_index');
        });

        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            $this->createActiveUniqueIndexes();
        }
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            $grammar = DB::getQueryGrammar();
            foreach ([
                'inventory_opening_stock_pricings_company_period_doc_num_unique_active',
                'inventory_opening_stock_pricings_opening_stock_unique_active',
                'inventory_opening_stock_pricing_lines_line_unique_active',
                'inventory_opening_stock_pricing_lines_product_unique_active',
            ] as $index) {
                DB::statement('DROP INDEX IF EXISTS '.$grammar->wrap($index));
            }
        }

        Schema::dropIfExists('inventory_opening_stock_pricing_lines');
        Schema::dropIfExists('inventory_opening_stock_pricings');
    }

    private function createActiveUniqueIndexes(): void
    {
        $grammar = DB::getQueryGrammar();
        $pricingsTable = $grammar->wrapTable('inventory_opening_stock_pricings');
        $linesTable = $grammar->wrapTable('inventory_opening_stock_pricing_lines');
        $deletedAt = $grammar->wrap('deleted_at');

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS '.$grammar->wrap('inventory_opening_stock_pricings_company_period_doc_num_unique_active').' ON '.$pricingsTable.' ('.$grammar->wrap('company_id').', '.$grammar->wrap('financial_period_id').', '.$grammar->wrap('doc_num').') WHERE '.$deletedAt.' IS NULL AND '.$grammar->wrap('doc_num').' IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS '.$grammar->wrap('inventory_opening_stock_pricings_opening_stock_unique_active').' ON '.$pricingsTable.' ('.$grammar->wrap('opening_stock_id').') WHERE '.$deletedAt.' IS NULL');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS '.$grammar->wrap('inventory_opening_stock_pricing_lines_line_unique_active').' ON '.$linesTable.' ('.$grammar->wrap('pricing_id').', '.$grammar->wrap('opening_stock_line_id').') WHERE '.$deletedAt.' IS NULL');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS '.$grammar->wrap('inventory_opening_stock_pricing_lines_product_unique_active').' ON '.$linesTable.' ('.$grammar->wrap('pricing_id').', '.$grammar->wrap('product_id').') WHERE '.$deletedAt.' IS NULL');
    }
};
