<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('hr_payroll_payments', function (Blueprint $table) {
            $table->foreignId('payslip_id')
                ->nullable()
                ->after('payroll_run_id')
                ->constrained('hr_payslips')
                ->restrictOnDelete();
            $table->index(['payslip_id', 'status'], 'hr_payroll_payments_payslip_status_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('hr_payroll_payments', function (Blueprint $table) {
            $table->dropIndex('hr_payroll_payments_payslip_status_idx');
            $table->dropConstrainedForeignId('payslip_id');
        });
    }
};
