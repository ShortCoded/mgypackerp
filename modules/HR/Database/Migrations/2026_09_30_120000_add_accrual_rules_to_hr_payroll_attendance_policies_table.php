<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_payroll_attendance_policies', function (Blueprint $table): void {
            $table->string('monthly_partial_method', 40)->nullable()->after('deduct_unpaid_leave');
            $table->string('weekly_accrual_method', 40)->nullable()->after('monthly_partial_method');
            $table->string('daily_accrual_method', 40)->nullable()->after('weekly_accrual_method');
            $table->string('hourly_accrual_method', 40)->nullable()->after('daily_accrual_method');
            $table->string('shift_accrual_method', 40)->nullable()->after('hourly_accrual_method');
            $table->string('piece_accrual_method', 40)->nullable()->after('shift_accrual_method');
        });
    }

    public function down(): void
    {
        if (DB::table('hr_payroll_attendance_policies')
            ->where(function ($query): void {
                foreach ([
                    'monthly_partial_method', 'weekly_accrual_method', 'daily_accrual_method',
                    'hourly_accrual_method', 'shift_accrual_method', 'piece_accrual_method',
                ] as $column) {
                    $query->orWhereNotNull($column);
                }
            })->exists()) {
            throw new RuntimeException('Rollback refused because populated payroll accrual policies would be lost.');
        }

        Schema::table('hr_payroll_attendance_policies', function (Blueprint $table): void {
            $table->dropColumn([
                'monthly_partial_method',
                'weekly_accrual_method',
                'daily_accrual_method',
                'hourly_accrual_method',
                'shift_accrual_method',
                'piece_accrual_method',
            ]);
        });
    }
};
