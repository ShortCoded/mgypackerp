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
            $table->string('hourly_rounding_mode', 20)->nullable()->after('hourly_accrual_method');
            $table->unsignedTinyInteger('hourly_rounding_increment_minutes')->nullable()->after('hourly_rounding_mode');
        });
    }

    public function down(): void
    {
        if (DB::table('hr_payroll_attendance_policies')
            ->whereNotNull('hourly_rounding_mode')
            ->orWhereNotNull('hourly_rounding_increment_minutes')
            ->exists()) {
            throw new RuntimeException('Rollback refused because populated payroll hourly-rounding rules would be lost.');
        }

        Schema::table('hr_payroll_attendance_policies', function (Blueprint $table) {
            $table->dropColumn(['hourly_rounding_mode', 'hourly_rounding_increment_minutes']);
        });
    }
};
