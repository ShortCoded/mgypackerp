<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @var list<string>
     */
    private array $tables = [
        'users',
        'companies',
        'hr_countries',
        'hr_governorates',
        'hr_cities',
        'hr_areas',
        'hr_nationalities',
        'hr_religions',
        'hr_qualifications',
        'hr_universities',
        'hr_faculties',
        'hr_specializations',
        'hr_insurances',
        'hr_military_services',
        'hr_allowances',
        'hr_work_permissions',
        'hr_hiring_statuses',
        'hr_identifications',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach ($this->tables() as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            if (DB::getDriverName() === 'sqlite') {
                $this->addSqliteColumns($tableName);

                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                $this->addRestoredAuditColumns($table, $tableName);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (array_reverse($this->tables()) as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            if (DB::getDriverName() === 'sqlite') {
                $this->dropSqliteColumns($tableName);

                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                if (Schema::hasColumn($tableName, 'restored_by')) {
                    $table->dropConstrainedForeignId('restored_by');
                }

                if (Schema::hasColumn($tableName, 'restored_at')) {
                    $table->dropColumn('restored_at');
                }
            });
        }
    }

    private function addRestoredAuditColumns(Blueprint $table, string $tableName): void
    {
        if (! Schema::hasColumn($tableName, 'restored_by')) {
            $table->foreignId('restored_by')->nullable()->constrained('users')->nullOnDelete();
        }

        if (! Schema::hasColumn($tableName, 'restored_at')) {
            $table->timestamp('restored_at')->nullable();
        }
    }

    private function addSqliteColumns(string $tableName): void
    {
        $grammar = DB::getQueryGrammar();
        $wrappedTable = $grammar->wrapTable($tableName);

        if (! Schema::hasColumn($tableName, 'restored_by')) {
            DB::statement("ALTER TABLE {$wrappedTable} ADD COLUMN restored_by INTEGER NULL");
        }

        if (! Schema::hasColumn($tableName, 'restored_at')) {
            DB::statement("ALTER TABLE {$wrappedTable} ADD COLUMN restored_at DATETIME NULL");
        }
    }

    private function dropSqliteColumns(string $tableName): void
    {
        $grammar = DB::getQueryGrammar();
        $wrappedTable = $grammar->wrapTable($tableName);

        if (Schema::hasColumn($tableName, 'restored_by')) {
            DB::statement("ALTER TABLE {$wrappedTable} DROP COLUMN restored_by");
        }

        if (Schema::hasColumn($tableName, 'restored_at')) {
            DB::statement("ALTER TABLE {$wrappedTable} DROP COLUMN restored_at");
        }
    }

    /**
     * @return list<string>
     */
    private function tables(): array
    {
        return array_values(array_unique([
            config('permission.table_names.roles', 'roles'),
            ...$this->tables,
        ]));
    }
};
