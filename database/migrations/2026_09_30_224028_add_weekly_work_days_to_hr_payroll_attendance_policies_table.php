<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_payroll_attendance_policies', function (Blueprint $table) {
            $table->unsignedTinyInteger('weekly_work_days')->nullable()->after('weekly_accrual_method');
        });
    }

    public function down(): void
    {
        if (DB::table('hr_payroll_attendance_policies')->whereNotNull('weekly_work_days')->exists()) {
            throw new RuntimeException('Rollback refused because populated weekly payroll rules would be lost.');
        }

        Schema::table('hr_payroll_attendance_policies', function (Blueprint $table) {
            $table->dropColumn('weekly_work_days');
        });
    }
};
