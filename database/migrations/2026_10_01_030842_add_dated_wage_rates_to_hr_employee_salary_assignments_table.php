<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_employee_salary_assignments', function (Blueprint $table): void {
            $table->string('pay_basis', 30)->nullable()->after('basic_salary');
            $table->decimal('weekly_wage', 19, 4)->nullable()->after('pay_basis');
            $table->decimal('daily_wage', 19, 4)->nullable()->after('weekly_wage');
            $table->decimal('hourly_wage', 19, 4)->nullable()->after('daily_wage');
            $table->decimal('shift_wage', 19, 4)->nullable()->after('hourly_wage');
            $table->decimal('piece_rate', 19, 4)->nullable()->after('shift_wage');
            $table->text('reason')->nullable()->after('components');
            $table->index(['employee_id', 'effective_from', 'effective_to'], 'hr_salary_assignments_employee_dates_idx');
        });
    }

    public function down(): void
    {
        if (DB::table('hr_employee_salary_assignments')->whereNotNull('pay_basis')->exists()) {
            throw new RuntimeException('Cannot remove recorded wage-rate history.');
        }

        Schema::table('hr_employee_salary_assignments', function (Blueprint $table): void {
            $table->dropIndex('hr_salary_assignments_employee_dates_idx');
            $table->dropColumn([
                'pay_basis', 'weekly_wage', 'daily_wage', 'hourly_wage', 'shift_wage', 'piece_rate', 'reason',
            ]);
        });
    }
};
