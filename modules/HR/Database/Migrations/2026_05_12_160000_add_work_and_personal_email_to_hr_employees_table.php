<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hr_employees')) {
            return;
        }

        Schema::table('hr_employees', function (Blueprint $table): void {
            if (! Schema::hasColumn('hr_employees', 'work_email')) {
                $table->string('work_email')->nullable()->after('email');
            }

            if (! Schema::hasColumn('hr_employees', 'personal_email')) {
                $table->string('personal_email')->nullable()->after('work_email');
            }
        });

        $this->createActiveUniqueIndex('hr_employees', 'work_email');
    }

    public function down(): void
    {
        if (! Schema::hasTable('hr_employees')) {
            return;
        }

        $this->dropActiveUniqueIndex('hr_employees', 'work_email');

        Schema::table('hr_employees', function (Blueprint $table): void {
            if (Schema::hasColumn('hr_employees', 'personal_email')) {
                $table->dropColumn('personal_email');
            }

            if (Schema::hasColumn('hr_employees', 'work_email')) {
                $table->dropColumn('work_email');
            }
        });
    }

    private function createActiveUniqueIndex(string $table, string $column): void
    {
        if (! Schema::hasColumn($table, $column) || ! Schema::hasColumn($table, 'deleted_at')) {
            return;
        }

        $grammar = DB::getQueryGrammar();
        $index = "{$table}_{$column}_unique_active";
        $wrappedIndex = $grammar->wrap($index);
        $wrappedTable = $grammar->wrapTable($table);
        $wrappedColumn = $grammar->wrap($column);
        $wrappedDeletedAt = $grammar->wrap('deleted_at');

        match (DB::getDriverName()) {
            'pgsql', 'sqlite' => DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS {$wrappedIndex} ON {$wrappedTable} ({$wrappedColumn}) WHERE {$wrappedDeletedAt} IS NULL AND {$wrappedColumn} IS NOT NULL"),
            default => null,
        };
    }

    private function dropActiveUniqueIndex(string $table, string $column): void
    {
        if (! in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            return;
        }

        $index = "{$table}_{$column}_unique_active";
        $wrappedIndex = DB::getQueryGrammar()->wrap($index);
        DB::statement("DROP INDEX IF EXISTS {$wrappedIndex}");
    }
};
