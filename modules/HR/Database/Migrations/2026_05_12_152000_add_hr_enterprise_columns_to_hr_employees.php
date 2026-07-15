<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hr_employees')) {
            return;
        }

        Schema::table('hr_employees', function (Blueprint $table): void {
            if (! Schema::hasColumn('hr_employees', 'manager_employee_id')) {
                $table->foreignId('manager_employee_id')->nullable()->constrained('hr_employees')->nullOnDelete();
            }

            if (! Schema::hasColumn('hr_employees', 'primary_assignment_id')) {
                $table->foreignId('primary_assignment_id')->nullable()->constrained('hr_employee_assignments')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('hr_employees')) {
            return;
        }

        Schema::table('hr_employees', function (Blueprint $table): void {
            if (Schema::hasColumn('hr_employees', 'primary_assignment_id')) {
                $table->dropConstrainedForeignId('primary_assignment_id');
            }

            if (Schema::hasColumn('hr_employees', 'manager_employee_id')) {
                $table->dropConstrainedForeignId('manager_employee_id');
            }
        });
    }
};
