<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->masterTables();
        $this->organizationTables();
        $this->workflowEngineTables();
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_history');
        Schema::dropIfExists('workflow_actions');
        Schema::dropIfExists('workflow_tasks');
        Schema::dropIfExists('workflow_instances');
        Schema::dropIfExists('workflow_escalation_rules');
        Schema::dropIfExists('workflow_conditions');
        Schema::dropIfExists('workflow_step_approvers');
        Schema::dropIfExists('workflow_steps');
        Schema::dropIfExists('workflow_versions');
        Schema::dropIfExists('workflow_definitions');
        Schema::dropIfExists('hr_org_units');
        Schema::dropIfExists('hr_positions');
        Schema::dropIfExists('hr_work_locations');
        Schema::dropIfExists('hr_job_levels');
        Schema::dropIfExists('hr_grades');
        Schema::dropIfExists('hr_jobs');
        Schema::dropIfExists('hr_certification_definitions');
        Schema::dropIfExists('hr_skill_definitions');
        Schema::dropIfExists('hr_shifts');
        Schema::dropIfExists('hr_work_calendar_days');
        Schema::dropIfExists('hr_work_calendars');
        Schema::dropIfExists('hr_payroll_items');
        Schema::dropIfExists('hr_overtime_types');
        Schema::dropIfExists('hr_penalty_types');
        Schema::dropIfExists('hr_leave_types');
        Schema::dropIfExists('hr_document_requirements');
        Schema::dropIfExists('hr_asset_types');
        Schema::dropIfExists('hr_employee_categories');
        Schema::dropIfExists('hr_employment_types');
        Schema::dropIfExists('hr_contract_types');
        Schema::dropIfExists('hr_cost_centers');
        Schema::dropIfExists('hr_banks');
        Schema::dropIfExists('hr_org_unit_types');
    }

    private function masterTables(): void
    {
        if (! Schema::hasTable('hr_org_unit_types')) {
            Schema::create('hr_org_unit_types', function (Blueprint $table): void {
                $table->id();
                $table->string('code', 80)->nullable()->index();
                $table->string('name')->index();
                $table->string('category', 40)->index();
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->string('status', 30)->default('active')->index();
                $table->text('notes')->nullable();
                $this->auditColumns($table);
            });
        }

        if (! Schema::hasTable('hr_banks')) {
            Schema::create('hr_banks', function (Blueprint $table): void {
                $table->id();
                $table->string('code', 80)->nullable()->index();
                $table->string('name')->index();
                $table->string('swift', 40)->nullable()->index();
                $table->string('iban_prefix', 10)->nullable();
                $table->string('status', 30)->default('active')->index();
                $table->text('notes')->nullable();
                $this->auditColumns($table);
            });
        }

        if (! Schema::hasTable('hr_cost_centers')) {
            Schema::create('hr_cost_centers', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
                $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
                $table->string('code', 80)->index();
                $table->string('name')->index();
                $table->string('status', 30)->default('active')->index();
                $table->text('notes')->nullable();
                $this->auditColumns($table);
            });
        }

        foreach (['hr_contract_types', 'hr_employment_types', 'hr_employee_categories', 'hr_asset_types'] as $tableName) {
            if (! Schema::hasTable($tableName)) {
                Schema::create($tableName, function (Blueprint $table): void {
                    $table->id();
                    $table->string('code', 80)->nullable()->index();
                    $table->string('name')->index();
                    $table->string('status', 30)->default('active')->index();
                    $table->text('notes')->nullable();
                    $this->auditColumns($table);
                });
            }
        }

        if (! Schema::hasTable('hr_document_requirements')) {
            Schema::create('hr_document_requirements', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('employment_type_id')->nullable()->constrained('hr_employment_types')->nullOnDelete();
                $table->foreignId('contract_type_id')->nullable()->constrained('hr_contract_types')->nullOnDelete();
                $table->string('document_code', 80)->index();
                $table->string('title');
                $table->boolean('is_mandatory')->default(true);
                $table->unsignedSmallInteger('grace_days_after_hire')->nullable();
                $table->string('status', 30)->default('active')->index();
                $table->text('notes')->nullable();
                $this->auditColumns($table);
            });
        }

        foreach (['hr_leave_types', 'hr_penalty_types', 'hr_overtime_types'] as $tableName) {
            if (! Schema::hasTable($tableName)) {
                Schema::create($tableName, function (Blueprint $table): void {
                    $table->id();
                    $table->string('code', 80)->nullable()->index();
                    $table->string('name')->index();
                    $table->string('status', 30)->default('active')->index();
                    $table->json('metadata')->nullable();
                    $table->text('notes')->nullable();
                    $this->auditColumns($table);
                });
            }
        }

        if (! Schema::hasTable('hr_payroll_items')) {
            Schema::create('hr_payroll_items', function (Blueprint $table): void {
                $table->id();
                $table->string('code', 80)->nullable()->index();
                $table->string('name')->index();
                $table->string('item_kind', 30)->index();
                $table->boolean('is_taxable')->default(false);
                $table->boolean('is_system')->default(false);
                $table->string('status', 30)->default('active')->index();
                $table->text('notes')->nullable();
                $this->auditColumns($table);
            });
        }

        if (! Schema::hasTable('hr_work_calendars')) {
            Schema::create('hr_work_calendars', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
                $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
                $table->string('code', 80)->nullable()->index();
                $table->string('name')->index();
                $table->string('status', 30)->default('active')->index();
                $table->text('notes')->nullable();
                $this->auditColumns($table);
            });
        }

        if (! Schema::hasTable('hr_work_calendar_days')) {
            Schema::create('hr_work_calendar_days', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('calendar_id')->constrained('hr_work_calendars')->cascadeOnDelete();
                $table->date('work_date')->index();
                $table->string('day_type', 30)->index();
                $table->string('label')->nullable();
                $table->timestamps();
                $table->unique(['calendar_id', 'work_date']);
            });
        }

        if (! Schema::hasTable('hr_shifts')) {
            Schema::create('hr_shifts', function (Blueprint $table): void {
                $table->id();
                $table->string('code', 80)->nullable()->index();
                $table->string('name')->index();
                $table->time('start_time')->nullable();
                $table->time('end_time')->nullable();
                $table->unsignedSmallInteger('break_minutes')->default(0);
                $table->boolean('crosses_midnight')->default(false);
                $table->string('status', 30)->default('active')->index();
                $table->text('notes')->nullable();
                $this->auditColumns($table);
            });
        }

        foreach (['hr_skill_definitions', 'hr_certification_definitions'] as $tableName) {
            if (! Schema::hasTable($tableName)) {
                Schema::create($tableName, function (Blueprint $table): void {
                    $table->id();
                    $table->string('code', 80)->nullable()->index();
                    $table->string('name')->index();
                    $table->string('status', 30)->default('active')->index();
                    $table->text('notes')->nullable();
                    $this->auditColumns($table);
                });
            }
        }

        if (! Schema::hasTable('hr_jobs')) {
            Schema::create('hr_jobs', function (Blueprint $table): void {
                $table->id();
                $table->string('code', 80)->nullable()->index();
                $table->string('name')->index();
                $table->text('description')->nullable();
                $table->string('status', 30)->default('active')->index();
                $table->text('notes')->nullable();
                $this->auditColumns($table);
            });
        }

        if (! Schema::hasTable('hr_grades')) {
            Schema::create('hr_grades', function (Blueprint $table): void {
                $table->id();
                $table->string('code', 80)->nullable()->index();
                $table->string('name')->index();
                $table->unsignedSmallInteger('rank')->default(0)->index();
                $table->string('status', 30)->default('active')->index();
                $table->text('notes')->nullable();
                $this->auditColumns($table);
            });
        }

        if (! Schema::hasTable('hr_job_levels')) {
            Schema::create('hr_job_levels', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('grade_id')->nullable()->constrained('hr_grades')->nullOnDelete();
                $table->string('code', 80)->nullable()->index();
                $table->string('name')->index();
                $table->unsignedSmallInteger('rank')->default(0)->index();
                $table->string('status', 30)->default('active')->index();
                $table->text('notes')->nullable();
                $this->auditColumns($table);
            });
        }
    }

    private function organizationTables(): void
    {
        if (! Schema::hasTable('hr_work_locations')) {
            Schema::create('hr_work_locations', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
                $table->string('code', 80)->nullable()->index();
                $table->string('name')->index();
                $table->string('status', 30)->default('active')->index();
                $table->text('notes')->nullable();
                $this->auditColumns($table);
            });
        }

        if (! Schema::hasTable('hr_positions')) {
            Schema::create('hr_positions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
                $table->foreignId('job_id')->nullable()->constrained('hr_jobs')->nullOnDelete();
                $table->foreignId('grade_id')->nullable()->constrained('hr_grades')->nullOnDelete();
                $table->foreignId('job_level_id')->nullable()->constrained('hr_job_levels')->nullOnDelete();
                $table->foreignId('reports_to_position_id')->nullable()->constrained('hr_positions')->nullOnDelete();
                $table->string('code', 80)->nullable()->index();
                $table->string('name')->index();
                $table->string('status', 30)->default('active')->index();
                $table->text('notes')->nullable();
                $this->auditColumns($table);
            });
        }

        if (! Schema::hasTable('hr_org_units')) {
            Schema::create('hr_org_units', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('parent_id')->nullable()->constrained('hr_org_units')->nullOnDelete();
                $table->foreignId('org_unit_type_id')->constrained('hr_org_unit_types')->restrictOnDelete();
                $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
                $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
                $table->string('code', 80)->nullable()->index();
                $table->string('name')->index();
                $table->unsignedSmallInteger('headcount_budget')->nullable();
                $table->string('status', 30)->default('active')->index();
                $table->text('notes')->nullable();
                $this->auditColumns($table);
            });
        }
    }

    private function workflowEngineTables(): void
    {
        $rolesTable = config('permission.table_names.roles', 'roles');

        if (! Schema::hasTable('workflow_definitions')) {
            Schema::create('workflow_definitions', function (Blueprint $table): void {
                $table->id();
                $table->string('code', 120)->unique();
                $table->string('name')->index();
                $table->string('module', 60)->index();
                $table->string('status', 30)->default('draft')->index();
                $table->text('description')->nullable();
                $this->auditColumns($table);
            });
        }

        if (! Schema::hasTable('workflow_versions')) {
            Schema::create('workflow_versions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('workflow_definition_id')->constrained('workflow_definitions')->cascadeOnDelete();
                $table->unsignedInteger('version')->default(1);
                $table->boolean('is_active')->default(false)->index();
                $table->timestamp('published_at')->nullable();
                $table->timestamps();
                $table->unique(['workflow_definition_id', 'version']);
            });
        }

        if (! Schema::hasTable('workflow_steps')) {
            Schema::create('workflow_steps', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('workflow_version_id')->constrained('workflow_versions')->cascadeOnDelete();
                $table->unsignedSmallInteger('sequence')->index();
                $table->string('name');
                $table->string('approval_mode', 30)->default('serial')->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('workflow_step_approvers')) {
            Schema::create('workflow_step_approvers', function (Blueprint $table) use ($rolesTable): void {
                $table->id();
                $table->foreignId('workflow_step_id')->constrained('workflow_steps')->cascadeOnDelete();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('role_id')->nullable()->constrained($rolesTable)->nullOnDelete();
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('workflow_conditions')) {
            Schema::create('workflow_conditions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('workflow_step_id')->constrained('workflow_steps')->cascadeOnDelete();
                $table->string('field', 120);
                $table->string('operator', 40);
                $table->json('value')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('workflow_escalation_rules')) {
            Schema::create('workflow_escalation_rules', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('workflow_step_id')->constrained('workflow_steps')->cascadeOnDelete();
                $table->unsignedInteger('delay_minutes')->default(0);
                $table->foreignId('escalate_to_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('workflow_instances')) {
            Schema::create('workflow_instances', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('workflow_version_id')->constrained('workflow_versions')->restrictOnDelete();
                $table->string('status', 40)->default('draft')->index();
                $table->foreignId('initiated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('workflow_tasks')) {
            Schema::create('workflow_tasks', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('workflow_instance_id')->constrained('workflow_instances')->cascadeOnDelete();
                $table->foreignId('workflow_step_id')->constrained('workflow_steps')->cascadeOnDelete();
                $table->foreignId('assignee_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('status', 40)->default('pending')->index();
                $table->timestamp('due_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('workflow_actions')) {
            Schema::create('workflow_actions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('workflow_task_id')->constrained('workflow_tasks')->cascadeOnDelete();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('action', 40)->index();
                $table->text('comment')->nullable();
                $table->timestamp('acted_at')->useCurrent();
            });
        }

        if (! Schema::hasTable('workflow_history')) {
            Schema::create('workflow_history', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('workflow_instance_id')->constrained('workflow_instances')->cascadeOnDelete();
                $table->string('event', 120)->index();
                $table->json('payload')->nullable();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('created_at')->useCurrent();
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
