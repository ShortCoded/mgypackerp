<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->employeeCoreExtensions();
        $this->timeAndAttendance();
        $this->leaveDomain();
        $this->payrollDomain();
        $this->recruitmentDomain();
        $this->performanceDomain();
        $this->employeeSelfServiceRequests();
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_ess_letter_requests');
        Schema::dropIfExists('hr_ess_asset_requests');
        Schema::dropIfExists('hr_ess_loan_requests');
        Schema::dropIfExists('hr_ess_profile_update_requests');
        Schema::dropIfExists('hr_ess_attendance_requests');
        Schema::dropIfExists('hr_development_plans');
        Schema::dropIfExists('hr_performance_review_scores');
        Schema::dropIfExists('hr_performance_reviews');
        Schema::dropIfExists('hr_rating_scales');
        Schema::dropIfExists('hr_appraisal_cycles');
        Schema::dropIfExists('hr_goals');
        Schema::dropIfExists('hr_kpi_definitions');
        Schema::dropIfExists('hr_pre_employment_checks');
        Schema::dropIfExists('hr_job_offers');
        Schema::dropIfExists('hr_interview_evaluations');
        Schema::dropIfExists('hr_interviews');
        Schema::dropIfExists('hr_applications');
        Schema::dropIfExists('hr_applicants');
        Schema::dropIfExists('hr_vacancies');
        Schema::dropIfExists('hr_job_requisitions');
        Schema::dropIfExists('hr_final_settlements');
        Schema::dropIfExists('hr_salary_advances');
        Schema::dropIfExists('hr_employee_loans');
        Schema::dropIfExists('hr_employee_salary_assignments');
        Schema::dropIfExists('hr_payroll_postings');
        Schema::dropIfExists('hr_payroll_attendance_inputs');
        Schema::dropIfExists('hr_payroll_inputs');
        Schema::dropIfExists('hr_payslip_items');
        Schema::dropIfExists('hr_payslips');
        Schema::dropIfExists('hr_payroll_run_employees');
        Schema::dropIfExists('hr_payroll_runs');
        Schema::dropIfExists('hr_payroll_periods');
        Schema::dropIfExists('hr_leave_encashment_requests');
        Schema::dropIfExists('hr_leave_request_days');
        Schema::dropIfExists('hr_leave_requests');
        Schema::dropIfExists('hr_leave_balance_ledger');
        Schema::dropIfExists('hr_leave_balances');
        Schema::dropIfExists('hr_employee_leave_policies');
        Schema::dropIfExists('hr_leave_carry_forward_rules');
        Schema::dropIfExists('hr_leave_accrual_rules');
        Schema::dropIfExists('hr_leave_policies');
        Schema::dropIfExists('hr_leave_blackout_dates');
        Schema::dropIfExists('hr_remote_work_requests');
        Schema::dropIfExists('hr_overtime_requests');
        Schema::dropIfExists('hr_attendance_adjustment_requests');
        Schema::dropIfExists('hr_attendance_exceptions');
        Schema::dropIfExists('hr_attendance_daily_records');
        Schema::dropIfExists('hr_attendance_raw_logs');
        Schema::dropIfExists('hr_biometric_devices');
        Schema::dropIfExists('hr_work_calendar_assignments');
        Schema::dropIfExists('hr_employee_shift_assignments');
        Schema::dropIfExists('hr_employee_lifecycle_events');
        Schema::dropIfExists('hr_employee_insurance_policies');
        Schema::dropIfExists('hr_employee_bank_accounts');
        Schema::dropIfExists('hr_employee_assets');
        Schema::dropIfExists('hr_employee_medical_records');
        Schema::dropIfExists('hr_employee_certifications');
        Schema::dropIfExists('hr_employee_skills');
        Schema::dropIfExists('hr_employee_experiences');
        Schema::dropIfExists('hr_employee_educations');
        Schema::dropIfExists('hr_employee_dependents');
        Schema::dropIfExists('hr_employee_emergency_contacts');
        Schema::dropIfExists('hr_employee_contacts');
        Schema::dropIfExists('hr_employee_probations');
        Schema::dropIfExists('hr_employee_contracts');
        Schema::dropIfExists('hr_employee_assignments');
    }

    private function employeeCoreExtensions(): void
    {
        if (! Schema::hasTable('hr_employee_assignments')) {
            Schema::create('hr_employee_assignments', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
                $table->foreignId('org_unit_id')->nullable()->constrained('hr_org_units')->nullOnDelete();
                $table->foreignId('position_id')->nullable()->constrained('hr_positions')->nullOnDelete();
                $table->foreignId('cost_center_id')->nullable()->constrained('hr_cost_centers')->nullOnDelete();
                $table->foreignId('work_location_id')->nullable()->constrained('hr_work_locations')->nullOnDelete();
                $table->foreignId('manager_employee_id')->nullable()->constrained('hr_employees')->nullOnDelete();
                $table->foreignId('reports_to_assignment_id')->nullable()->constrained('hr_employee_assignments')->nullOnDelete();
                $table->date('effective_from')->index();
                $table->date('effective_to')->nullable()->index();
                $table->boolean('is_primary')->default(false)->index();
                $table->string('assignment_status', 30)->default('active')->index();
                $table->text('notes')->nullable();
                $this->auditColumns($table);
            });
        }

        if (! Schema::hasTable('hr_employee_contracts')) {
            Schema::create('hr_employee_contracts', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
                $table->foreignId('contract_type_id')->nullable()->constrained('hr_contract_types')->nullOnDelete();
                $table->foreignId('employment_type_id')->nullable()->constrained('hr_employment_types')->nullOnDelete();
                $table->foreignId('workflow_instance_id')->nullable()->constrained('workflow_instances')->nullOnDelete();
                $table->date('start_date');
                $table->date('end_date')->nullable();
                $table->string('status', 30)->default('draft')->index();
                $table->text('notes')->nullable();
                $this->auditColumns($table);
            });
        }

        if (! Schema::hasTable('hr_employee_probations')) {
            Schema::create('hr_employee_probations', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
                $table->foreignId('contract_id')->nullable()->constrained('hr_employee_contracts')->nullOnDelete();
                $table->date('start_date');
                $table->date('end_date')->nullable();
                $table->string('outcome', 30)->nullable()->index();
                $table->text('review_notes')->nullable();
                $this->auditColumns($table);
            });
        }

        foreach ([
            'hr_employee_contacts' => function (Blueprint $table): void {
                $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
                $table->string('contact_type', 40)->index();
                $table->string('value');
                $table->boolean('is_primary')->default(false);
            },
            'hr_employee_emergency_contacts' => function (Blueprint $table): void {
                $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
                $table->string('name');
                $table->string('relation', 80)->nullable();
                $table->string('phone', 80)->nullable();
                $table->string('email')->nullable();
            },
            'hr_employee_dependents' => function (Blueprint $table): void {
                $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
                $table->string('full_name');
                $table->date('birth_date')->nullable();
                $table->string('relation', 80)->nullable();
            },
            'hr_employee_educations' => function (Blueprint $table): void {
                $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
                $table->string('institution')->nullable();
                $table->string('degree', 120)->nullable();
                $table->unsignedSmallInteger('year_from')->nullable();
                $table->unsignedSmallInteger('year_to')->nullable();
            },
            'hr_employee_experiences' => function (Blueprint $table): void {
                $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
                $table->string('employer')->nullable();
                $table->string('job_title')->nullable();
                $table->date('start_date')->nullable();
                $table->date('end_date')->nullable();
            },
        ] as $tableName => $callback) {
            if (! Schema::hasTable($tableName)) {
                Schema::create($tableName, function (Blueprint $table) use ($callback): void {
                    $table->id();
                    $callback($table);
                    $this->auditColumns($table);
                });
            }
        }

        if (! Schema::hasTable('hr_employee_skills')) {
            Schema::create('hr_employee_skills', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
                $table->foreignId('skill_definition_id')->constrained('hr_skill_definitions')->cascadeOnDelete();
                $table->unsignedTinyInteger('level')->nullable();
                $table->text('notes')->nullable();
                $this->auditColumns($table);
            });
        }

        if (! Schema::hasTable('hr_employee_certifications')) {
            Schema::create('hr_employee_certifications', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
                $table->foreignId('certification_definition_id')->constrained('hr_certification_definitions')->cascadeOnDelete();
                $table->date('issued_on')->nullable();
                $table->date('expires_on')->nullable();
                $table->string('credential_id', 120)->nullable();
                $this->auditColumns($table);
            });
        }

        if (! Schema::hasTable('hr_employee_medical_records')) {
            Schema::create('hr_employee_medical_records', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
                $table->date('record_date')->nullable();
                $table->string('record_type', 60)->index();
                $table->text('summary')->nullable();
                $table->text('notes')->nullable();
                $this->auditColumns($table);
            });
        }

        if (! Schema::hasTable('hr_employee_assets')) {
            Schema::create('hr_employee_assets', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
                $table->foreignId('asset_type_id')->nullable()->constrained('hr_asset_types')->nullOnDelete();
                $table->string('asset_tag', 120)->nullable()->index();
                $table->string('status', 30)->default('assigned')->index();
                $table->date('assigned_on')->nullable();
                $table->date('returned_on')->nullable();
                $this->auditColumns($table);
            });
        }

        if (! Schema::hasTable('hr_employee_bank_accounts')) {
            Schema::create('hr_employee_bank_accounts', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
                $table->foreignId('bank_id')->nullable()->constrained('hr_banks')->nullOnDelete();
                $table->string('iban', 64)->nullable()->index();
                $table->string('account_number', 64)->nullable();
                $table->boolean('is_salary_account')->default(false)->index();
                $this->auditColumns($table);
            });
        }

        if (! Schema::hasTable('hr_employee_insurance_policies')) {
            Schema::create('hr_employee_insurance_policies', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
                $table->foreignId('insurance_id')->nullable()->constrained('hr_insurances')->nullOnDelete();
                $table->string('policy_number', 120)->nullable();
                $table->date('coverage_start')->nullable();
                $table->date('coverage_end')->nullable();
                $this->auditColumns($table);
            });
        }

        if (! Schema::hasTable('hr_employee_lifecycle_events')) {
            Schema::create('hr_employee_lifecycle_events', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
                $table->string('event_type', 80)->index();
                $table->string('status', 40)->default('draft')->index();
                $table->date('effective_date')->nullable()->index();
                $table->foreignId('workflow_instance_id')->nullable()->constrained('workflow_instances')->nullOnDelete();
                $table->json('payload')->nullable();
                $this->auditColumns($table);
            });
        }
    }

    private function timeAndAttendance(): void
    {
        if (! Schema::hasTable('hr_employee_shift_assignments')) {
            Schema::create('hr_employee_shift_assignments', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
                $table->foreignId('shift_id')->constrained('hr_shifts')->cascadeOnDelete();
                $table->date('effective_from')->index();
                $table->date('effective_to')->nullable();
                $this->auditColumns($table);
            });
        }

        if (! Schema::hasTable('hr_work_calendar_assignments')) {
            Schema::create('hr_work_calendar_assignments', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
                $table->foreignId('calendar_id')->constrained('hr_work_calendars')->cascadeOnDelete();
                $table->date('effective_from')->index();
                $table->date('effective_to')->nullable();
                $this->auditColumns($table);
            });
        }

        if (! Schema::hasTable('hr_biometric_devices')) {
            Schema::create('hr_biometric_devices', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
                $table->string('device_uid', 120)->unique();
                $table->string('name')->nullable();
                $table->string('status', 30)->default('active')->index();
                $this->auditColumns($table);
            });
        }

        if (! Schema::hasTable('hr_attendance_raw_logs')) {
            Schema::create('hr_attendance_raw_logs', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('employee_id')->nullable()->constrained('hr_employees')->nullOnDelete();
                $table->foreignId('device_id')->nullable()->constrained('hr_biometric_devices')->nullOnDelete();
                $table->timestamp('punched_at')->index();
                $table->string('punch_type', 20)->index();
                $table->string('source', 40)->default('device')->index();
                $table->json('raw_payload')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('hr_attendance_daily_records')) {
            Schema::create('hr_attendance_daily_records', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
                $table->date('work_date')->index();
                $table->timestamp('check_in_at')->nullable();
                $table->timestamp('check_out_at')->nullable();
                $table->unsignedSmallInteger('late_minutes')->default(0);
                $table->unsignedSmallInteger('early_leave_minutes')->default(0);
                $table->unsignedSmallInteger('overtime_minutes')->default(0);
                $table->string('status', 40)->default('present')->index();
                $table->timestamps();
                $table->unique(['employee_id', 'work_date']);
            });
        }

        if (! Schema::hasTable('hr_attendance_exceptions')) {
            Schema::create('hr_attendance_exceptions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
                $table->date('work_date')->index();
                $table->string('exception_type', 60)->index();
                $table->text('notes')->nullable();
                $this->auditColumns($table);
            });
        }

        if (! Schema::hasTable('hr_attendance_adjustment_requests')) {
            Schema::create('hr_attendance_adjustment_requests', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
                $table->foreignId('workflow_instance_id')->nullable()->constrained('workflow_instances')->nullOnDelete();
                $table->date('work_date')->index();
                $table->text('reason');
                $table->string('status', 40)->default('draft')->index();
                $this->auditColumns($table);
            });
        }

        if (! Schema::hasTable('hr_overtime_requests')) {
            Schema::create('hr_overtime_requests', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
                $table->foreignId('overtime_type_id')->nullable()->constrained('hr_overtime_types')->nullOnDelete();
                $table->foreignId('workflow_instance_id')->nullable()->constrained('workflow_instances')->nullOnDelete();
                $table->date('work_date')->index();
                $table->unsignedSmallInteger('minutes')->default(0);
                $table->string('status', 40)->default('draft')->index();
                $this->auditColumns($table);
            });
        }

        if (! Schema::hasTable('hr_remote_work_requests')) {
            Schema::create('hr_remote_work_requests', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
                $table->foreignId('workflow_instance_id')->nullable()->constrained('workflow_instances')->nullOnDelete();
                $table->date('start_date')->index();
                $table->date('end_date')->index();
                $table->string('status', 40)->default('draft')->index();
                $this->auditColumns($table);
            });
        }
    }

    private function leaveDomain(): void
    {
        if (! Schema::hasTable('hr_leave_policies')) {
            Schema::create('hr_leave_policies', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('leave_type_id')->constrained('hr_leave_types')->cascadeOnDelete();
                $table->string('name')->index();
                $table->string('status', 30)->default('active')->index();
                $table->text('notes')->nullable();
                $this->auditColumns($table);
            });
        }

        if (! Schema::hasTable('hr_leave_accrual_rules')) {
            Schema::create('hr_leave_accrual_rules', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('leave_policy_id')->constrained('hr_leave_policies')->cascadeOnDelete();
                $table->string('frequency', 30)->index();
                $table->decimal('amount', 12, 4)->default(0);
                $table->json('metadata')->nullable();
                $this->auditColumns($table);
            });
        }

        if (! Schema::hasTable('hr_leave_carry_forward_rules')) {
            Schema::create('hr_leave_carry_forward_rules', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('leave_policy_id')->constrained('hr_leave_policies')->cascadeOnDelete();
                $table->unsignedSmallInteger('max_days')->default(0);
                $table->date('effective_from')->nullable();
                $table->date('effective_to')->nullable();
                $this->auditColumns($table);
            });
        }

        if (! Schema::hasTable('hr_employee_leave_policies')) {
            Schema::create('hr_employee_leave_policies', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
                $table->foreignId('leave_policy_id')->constrained('hr_leave_policies')->cascadeOnDelete();
                $table->date('effective_from')->index();
                $table->date('effective_to')->nullable();
                $this->auditColumns($table);
            });
        }

        if (! Schema::hasTable('hr_leave_balances')) {
            Schema::create('hr_leave_balances', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
                $table->foreignId('leave_type_id')->constrained('hr_leave_types')->cascadeOnDelete();
                $table->unsignedSmallInteger('balance_year')->index();
                $table->decimal('opening_balance', 12, 4)->default(0);
                $table->decimal('current_balance', 12, 4)->default(0);
                $table->timestamps();
                $table->unique(['employee_id', 'leave_type_id', 'balance_year']);
            });
        }

        if (! Schema::hasTable('hr_leave_balance_ledger')) {
            Schema::create('hr_leave_balance_ledger', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('leave_balance_id')->constrained('hr_leave_balances')->cascadeOnDelete();
                $table->string('entry_type', 40)->index();
                $table->decimal('amount', 12, 4);
                $table->text('notes')->nullable();
                $table->timestamp('posted_at')->useCurrent();
            });
        }

        if (! Schema::hasTable('hr_leave_requests')) {
            Schema::create('hr_leave_requests', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
                $table->foreignId('leave_type_id')->constrained('hr_leave_types')->restrictOnDelete();
                $table->foreignId('workflow_instance_id')->nullable()->constrained('workflow_instances')->nullOnDelete();
                $table->string('status', 40)->default('draft')->index();
                $table->text('reason')->nullable();
                $this->auditColumns($table);
            });
        }

        if (! Schema::hasTable('hr_leave_request_days')) {
            Schema::create('hr_leave_request_days', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('leave_request_id')->constrained('hr_leave_requests')->cascadeOnDelete();
                $table->date('leave_date')->index();
                $table->decimal('day_fraction', 4, 3)->default(1);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('hr_leave_encashment_requests')) {
            Schema::create('hr_leave_encashment_requests', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
                $table->foreignId('leave_type_id')->constrained('hr_leave_types')->restrictOnDelete();
                $table->foreignId('workflow_instance_id')->nullable()->constrained('workflow_instances')->nullOnDelete();
                $table->decimal('days', 8, 3);
                $table->string('status', 40)->default('draft')->index();
                $this->auditColumns($table);
            });
        }

        if (! Schema::hasTable('hr_leave_blackout_dates')) {
            Schema::create('hr_leave_blackout_dates', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
                $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
                $table->date('start_date')->index();
                $table->date('end_date')->index();
                $table->string('reason')->nullable();
                $this->auditColumns($table);
            });
        }
    }

    private function payrollDomain(): void
    {
        if (! Schema::hasTable('hr_payroll_periods')) {
            Schema::create('hr_payroll_periods', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
                $table->date('period_start')->index();
                $table->date('period_end')->index();
                $table->string('status', 30)->default('open')->index();
                $this->auditColumns($table);
            });
        }

        if (! Schema::hasTable('hr_payroll_runs')) {
            Schema::create('hr_payroll_runs', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('payroll_period_id')->constrained('hr_payroll_periods')->cascadeOnDelete();
                $table->foreignId('workflow_instance_id')->nullable()->constrained('workflow_instances')->nullOnDelete();
                $table->string('status', 40)->default('draft')->index();
                $table->timestamp('posted_at')->nullable();
                $this->auditColumns($table);
            });
        }

        if (! Schema::hasTable('hr_payroll_run_employees')) {
            Schema::create('hr_payroll_run_employees', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('payroll_run_id')->constrained('hr_payroll_runs')->cascadeOnDelete();
                $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
                $table->string('status', 40)->default('pending')->index();
                $table->timestamps();
                $table->unique(['payroll_run_id', 'employee_id']);
            });
        }

        if (! Schema::hasTable('hr_payslips')) {
            Schema::create('hr_payslips', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('payroll_run_id')->constrained('hr_payroll_runs')->cascadeOnDelete();
                $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
                $table->string('status', 40)->default('draft')->index();
                $table->timestamps();
                $table->unique(['payroll_run_id', 'employee_id']);
            });
        }

        if (! Schema::hasTable('hr_payslip_items')) {
            Schema::create('hr_payslip_items', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('payslip_id')->constrained('hr_payslips')->cascadeOnDelete();
                $table->foreignId('payroll_item_id')->nullable()->constrained('hr_payroll_items')->nullOnDelete();
                $table->decimal('amount', 15, 2)->default(0);
                $table->string('direction', 10)->default('earning')->index();
                $table->timestamps();
            });
        }

        foreach (['hr_payroll_inputs', 'hr_payroll_attendance_inputs'] as $tableName) {
            if (! Schema::hasTable($tableName)) {
                Schema::create($tableName, function (Blueprint $table): void {
                    $table->id();
                    $table->foreignId('payroll_run_id')->constrained('hr_payroll_runs')->cascadeOnDelete();
                    $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
                    $table->json('payload');
                    $table->timestamps();
                });
            }
        }

        if (! Schema::hasTable('hr_payroll_postings')) {
            Schema::create('hr_payroll_postings', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('payroll_run_id')->constrained('hr_payroll_runs')->cascadeOnDelete();
                $table->string('posting_reference', 120)->nullable()->index();
                $table->string('status', 40)->default('pending')->index();
                $table->json('gl_lines')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('hr_employee_salary_assignments')) {
            Schema::create('hr_employee_salary_assignments', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
                $table->date('effective_from')->index();
                $table->date('effective_to')->nullable();
                $table->decimal('basic_salary', 15, 2)->default(0);
                $table->json('components')->nullable();
                $this->auditColumns($table);
            });
        }

        foreach (['hr_employee_loans', 'hr_salary_advances'] as $tableName) {
            if (! Schema::hasTable($tableName)) {
                Schema::create($tableName, function (Blueprint $table): void {
                    $table->id();
                    $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
                    $table->decimal('principal', 15, 2)->default(0);
                    $table->decimal('balance', 15, 2)->default(0);
                    $table->string('status', 40)->default('active')->index();
                    $this->auditColumns($table);
                });
            }
        }

        if (! Schema::hasTable('hr_final_settlements')) {
            Schema::create('hr_final_settlements', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
                $table->foreignId('workflow_instance_id')->nullable()->constrained('workflow_instances')->nullOnDelete();
                $table->date('last_working_day')->nullable();
                $table->decimal('net_amount', 15, 2)->nullable();
                $table->string('status', 40)->default('draft')->index();
                $this->auditColumns($table);
            });
        }
    }

    private function recruitmentDomain(): void
    {
        if (! Schema::hasTable('hr_job_requisitions')) {
            Schema::create('hr_job_requisitions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('org_unit_id')->nullable()->constrained('hr_org_units')->nullOnDelete();
                $table->foreignId('position_id')->nullable()->constrained('hr_positions')->nullOnDelete();
                $table->foreignId('workflow_instance_id')->nullable()->constrained('workflow_instances')->nullOnDelete();
                $table->unsignedSmallInteger('headcount')->default(1);
                $table->string('status', 40)->default('draft')->index();
                $this->auditColumns($table);
            });
        }

        if (! Schema::hasTable('hr_vacancies')) {
            Schema::create('hr_vacancies', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('job_requisition_id')->nullable()->constrained('hr_job_requisitions')->nullOnDelete();
                $table->string('title')->index();
                $table->string('status', 40)->default('open')->index();
                $this->auditColumns($table);
            });
        }

        if (! Schema::hasTable('hr_applicants')) {
            Schema::create('hr_applicants', function (Blueprint $table): void {
                $table->id();
                $table->string('full_name')->index();
                $table->string('email')->nullable()->index();
                $table->string('phone', 80)->nullable();
                $this->auditColumns($table);
            });
        }

        if (! Schema::hasTable('hr_applications')) {
            Schema::create('hr_applications', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('vacancy_id')->constrained('hr_vacancies')->cascadeOnDelete();
                $table->foreignId('applicant_id')->constrained('hr_applicants')->cascadeOnDelete();
                $table->foreignId('workflow_instance_id')->nullable()->constrained('workflow_instances')->nullOnDelete();
                $table->string('status', 40)->default('applied')->index();
                $this->auditColumns($table);
            });
        }

        if (! Schema::hasTable('hr_recruitment_pipeline_stages')) {
            Schema::create('hr_recruitment_pipeline_stages', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('vacancy_id')->constrained('hr_vacancies')->cascadeOnDelete();
                $table->unsignedSmallInteger('sequence')->default(0);
                $table->string('name');
                $table->timestamps();
            });
        }

        foreach (['hr_interviews', 'hr_interview_evaluations', 'hr_job_offers', 'hr_pre_employment_checks'] as $tableName) {
            if (! Schema::hasTable($tableName)) {
                Schema::create($tableName, function (Blueprint $table) use ($tableName): void {
                    $table->id();
                    $table->foreignId('application_id')->constrained('hr_applications')->cascadeOnDelete();
                    if ($tableName === 'hr_job_offers') {
                        $table->foreignId('workflow_instance_id')->nullable()->constrained('workflow_instances')->nullOnDelete();
                    }
                    $table->string('status', 40)->default('scheduled')->index();
                    $table->json('payload')->nullable();
                    $this->auditColumns($table);
                });
            }
        }
    }

    private function performanceDomain(): void
    {
        if (! Schema::hasTable('hr_kpi_definitions')) {
            Schema::create('hr_kpi_definitions', function (Blueprint $table): void {
                $table->id();
                $table->string('code', 80)->nullable()->index();
                $table->string('name')->index();
                $table->text('description')->nullable();
                $this->auditColumns($table);
            });
        }

        if (! Schema::hasTable('hr_goals')) {
            Schema::create('hr_goals', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
                $table->foreignId('kpi_definition_id')->nullable()->constrained('hr_kpi_definitions')->nullOnDelete();
                $table->string('title');
                $table->string('status', 40)->default('active')->index();
                $this->auditColumns($table);
            });
        }

        if (! Schema::hasTable('hr_appraisal_cycles')) {
            Schema::create('hr_appraisal_cycles', function (Blueprint $table): void {
                $table->id();
                $table->string('name')->index();
                $table->date('start_date')->nullable();
                $table->date('end_date')->nullable();
                $table->string('status', 40)->default('draft')->index();
                $this->auditColumns($table);
            });
        }

        if (! Schema::hasTable('hr_rating_scales')) {
            Schema::create('hr_rating_scales', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('appraisal_cycle_id')->constrained('hr_appraisal_cycles')->cascadeOnDelete();
                $table->string('name');
                $table->json('levels')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('hr_performance_reviews')) {
            Schema::create('hr_performance_reviews', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
                $table->foreignId('appraisal_cycle_id')->constrained('hr_appraisal_cycles')->cascadeOnDelete();
                $table->foreignId('workflow_instance_id')->nullable()->constrained('workflow_instances')->nullOnDelete();
                $table->string('status', 40)->default('draft')->index();
                $this->auditColumns($table);
            });
        }

        if (! Schema::hasTable('hr_performance_review_scores')) {
            Schema::create('hr_performance_review_scores', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('performance_review_id')->constrained('hr_performance_reviews')->cascadeOnDelete();
                $table->foreignId('kpi_definition_id')->nullable()->constrained('hr_kpi_definitions')->nullOnDelete();
                $table->decimal('score', 8, 2)->nullable();
                $table->text('comment')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('hr_development_plans')) {
            Schema::create('hr_development_plans', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
                $table->foreignId('performance_review_id')->nullable()->constrained('hr_performance_reviews')->nullOnDelete();
                $table->string('title');
                $table->string('status', 40)->default('active')->index();
                $this->auditColumns($table);
            });
        }
    }

    private function employeeSelfServiceRequests(): void
    {
        foreach ([
            'hr_ess_attendance_requests' => 'Attendance change',
            'hr_ess_profile_update_requests' => 'Profile update',
            'hr_ess_loan_requests' => 'Loan',
            'hr_ess_asset_requests' => 'Asset',
            'hr_ess_letter_requests' => 'Letter',
        ] as $tableName => $_label) {
            if (! Schema::hasTable($tableName)) {
                Schema::create($tableName, function (Blueprint $table): void {
                    $table->id();
                    $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
                    $table->foreignId('workflow_instance_id')->nullable()->constrained('workflow_instances')->nullOnDelete();
                    $table->string('status', 40)->default('draft')->index();
                    $table->json('payload')->nullable();
                    $this->auditColumns($table);
                });
            }
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
