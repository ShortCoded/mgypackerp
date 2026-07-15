<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        match (DB::getDriverName()) {
            'pgsql', 'sqlite' => $this->createIndexes(),
            default => null,
        };
    }

    public function down(): void
    {
        match (DB::getDriverName()) {
            'pgsql', 'sqlite' => $this->dropIndexes(),
            default => null,
        };
    }

    private function createIndexes(): void
    {
        if (Schema::hasTable('branch_refrigerators')) {
            $this->dropIndexIfExists('branch_refrigerators_branch_name_unique_active');
            $this->createBranchRefrigeratorNameIndex();
        }

        if (Schema::hasTable('branch_refrigerator_capacities')) {
            $this->createCapacityUnitIndex();
        }
    }

    private function dropIndexes(): void
    {
        $this->dropIndexIfExists('branch_refrigerator_capacities_unit_unique_active');
        $this->dropIndexIfExists('branch_refrigerators_branch_name_unique_active');

        if (Schema::hasTable('branch_refrigerators')) {
            $this->createLegacyBranchRefrigeratorNameIndex();
        }
    }

    private function createBranchRefrigeratorNameIndex(): void
    {
        $grammar = DB::getQueryGrammar();
        $index = $grammar->wrap('branch_refrigerators_branch_name_unique_active');
        $table = $grammar->wrapTable('branch_refrigerators');
        $branch = $grammar->wrap('branch_id');
        $name = $grammar->wrap('name');
        $deletedAt = $grammar->wrap('deleted_at');

        DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS {$index} ON {$table} ({$branch}, LOWER({$name})) WHERE {$deletedAt} IS NULL");
    }

    private function createLegacyBranchRefrigeratorNameIndex(): void
    {
        $grammar = DB::getQueryGrammar();
        $index = $grammar->wrap('branch_refrigerators_branch_name_unique_active');
        $table = $grammar->wrapTable('branch_refrigerators');
        $branch = $grammar->wrap('branch_id');
        $name = $grammar->wrap('name');
        $deletedAt = $grammar->wrap('deleted_at');

        DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS {$index} ON {$table} ({$branch}, {$name}) WHERE {$deletedAt} IS NULL");
    }

    private function createCapacityUnitIndex(): void
    {
        $grammar = DB::getQueryGrammar();
        $index = $grammar->wrap('branch_refrigerator_capacities_unit_unique_active');
        $table = $grammar->wrapTable('branch_refrigerator_capacities');
        $refrigerator = $grammar->wrap('branch_refrigerator_id');
        $unit = $grammar->wrap('item_unit_id');
        $deletedAt = $grammar->wrap('deleted_at');

        DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS {$index} ON {$table} ({$refrigerator}, {$unit}) WHERE {$deletedAt} IS NULL AND {$unit} IS NOT NULL");
    }

    private function dropIndexIfExists(string $index): void
    {
        $wrappedIndex = DB::getQueryGrammar()->wrap($index);

        DB::statement("DROP INDEX IF EXISTS {$wrappedIndex}");
    }
};
