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
            $table->json('deduction_rules')->nullable();
            $table->string('same_day_late_early_mode', 24)->nullable();
            $table->string('deduction_rounding_mode', 24)->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('hr_payroll_attendance_policies')
            ->where(function ($query): void {
                $query->whereNotNull('deduction_rules')
                    ->orWhereNotNull('same_day_late_early_mode')
                    ->orWhereNotNull('deduction_rounding_mode');
            })->exists()) {
            throw new RuntimeException('Cannot roll back configured payroll deduction policies.');
        }

        Schema::table('hr_payroll_attendance_policies', function (Blueprint $table): void {
            $table->dropColumn(['deduction_rules', 'same_day_late_early_mode', 'deduction_rounding_mode']);
        });
    }
};
