<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_payroll_statutory_policy_usages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies');
            $table->foreignId('payroll_run_id')->constrained('hr_payroll_runs')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->string('policy_type', 20);
            $table->unsignedBigInteger('policy_id');
            $table->json('source_snapshot');
            $table->timestamps();
            $table->unique(['payroll_run_id', 'employee_id', 'policy_type', 'policy_id'], 'hr_payroll_statutory_usage_unique');
            $table->index(['company_id', 'policy_type', 'policy_id'], 'hr_payroll_statutory_usage_policy_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_payroll_statutory_policy_usages');
    }
};
