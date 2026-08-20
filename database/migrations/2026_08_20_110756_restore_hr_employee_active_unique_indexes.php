<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->restoreActiveIndexes();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('hr_employees') || ! in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            return;
        }

        $grammar = DB::getQueryGrammar();

        foreach (['doc_number', 'doc_num', 'employee_code', 'national_id', 'email', 'work_email'] as $column) {
            $index = $grammar->wrap("hr_employees_{$column}_unique_active");
            DB::statement("DROP INDEX IF EXISTS {$index}");
        }
    }

    private function restoreActiveIndexes(): void
    {
        if (! Schema::hasTable('hr_employees') || ! in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            return;
        }

        $grammar = DB::getQueryGrammar();
        $table = $grammar->wrapTable('hr_employees');
        $deletedAt = $grammar->wrap('deleted_at');

        foreach (['doc_number', 'doc_num', 'employee_code', 'national_id', 'email', 'work_email'] as $column) {
            if (! Schema::hasColumn('hr_employees', $column)) {
                continue;
            }

            $index = $grammar->wrap("hr_employees_{$column}_unique_active");
            $wrappedColumn = $grammar->wrap($column);
            DB::statement("DROP INDEX IF EXISTS {$index}");
            DB::statement("CREATE UNIQUE INDEX {$index} ON {$table} ({$wrappedColumn}) WHERE {$deletedAt} IS NULL AND {$wrappedColumn} IS NOT NULL");
        }
    }
};
