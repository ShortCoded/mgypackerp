<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->createRegulationsTable();
        $this->createAttendanceRulesTable();
        $this->alignEmployeesTable();
        $this->alignEmployeeDocumentsTable();
    }

    public function down(): void
    {
        foreach (['hr_regulations', 'hr_attendance_rules'] as $table) {
            foreach (['doc_number', 'doc_num', 'name', 'code'] as $column) {
                $this->dropIndex("{$table}_{$column}_unique_active");
            }
        }

        Schema::dropIfExists('hr_attendance_rules');
        Schema::dropIfExists('hr_regulations');
    }

    private function createRegulationsTable(): void
    {
        if (! Schema::hasTable('hr_regulations')) {
            Schema::create('hr_regulations', function (Blueprint $table): void {
                $table->id();
                $table->integer('doc_number')->nullable()->index();
                $table->string('doc_num')->nullable()->index();
                $table->string('name')->index();
                $table->string('code', 100)->nullable()->index();
                $table->text('description')->nullable();
                $table->date('effective_from')->nullable();
                $table->date('effective_to')->nullable();
                $table->string('applies_to', 30)->nullable()->default('all');
                $table->string('status', 30)->default('active')->index();
                $table->text('notes')->nullable();
                $this->auditColumns($table);
            });
        }

        foreach (['doc_number', 'doc_num', 'name'] as $column) {
            $this->createActiveUniqueIndex('hr_regulations', $column);
        }

        $this->createActiveUniqueIndex('hr_regulations', 'code');
    }

    private function createAttendanceRulesTable(): void
    {
        if (! Schema::hasTable('hr_attendance_rules')) {
            Schema::create('hr_attendance_rules', function (Blueprint $table): void {
                $table->id();
                $table->integer('doc_number')->nullable()->index();
                $table->string('doc_num')->nullable()->index();
                $table->string('name')->index();
                $table->string('code', 100)->nullable()->index();
                $table->foreignId('regulation_id')->nullable()->constrained('hr_regulations')->nullOnDelete();
                $table->time('work_start_time');
                $table->time('work_end_time');
                $table->unsignedSmallInteger('grace_minutes_late')->default(0);
                $table->unsignedSmallInteger('grace_minutes_early_leave')->default(0);
                $table->unsignedSmallInteger('allowed_late_minutes_per_month')->nullable();
                $table->unsignedSmallInteger('allowed_early_leave_minutes_per_month')->nullable();
                $table->unsignedSmallInteger('deduct_after_late_minutes')->nullable();
                $table->unsignedSmallInteger('deduct_after_early_leave_minutes')->nullable();
                $table->boolean('overtime_allowed')->default(false);
                $table->unsignedSmallInteger('overtime_after_minutes')->nullable();
                $table->unsignedSmallInteger('break_minutes')->default(0);
                $table->json('weekend_days')->nullable();
                $table->boolean('requires_check_in')->default(true);
                $table->boolean('requires_check_out')->default(true);
                $table->string('status', 30)->default('active')->index();
                $table->text('notes')->nullable();
                $this->auditColumns($table);
            });
        }

        foreach (['doc_number', 'doc_num', 'name'] as $column) {
            $this->createActiveUniqueIndex('hr_attendance_rules', $column);
        }

        $this->createActiveUniqueIndex('hr_attendance_rules', 'code');
    }

    private function alignEmployeesTable(): void
    {
        if (! Schema::hasTable('hr_employees')) {
            return;
        }

        Schema::table('hr_employees', function (Blueprint $table): void {
            $this->addColumnIfMissing($table, 'employee_code', fn (Blueprint $table): mixed => $table->string('employee_code')->nullable()->index());
            $this->addColumnIfMissing($table, 'email', fn (Blueprint $table): mixed => $table->string('email')->nullable()->index());
            $this->addColumnIfMissing($table, 'department', fn (Blueprint $table): mixed => $table->string('department')->nullable());
            $this->addColumnIfMissing($table, 'basic_salary', fn (Blueprint $table): mixed => $table->decimal('basic_salary', 15, 2)->nullable());
            $this->addColumnIfMissing($table, 'graduation_year', fn (Blueprint $table): mixed => $table->unsignedSmallInteger('graduation_year')->nullable());
            $this->addColumnIfMissing($table, 'contract_start_date', fn (Blueprint $table): mixed => $table->date('contract_start_date')->nullable());
            $this->addColumnIfMissing($table, 'contract_end_date', fn (Blueprint $table): mixed => $table->date('contract_end_date')->nullable());
            $this->addColumnIfMissing($table, 'allowance_id', fn (Blueprint $table): mixed => $table->foreignId('allowance_id')->nullable()->constrained('hr_allowances')->nullOnDelete());
            $this->addColumnIfMissing($table, 'work_permission_id', fn (Blueprint $table): mixed => $table->foreignId('work_permission_id')->nullable()->constrained('hr_work_permissions')->nullOnDelete());
            $this->addColumnIfMissing($table, 'regulation_id', fn (Blueprint $table): mixed => $table->foreignId('regulation_id')->nullable()->constrained('hr_regulations')->nullOnDelete());
            $this->addColumnIfMissing($table, 'attendance_rule_id', fn (Blueprint $table): mixed => $table->foreignId('attendance_rule_id')->nullable()->constrained('hr_attendance_rules')->nullOnDelete());
        });

        foreach (['doc_number', 'doc_num', 'employee_code', 'national_id', 'email'] as $column) {
            $this->createActiveUniqueIndex('hr_employees', $column);
        }
    }

    private function alignEmployeeDocumentsTable(): void
    {
        if (! Schema::hasTable('hr_employee_documents')) {
            return;
        }

        Schema::table('hr_employee_documents', function (Blueprint $table): void {
            $this->addColumnIfMissing($table, 'document_type', fn (Blueprint $table): mixed => $table->string('document_type', 50)->nullable()->default('other')->index());
        });
    }

    private function addColumnIfMissing(Blueprint $table, string $column, callable $callback): void
    {
        if (! Schema::hasColumn($table->getTable(), $column)) {
            $callback($table);
        }
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

    private function dropIndex(string $index): void
    {
        if (! in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            return;
        }

        $wrappedIndex = DB::getQueryGrammar()->wrap($index);
        DB::statement("DROP INDEX IF EXISTS {$wrappedIndex}");
    }
};
