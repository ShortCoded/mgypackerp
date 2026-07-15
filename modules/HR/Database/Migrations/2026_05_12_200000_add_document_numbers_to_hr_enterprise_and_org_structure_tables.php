<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @var list<string>
     */
    private const TABLES_FOR_DOC_NUMBERS = [
        'hr_org_unit_types',
        'hr_org_units',
        'hr_positions',
        'hr_jobs',
        'hr_grades',
        'hr_job_levels',
        'hr_cost_centers',
        'hr_work_locations',
        'hr_employee_categories',
        'hr_employment_types',
        'hr_contract_types',
    ];

    public function up(): void
    {
        foreach (self::TABLES_FOR_DOC_NUMBERS as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                if (! Schema::hasColumn($table, 'doc_number')) {
                    $blueprint->unsignedInteger('doc_number')->nullable()->index();
                }

                if (! Schema::hasColumn($table, 'doc_num')) {
                    $blueprint->string('doc_num', 120)->nullable()->index();
                }
            });
        }

        if (! Schema::hasTable('hr_job_families')) {
            Schema::create('hr_job_families', function (Blueprint $table): void {
                $table->id();
                $table->unsignedInteger('doc_number')->nullable()->index();
                $table->string('doc_num', 120)->nullable()->index();
                $table->string('code', 80)->nullable()->index();
                $table->string('name')->index();
                $table->string('status', 30)->default('active')->index();
                $table->text('notes')->nullable();
                $this->auditColumns($table);
            });
        }

        if (! Schema::hasTable('hr_departments')) {
            Schema::create('hr_departments', function (Blueprint $table): void {
                $table->id();
                $table->unsignedInteger('doc_number')->nullable()->index();
                $table->string('doc_num', 120)->nullable()->index();
                $table->foreignId('org_unit_id')->nullable()->constrained('hr_org_units')->nullOnDelete();
                $table->string('code', 80)->nullable()->index();
                $table->string('name')->index();
                $table->string('status', 30)->default('active')->index();
                $table->text('notes')->nullable();
                $this->auditColumns($table);
            });
        }

        if (! Schema::hasTable('hr_sections')) {
            Schema::create('hr_sections', function (Blueprint $table): void {
                $table->id();
                $table->unsignedInteger('doc_number')->nullable()->index();
                $table->string('doc_num', 120)->nullable()->index();
                $table->foreignId('department_id')->nullable()->constrained('hr_departments')->nullOnDelete();
                $table->string('code', 80)->nullable()->index();
                $table->string('name')->index();
                $table->string('status', 30)->default('active')->index();
                $table->text('notes')->nullable();
                $this->auditColumns($table);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_sections');
        Schema::dropIfExists('hr_departments');
        Schema::dropIfExists('hr_job_families');

        foreach (self::TABLES_FOR_DOC_NUMBERS as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                if (Schema::hasColumn($table, 'doc_num')) {
                    $blueprint->dropColumn('doc_num');
                }

                if (Schema::hasColumn($table, 'doc_number')) {
                    $blueprint->dropColumn('doc_number');
                }
            });
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
};
