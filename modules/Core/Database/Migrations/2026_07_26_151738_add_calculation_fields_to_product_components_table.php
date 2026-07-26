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
            return;
        }

        Schema::table('product_components', function (Blueprint $table): void {
            $table->string('calculation_method', 20)
                ->default('direct')
                ->after('unit_id');
            $table->decimal('percentage', 18, 8)
                ->nullable()
                ->after('quantity');
            $table->foreignId('reference_component_id')
                ->nullable()
                ->after('percentage')
                ->constrained('product_components')
                ->restrictOnDelete();
        });

        $this->dropActiveComponentProductUniqueIndex();
    }

    public function down(): void
    {
        if (! Schema::hasTable('product_components')) {
            return;
        }

        $this->guardRollbackAgainstActiveDuplicates();

        Schema::table('product_components', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reference_component_id');
            $table->dropColumn(['calculation_method', 'percentage']);
        });

        $this->createActiveComponentProductUniqueIndex();
    }

    private function dropActiveComponentProductUniqueIndex(): void
    {
        $index = DB::getQueryGrammar()->wrap('product_components_product_component_unique_active');

        match (DB::getDriverName()) {
            'pgsql', 'sqlite' => DB::statement("DROP INDEX IF EXISTS {$index}"),
            default => null,
        };
    }

    private function guardRollbackAgainstActiveDuplicates(): void
    {
        if (! in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            return;
        }

        $hasActiveDuplicates = DB::table('product_components')
            ->select(['product_id', 'component_product_id'])
            ->whereNull('deleted_at')
            ->groupBy('product_id', 'component_product_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($hasActiveDuplicates) {
            throw new RuntimeException(
                'Cannot roll back the Product BOM calculation migration while duplicate active component items exist. Resolve those duplicates explicitly before retrying; no BOM data was changed.',
            );
        }
    }

    private function createActiveComponentProductUniqueIndex(): void
    {
        $grammar = DB::getQueryGrammar();
        $index = $grammar->wrap('product_components_product_component_unique_active');
        $table = $grammar->wrapTable('product_components');
        $productId = $grammar->wrap('product_id');
        $componentProductId = $grammar->wrap('component_product_id');
        $deletedAt = $grammar->wrap('deleted_at');

        match (DB::getDriverName()) {
            'pgsql', 'sqlite' => DB::statement(
                "CREATE UNIQUE INDEX IF NOT EXISTS {$index} ON {$table} ({$productId}, {$componentProductId}) WHERE {$deletedAt} IS NULL"
            ),
            default => null,
        };
    }
};
