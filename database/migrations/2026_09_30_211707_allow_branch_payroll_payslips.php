<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_payslips', function (Blueprint $table): void {
            $table->dropUnique('hr_payslips_payroll_run_id_employee_id_unique');
            $table->unique(['payroll_run_id', 'employee_id', 'branch_id'], 'hr_payslips_run_employee_branch_unique');
        });

        Schema::table('hr_payroll_advance_applications', function (Blueprint $table): void {
            $table->dropUnique('hr_payroll_advance_run_source_unique');
            $table->unique(['payroll_run_id', 'salary_advance_id', 'payslip_item_id'], 'hr_payroll_advance_run_source_item_unique');
        });
    }

    public function down(): void
    {
        $splitPayslips = DB::table('hr_payslips')
            ->select('payroll_run_id', 'employee_id')
            ->groupBy('payroll_run_id', 'employee_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();
        $splitAdvances = DB::table('hr_payroll_advance_applications')
            ->select('payroll_run_id', 'salary_advance_id')
            ->groupBy('payroll_run_id', 'salary_advance_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();
        if ($splitPayslips || $splitAdvances) {
            throw new RuntimeException('Cannot remove branch payroll support while split payslips or advances exist.');
        }

        Schema::table('hr_payroll_advance_applications', function (Blueprint $table): void {
            $table->dropUnique('hr_payroll_advance_run_source_item_unique');
            $table->unique(['payroll_run_id', 'salary_advance_id'], 'hr_payroll_advance_run_source_unique');
        });

        Schema::table('hr_payslips', function (Blueprint $table): void {
            $table->dropUnique('hr_payslips_run_employee_branch_unique');
            $table->unique(['payroll_run_id', 'employee_id']);
        });
    }
};
