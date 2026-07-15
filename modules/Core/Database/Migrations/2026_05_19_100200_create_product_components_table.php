<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('product_components')) {
            Schema::create('product_components', function (Blueprint $table): void {
                $table->id();
                $table->uuid('public_id')->unique();
                $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
                $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
                $table->foreignId('component_product_id')->constrained('products')->restrictOnDelete();
                $table->foreignId('unit_id')->nullable()->constrained('item_units')->nullOnDelete();
                $table->decimal('quantity', 15, 4);
                $table->text('notes')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes()->index();

                $table->index('company_id');
                $table->index('product_id');
                $table->index('component_product_id');
            });
        }

        match (DB::getDriverName()) {
            'pgsql', 'sqlite' => $this->createActiveUniqueIndex(),
            default => null,
        };
    }

    public function down(): void
    {
        $this->dropIndexIfExists('product_components_product_component_unique_active');

        Schema::dropIfExists('product_components');
    }

    private function createActiveUniqueIndex(): void
    {
        if (! Schema::hasTable('product_components')) {
            return;
        }

        $grammar = DB::getQueryGrammar();
        $wrappedIndex = $grammar->wrap('product_components_product_component_unique_active');
        $wrappedTable = $grammar->wrapTable('product_components');
        $productId = $grammar->wrap('product_id');
        $componentProductId = $grammar->wrap('component_product_id');
        $deletedAt = $grammar->wrap('deleted_at');

        DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS {$wrappedIndex} ON {$wrappedTable} ({$productId}, {$componentProductId}) WHERE {$deletedAt} IS NULL");
    }

    private function dropIndexIfExists(string $index): void
    {
        $wrappedIndex = DB::getQueryGrammar()->wrap($index);

        match (DB::getDriverName()) {
            'pgsql', 'sqlite' => DB::statement("DROP INDEX IF EXISTS {$wrappedIndex}"),
            default => null,
        };
    }
};
