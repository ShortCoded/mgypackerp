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
        'hr_employee_documents',
        'hr_employees',
        'hr_attendance_rules',
        'hr_regulations',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('hr_regulations')) {
            Schema::create('hr_regulations', function (Blueprint $table): void {
                $this->commonColumns($table);
                $table->string('code', 100)->nullable()->index();
                $table->text('description')->nullable();
                $table->date('effective_from')->nullable();
                $table->date('effective_to')->nullable();
                $table->string('applies_to', 30)->nullable()->default('all');
                $this->auditColumns($table);
            });
        }

        if (! Schema::hasTable('hr_attendance_rules')) {
            Schema::create('hr_attendance_rules', function (Blueprint $table): void {
                $this->commonColumns($table);
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
                $this->auditColumns($table);
            });
        }

        if (! Schema::hasTable('hr_employees')) {
            Schema::create('hr_employees', function (Blueprint $table): void {
                $table->id();
                $table->integer('doc_number')->nullable()->index();
                $table->string('doc_num')->nullable()->index();
                $table->string('employee_code')->nullable()->index();
                $table->string('full_name')->index();
                $table->string('name')->index();
                $table->string('status', 30)->default('active')->index();
                $table->string('gender', 20)->nullable();
                $table->date('birth_date')->nullable();
                $table->string('national_id', 60)->nullable()->index();
                $table->foreignId('nationality_id')->nullable()->constrained('hr_nationalities')->nullOnDelete();
                $table->foreignId('religion_id')->nullable()->constrained('hr_religions')->nullOnDelete();
                $table->string('marital_status', 30)->nullable();
                $table->foreignId('military_service_id')->nullable()->constrained('hr_military_services')->nullOnDelete();
                $table->string('phone', 50)->nullable();
                $table->string('mobile', 50)->nullable();
                $table->string('email')->nullable()->index();
                $table->string('address', 1000)->nullable();
                $table->foreignId('country_id')->nullable()->constrained('hr_countries')->nullOnDelete();
                $table->foreignId('governorate_id')->nullable()->constrained('hr_governorates')->nullOnDelete();
                $table->foreignId('city_id')->nullable()->constrained('hr_cities')->nullOnDelete();
                $table->foreignId('area_id')->nullable()->constrained('hr_areas')->nullOnDelete();
                $table->foreignId('qualification_id')->nullable()->constrained('hr_qualifications')->nullOnDelete();
                $table->foreignId('university_id')->nullable()->constrained('hr_universities')->nullOnDelete();
                $table->foreignId('faculty_id')->nullable()->constrained('hr_faculties')->nullOnDelete();
                $table->foreignId('specialization_id')->nullable()->constrained('hr_specializations')->nullOnDelete();
                $table->unsignedSmallInteger('graduation_year')->nullable();
                $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
                $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
                $table->foreignId('hiring_status_id')->nullable()->constrained('hr_hiring_statuses')->nullOnDelete();
                $table->foreignId('work_permission_id')->nullable()->constrained('hr_work_permissions')->nullOnDelete();
                $table->foreignId('insurance_id')->nullable()->constrained('hr_insurances')->nullOnDelete();
                $table->foreignId('regulation_id')->nullable()->constrained('hr_regulations')->nullOnDelete();
                $table->foreignId('attendance_rule_id')->nullable()->constrained('hr_attendance_rules')->nullOnDelete();
                $table->date('hire_date')->nullable()->index();
                $table->date('contract_start_date')->nullable();
                $table->date('contract_end_date')->nullable();
                $table->date('probation_end_date')->nullable();
                $table->string('job_title')->nullable();
                $table->string('department')->nullable();
                $table->decimal('basic_salary', 15, 2)->nullable();
                $table->foreignId('allowance_id')->nullable()->constrained('hr_allowances')->nullOnDelete();
                $table->text('notes')->nullable();
                $this->auditColumns($table);
            });
        }

        if (! Schema::hasTable('hr_employee_documents')) {
            Schema::create('hr_employee_documents', function (Blueprint $table): void {
                $table->id();
                $table->integer('doc_number')->nullable()->index();
                $table->string('doc_num')->nullable()->index();
                $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
                $table->string('document_type', 50)->default('other')->index();
                $table->string('title');
                $table->string('file_path');
                $table->string('original_name');
                $table->string('mime_type')->nullable();
                $table->string('extension', 20)->nullable();
                $table->unsignedBigInteger('size')->default(0);
                $table->date('expires_at')->nullable();
                $table->text('notes')->nullable();
                $this->auditColumns($table);
            });
        }

        foreach (['hr_regulations', 'hr_attendance_rules'] as $table) {
            foreach (['doc_number', 'doc_num', 'name', 'code'] as $column) {
                $this->createActiveUniqueIndex($table, $column);
            }
        }

        foreach (['doc_number', 'doc_num', 'employee_code', 'national_id', 'email'] as $column) {
            $this->createActiveUniqueIndex('hr_employees', $column);
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function commonColumns(Blueprint $table): void
    {
        $table->id();
        $table->integer('doc_number')->nullable()->index();
        $table->string('doc_num')->nullable()->index();
        $table->string('name')->index();
        $table->string('status', 30)->default('active')->index();
        $table->text('notes')->nullable();
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
        $wrappedIndex = $grammar->wrap("{$table}_{$column}_unique_active");
        $wrappedTable = $grammar->wrapTable($table);
        $wrappedColumn = $grammar->wrap($column);
        $wrappedDeletedAt = $grammar->wrap('deleted_at');

        match (DB::getDriverName()) {
            'pgsql', 'sqlite' => DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS {$wrappedIndex} ON {$wrappedTable} ({$wrappedColumn}) WHERE {$wrappedDeletedAt} IS NULL AND {$wrappedColumn} IS NOT NULL"),
            default => null,
        };
    }
};
