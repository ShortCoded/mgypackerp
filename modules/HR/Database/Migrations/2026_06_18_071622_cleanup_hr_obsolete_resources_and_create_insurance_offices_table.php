<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Topological order: children (tables with FK references to other obsolete
     * tables) must be dropped before their parents. PostgreSQL refuses to drop
     * a table when another table still has a foreign key pointing to it.
     *
     * Dependency chains (child → parent):
     *   hr_employee_insurance_policies → hr_insurances
     *   hr_employee_assignments       → hr_positions, hr_org_units, hr_cost_centers, hr_work_locations
     *   hr_attendance_rules           → hr_regulations
     *   hr_positions                  → hr_job_levels
     *   hr_org_units                  → hr_org_unit_types
     *
     * @var list<string>
     */
    private array $obsoleteTables = [
        'hr_employee_insurance_policies',
        'hr_employee_assignments',
        'hr_attendance_rules',
        'hr_positions',
        'hr_org_units',
        'hr_regulations',
        'hr_contract_types',
        'hr_cost_centers',
        'hr_employee_categories',
        'hr_job_families',
        'hr_job_levels',
        'hr_professions',
        'hr_work_locations',
        'hr_work_permissions',
        'hr_org_unit_types',
        'hr_insurances',
    ];

    public function up(): void
    {
        $this->dropDependentForeignKeysAndColumns();
        $this->dropObsoleteTables();
        $this->createInsuranceOfficesTable();
    }

    public function down(): void
    {
        // This cleanup intentionally drops obsolete HR data tables. A full rollback
        // cannot safely reconstruct deleted data or historical FK relationships.
        // The reversible part is limited to removing the new clean table.
        foreach (['doc_number', 'doc_num', 'name', 'insurance_office_code'] as $column) {
            $this->dropActiveUniqueIndex('hr_insurance_offices', $column);
        }

        Schema::dropIfExists('hr_insurance_offices');
    }

    private function dropDependentForeignKeysAndColumns(): void
    {
        $this->dropColumnsIfExist('hr_employees', [
            'primary_assignment_id',
            'work_permission_id',
            'insurance_id',
            'regulation_id',
            'attendance_rule_id',
            'attendance_regulation_id',
            'leave_regulation_id',
            'work_regulation_id',
            'attendance_departure_rule_id',
            'org_unit_id',
            'position_id',
            'job_level_id',
            'cost_center_id',
            'work_location_id',
            'profession_id',
        ]);

        $this->dropColumnsIfExist('hr_departments', [
            'org_unit_id',
        ]);

        $this->dropColumnsIfExist('hr_document_requirements', [
            'contract_type_id',
        ]);

        $this->dropColumnsIfExist('hr_employee_contracts', [
            'contract_type_id',
        ]);

        $this->dropColumnsIfExist('hr_job_requisitions', [
            'org_unit_id',
            'position_id',
        ]);
    }

    private function dropObsoleteTables(): void
    {
        foreach ($this->obsoleteTables as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function createInsuranceOfficesTable(): void
    {
        if (! Schema::hasTable('hr_insurance_offices')) {
            Schema::create('hr_insurance_offices', function (Blueprint $table): void {
                $table->id();
                $table->integer('doc_number')->nullable()->index();
                $table->string('doc_num')->nullable()->index();
                $table->string('name')->index();
                $table->string('insurance_office_code', 80)->nullable()->index();
                $table->text('address')->nullable();
                $table->string('phone', 50)->nullable();
                $table->string('email')->nullable();
                $table->string('contact_person')->nullable();
                $table->string('status', 30)->default('active')->index();
                $table->text('notes')->nullable();
                $this->auditColumns($table);
            });
        }

        foreach (['doc_number', 'doc_num', 'name', 'insurance_office_code'] as $column) {
            $this->createActiveUniqueIndex('hr_insurance_offices', $column);
        }
    }

    /**
     * @param  list<string>  $columns
     */
    private function dropColumnsIfExist(string $table, array $columns): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        $existingColumns = array_values(array_filter(
            $columns,
            fn (string $column): bool => Schema::hasColumn($table, $column),
        ));

        if ($existingColumns === []) {
            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            $this->rebuildSqliteTableWithoutColumns($table, $existingColumns);

            return;
        }

        $this->dropForeignKeysForColumns($table, $existingColumns);

        Schema::table($table, function (Blueprint $table) use ($existingColumns): void {
            $table->dropColumn($existingColumns);
        });
    }

    /**
     * @param  list<string>  $columns
     */
    private function dropForeignKeysForColumns(string $table, array $columns): void
    {
        foreach (Schema::getForeignKeys($table) as $foreignKey) {
            $foreignColumns = $foreignKey['columns'] ?? [];

            if (! is_array($foreignColumns) || array_intersect($foreignColumns, $columns) === []) {
                continue;
            }

            $name = $foreignKey['name'] ?? null;

            if (! is_string($name) || $name === '') {
                continue;
            }

            Schema::table($table, function (Blueprint $table) use ($name): void {
                $table->dropForeign($name);
            });
        }
    }

    /**
     * SQLite cannot drop columns that are still present in foreign key metadata.
     * The application uses PostgreSQL in production; this keeps the test schema
     * aligned with the destructive cleanup migration without requiring legacy
     * HR foreign keys to survive.
     *
     * @param  list<string>  $columns
     */
    private function rebuildSqliteTableWithoutColumns(string $table, array $columns): void
    {
        $grammar = DB::getQueryGrammar();
        $wrappedTable = $grammar->wrapTable($table);
        $temporaryTable = "{$table}_cleanup_tmp";
        $wrappedTemporaryTable = $grammar->wrapTable($temporaryTable);
        $columnsToDrop = array_flip($columns);
        $tableColumns = array_values(array_filter(
            DB::select("PRAGMA table_xinfo({$wrappedTable})"),
            fn (object $column): bool => ! isset($columnsToDrop[$column->name]) && (int) ($column->hidden ?? 0) === 0,
        ));

        if ($tableColumns === []) {
            return;
        }

        $columnDefinitions = array_map(
            fn (object $column): string => $this->sqliteColumnDefinition($column),
            $tableColumns,
        );
        $wrappedColumnNames = array_map(
            fn (object $column): string => $grammar->wrap($column->name),
            $tableColumns,
        );
        $columnList = implode(', ', $wrappedColumnNames);

        Schema::disableForeignKeyConstraints();

        try {
            DB::statement("DROP TABLE IF EXISTS {$wrappedTemporaryTable}");
            DB::statement("CREATE TABLE {$wrappedTemporaryTable} (".implode(', ', $columnDefinitions).')');
            DB::statement("INSERT INTO {$wrappedTemporaryTable} ({$columnList}) SELECT {$columnList} FROM {$wrappedTable}");
            DB::statement("DROP TABLE {$wrappedTable}");
            DB::statement("ALTER TABLE {$wrappedTemporaryTable} RENAME TO {$wrappedTable}");
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }

    private function sqliteColumnDefinition(object $column): string
    {
        $definition = DB::getQueryGrammar()->wrap($column->name).' '.($column->type ?: 'TEXT');

        if ((int) $column->pk === 1) {
            $definition .= ' PRIMARY KEY';

            if (strtoupper((string) $column->type) === 'INTEGER') {
                $definition .= ' AUTOINCREMENT';
            }
        }

        if ((int) $column->notnull === 1 && (int) $column->pk !== 1) {
            $definition .= ' NOT NULL';
        }

        if ($column->dflt_value !== null) {
            $definition .= ' DEFAULT '.$column->dflt_value;
        }

        return $definition;
    }

    private function auditColumns(Blueprint $table): void
    {
        $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
        $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
        $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
        $table->foreignId('restored_by')->nullable()->constrained('users')->nullOnDelete();
        $table->timestamp('restored_at')->nullable();
        $table->timestamps();
        $table->softDeletes()->index();
    }

    private function createActiveUniqueIndex(string $table, string $column): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column) || ! Schema::hasColumn($table, 'deleted_at')) {
            return;
        }

        $grammar = DB::getQueryGrammar();
        $wrappedIndex = $grammar->wrap("{$table}_{$column}_unique_active");
        $wrappedTable = $grammar->wrapTable($table);
        $wrappedColumn = $grammar->wrap($column);
        $wrappedDeletedAt = $grammar->wrap('deleted_at');
        $notNull = in_array($column, ['doc_number', 'doc_num', 'insurance_office_code'], true) ? " AND {$wrappedColumn} IS NOT NULL" : '';

        match (DB::getDriverName()) {
            'pgsql', 'sqlite' => DB::statement(
                "CREATE UNIQUE INDEX IF NOT EXISTS {$wrappedIndex} ON {$wrappedTable} ({$wrappedColumn}) WHERE {$wrappedDeletedAt} IS NULL{$notNull}"
            ),
            default => null,
        };
    }

    private function dropActiveUniqueIndex(string $table, string $column): void
    {
        if (! in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            return;
        }

        $wrappedIndex = DB::getQueryGrammar()->wrap("{$table}_{$column}_unique_active");

        DB::statement("DROP INDEX IF EXISTS {$wrappedIndex}");
    }
};
