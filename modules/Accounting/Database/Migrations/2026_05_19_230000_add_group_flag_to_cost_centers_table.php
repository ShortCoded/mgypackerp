<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cost_centers')) {
            return;
        }

        if (! Schema::hasColumn('cost_centers', 'is_group')) {
            Schema::table('cost_centers', function (Blueprint $table): void {
                $table->boolean('is_group')->default(false)->after('name')->index('cost_centers_is_group_index');
            });
        }

        DB::table('cost_centers')
            ->whereIn('id', function ($query): void {
                $query
                    ->select('parent_id')
                    ->from('cost_centers')
                    ->whereNull('deleted_at')
                    ->whereNotNull('parent_id');
            })
            ->update(['is_group' => true]);

        $this->createIndexIfMissing('cost_centers', 'cost_centers_company_status_group_index', ['company_id', 'status', 'is_group']);
    }

    public function down(): void
    {
        if (! Schema::hasTable('cost_centers') || ! Schema::hasColumn('cost_centers', 'is_group')) {
            return;
        }

        $this->dropIndexIfExists('cost_centers_company_status_group_index');
        $this->dropIndexIfExists('cost_centers_is_group_index');

        Schema::table('cost_centers', function (Blueprint $table): void {
            $table->dropColumn('is_group');
        });
    }

    /**
     * @param  list<string>  $columns
     */
    private function createIndexIfMissing(string $tableName, string $index, array $columns): void
    {
        foreach ($columns as $column) {
            if (! Schema::hasColumn($tableName, $column)) {
                return;
            }
        }

        $grammar = DB::getQueryGrammar();
        $wrappedIndex = $grammar->wrap($index);
        $wrappedTable = $grammar->wrapTable($tableName);
        $wrappedColumns = implode(', ', array_map(fn (string $column): string => $grammar->wrap($column), $columns));

        match (DB::getDriverName()) {
            'pgsql', 'sqlite' => DB::statement("CREATE INDEX IF NOT EXISTS {$wrappedIndex} ON {$wrappedTable} ({$wrappedColumns})"),
            default => null,
        };
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
