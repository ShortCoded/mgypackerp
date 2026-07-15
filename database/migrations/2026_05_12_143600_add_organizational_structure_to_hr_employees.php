<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @deprecated Prefer modules/HR/Database/Migrations/2026_05_12_210000_* which is loaded
     * by ModuleServiceProvider. Kept idempotent for installs that still run database/migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('hr_employees')) {
            return;
        }

        foreach ($this->requiredReferenceTables() as $tableName) {
            if (! Schema::hasTable($tableName)) {
                return;
            }
        }

        Schema::table('hr_employees', function (Blueprint $table): void {
            if (! Schema::hasColumn('hr_employees', 'org_unit_id')) {
                $table->foreignId('org_unit_id')->nullable()->constrained('hr_org_units')->nullOnDelete();
            }

            if (! Schema::hasColumn('hr_employees', 'department_id')) {
                $table->foreignId('department_id')->nullable()->constrained('hr_departments')->nullOnDelete();
            }

            if (! Schema::hasColumn('hr_employees', 'section_id')) {
                $table->foreignId('section_id')->nullable()->constrained('hr_sections')->nullOnDelete();
            }

            if (! Schema::hasColumn('hr_employees', 'position_id')) {
                $table->foreignId('position_id')->nullable()->constrained('hr_positions')->nullOnDelete();
            }

            if (! Schema::hasColumn('hr_employees', 'job_id')) {
                $table->foreignId('job_id')->nullable()->constrained('hr_jobs')->nullOnDelete();
            }

            if (! Schema::hasColumn('hr_employees', 'grade_id')) {
                $table->foreignId('grade_id')->nullable()->constrained('hr_grades')->nullOnDelete();
            }

            if (! Schema::hasColumn('hr_employees', 'job_level_id')) {
                $table->foreignId('job_level_id')->nullable()->constrained('hr_job_levels')->nullOnDelete();
            }

            if (! Schema::hasColumn('hr_employees', 'cost_center_id')) {
                $table->foreignId('cost_center_id')->nullable()->constrained('hr_cost_centers')->nullOnDelete();
            }

            if (! Schema::hasColumn('hr_employees', 'work_location_id')) {
                $table->foreignId('work_location_id')->nullable()->constrained('hr_work_locations')->nullOnDelete();
            }
        });
    }

    /**
     * @return list<string>
     */
    private function requiredReferenceTables(): array
    {
        return [
            'hr_org_units',
            'hr_departments',
            'hr_sections',
            'hr_positions',
            'hr_jobs',
            'hr_grades',
            'hr_job_levels',
            'hr_cost_centers',
            'hr_work_locations',
        ];
    }

    public function down(): void
    {
        if (! Schema::hasTable('hr_employees')) {
            return;
        }

        Schema::table('hr_employees', function (Blueprint $table): void {
            foreach ([
                'work_location_id',
                'cost_center_id',
                'job_level_id',
                'grade_id',
                'job_id',
                'position_id',
                'section_id',
                'department_id',
                'org_unit_id',
            ] as $column) {
                if (Schema::hasColumn('hr_employees', $column)) {
                    $table->dropConstrainedForeignId($column);
                }
            }
        });
    }
};
