<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_payroll_runs', function (Blueprint $table): void {
            $table->foreignId('branch_id')->nullable()->after('payroll_period_id')->constrained('branches')->nullOnDelete();
            $table->timestamp('calculated_at')->nullable()->after('status');
            $table->timestamp('reviewed_at')->nullable()->after('calculated_at');
            $table->foreignId('reviewed_by')->nullable()->after('reviewed_at')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('reviewed_by');
            $table->foreignId('approved_by')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
            $table->index(['payroll_period_id', 'branch_id', 'status'], 'hr_payroll_runs_period_branch_status_idx');
        });

        Schema::table('hr_payslips', function (Blueprint $table): void {
            $table->foreignId('company_id')->nullable()->after('employee_id')->constrained('companies')->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->after('company_id')->constrained('branches')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->after('branch_id')->constrained('hr_departments')->nullOnDelete();
            $table->foreignId('currency_id')->nullable()->after('department_id')->constrained('currencies')->nullOnDelete();
            $table->string('employee_doc_num')->nullable()->after('currency_id');
            $table->string('employee_name')->nullable()->after('employee_doc_num');
            $table->decimal('gross_amount', 18, 4)->default(0)->after('employee_name');
            $table->decimal('deduction_amount', 18, 4)->default(0)->after('gross_amount');
            $table->decimal('net_amount', 18, 4)->default(0)->after('deduction_amount');
            $table->index(['company_id', 'branch_id', 'status'], 'hr_payslips_company_branch_status_idx');
        });

        Schema::table('hr_payslip_items', function (Blueprint $table): void {
            $table->string('source_type', 50)->nullable()->after('direction');
            $table->unsignedBigInteger('source_id')->nullable()->after('source_type');
            $table->json('source_snapshot')->nullable()->after('source_id');
            $table->index(['source_type', 'source_id'], 'hr_payslip_items_source_idx');
        });

        Schema::table('hr_payroll_inputs', function (Blueprint $table): void {
            $table->unique(['payroll_run_id', 'employee_id'], 'hr_payroll_inputs_run_employee_unique');
        });

        Schema::table('hr_payroll_attendance_inputs', function (Blueprint $table): void {
            $table->unique(['payroll_run_id', 'employee_id'], 'hr_payroll_attendance_run_employee_unique');
        });

        Schema::table('hr_payroll_postings', function (Blueprint $table): void {
            $table->unique('payroll_run_id', 'hr_payroll_postings_run_unique');
        });

        Schema::create('hr_payroll_advance_applications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained('hr_payroll_runs')->cascadeOnDelete();
            $table->foreignId('payslip_id')->constrained('hr_payslips')->cascadeOnDelete();
            $table->foreignId('payslip_item_id')->constrained('hr_payslip_items')->cascadeOnDelete();
            $table->foreignId('salary_advance_id')->constrained('hr_salary_advances')->restrictOnDelete();
            $table->decimal('amount', 18, 4);
            $table->decimal('balance_before', 18, 4)->nullable();
            $table->decimal('balance_after', 18, 4)->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->foreignId('applied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['payroll_run_id', 'salary_advance_id'], 'hr_payroll_advance_run_source_unique');
        });

        Schema::create('hr_payroll_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('financial_period_id')->constrained('financial_periods')->restrictOnDelete();
            $table->foreignId('payroll_run_id')->constrained('hr_payroll_runs')->restrictOnDelete();
            $table->foreignId('cash_voucher_id')->unique()->constrained('cash_vouchers')->restrictOnDelete();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('reversal_journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->decimal('amount', 18, 4);
            $table->string('idempotency_key', 100);
            $table->string('status', 30)->default('draft')->index();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'idempotency_key'], 'hr_payroll_payments_company_idem_unique');
            $table->index(['payroll_run_id', 'status'], 'hr_payroll_payments_run_status_idx');
        });

        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            $grammar = DB::getQueryGrammar();
            DB::statement(
                'CREATE UNIQUE INDEX IF NOT EXISTS '.$grammar->wrap('hr_payroll_periods_company_dates_unique_active')
                .' ON '.$grammar->wrapTable('hr_payroll_periods')
                .' ('.$grammar->wrap('company_id').', '.$grammar->wrap('period_start').', '.$grammar->wrap('period_end').')'
                .' WHERE '.$grammar->wrap('deleted_at').' IS NULL'
            );
            DB::statement(
                'CREATE UNIQUE INDEX IF NOT EXISTS '.$grammar->wrap('hr_payroll_runs_period_branch_unique_active')
                .' ON '.$grammar->wrapTable('hr_payroll_runs')
                .' ('.$grammar->wrap('payroll_period_id').', '.$grammar->wrap('branch_id').')'
                .' WHERE '.$grammar->wrap('deleted_at').' IS NULL AND '.$grammar->wrap('branch_id').' IS NOT NULL'
            );
            DB::statement(
                'CREATE UNIQUE INDEX IF NOT EXISTS '.$grammar->wrap('hr_payroll_runs_period_companywide_unique_active')
                .' ON '.$grammar->wrapTable('hr_payroll_runs')
                .' ('.$grammar->wrap('payroll_period_id').')'
                .' WHERE '.$grammar->wrap('deleted_at').' IS NULL AND '.$grammar->wrap('branch_id').' IS NULL'
            );
        }
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement('DROP INDEX IF EXISTS '.DB::getQueryGrammar()->wrap('hr_payroll_runs_period_companywide_unique_active'));
            DB::statement('DROP INDEX IF EXISTS '.DB::getQueryGrammar()->wrap('hr_payroll_runs_period_branch_unique_active'));
            DB::statement('DROP INDEX IF EXISTS '.DB::getQueryGrammar()->wrap('hr_payroll_periods_company_dates_unique_active'));
        }

        Schema::dropIfExists('hr_payroll_payments');
        Schema::dropIfExists('hr_payroll_advance_applications');

        Schema::table('hr_payroll_postings', function (Blueprint $table): void {
            $table->dropUnique('hr_payroll_postings_run_unique');
        });

        Schema::table('hr_payroll_attendance_inputs', function (Blueprint $table): void {
            $table->dropUnique('hr_payroll_attendance_run_employee_unique');
        });

        Schema::table('hr_payroll_inputs', function (Blueprint $table): void {
            $table->dropUnique('hr_payroll_inputs_run_employee_unique');
        });

        Schema::table('hr_payslip_items', function (Blueprint $table): void {
            $table->dropIndex('hr_payslip_items_source_idx');
            $table->dropColumn(['source_type', 'source_id', 'source_snapshot']);
        });

        Schema::table('hr_payslips', function (Blueprint $table): void {
            $table->dropIndex('hr_payslips_company_branch_status_idx');
            $table->dropConstrainedForeignId('currency_id');
            $table->dropConstrainedForeignId('department_id');
            $table->dropConstrainedForeignId('branch_id');
            $table->dropConstrainedForeignId('company_id');
            $table->dropColumn(['employee_doc_num', 'employee_name', 'gross_amount', 'deduction_amount', 'net_amount']);
        });

        Schema::table('hr_payroll_runs', function (Blueprint $table): void {
            $table->dropIndex('hr_payroll_runs_period_branch_status_idx');
            $table->dropConstrainedForeignId('approved_by');
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropConstrainedForeignId('branch_id');
            $table->dropColumn(['calculated_at', 'reviewed_at', 'approved_at']);
        });
    }
};
