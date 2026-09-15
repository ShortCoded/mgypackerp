<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_plans', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('doc_number');
            $table->string('doc_num', 100);
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('fixed_asset_id')->nullable()->constrained('fixed_assets')->restrictOnDelete();
            $table->foreignId('production_mold_id')->nullable()->constrained('production_molds')->restrictOnDelete();
            $table->string('name');
            $table->string('maintenance_type', 30)->default('preventive');
            $table->string('discipline', 30)->nullable();
            $table->string('service_mode', 30)->default('internal');
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->restrictOnDelete();
            $table->string('external_provider_name')->nullable();
            $table->string('frequency_basis', 30);
            $table->decimal('interval_value', 20, 4)->nullable();
            $table->string('schedule_anchor', 20)->default('planned');
            $table->timestamp('next_due_at')->nullable()->index();
            $table->decimal('next_meter_value', 20, 4)->nullable();
            $table->text('task_template');
            $table->unsignedInteger('expected_duration_minutes')->nullable();
            $table->decimal('estimated_cost', 20, 4)->default(0);
            $table->string('status', 30)->default('draft')->index();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('last_completed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('restored_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('restored_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_id', 'doc_num']);
            $table->unique(['company_id', 'doc_number']);
            $table->index(['company_id', 'branch_id', 'status'], 'maintenance_plans_context_status_index');
        });

        Schema::create('maintenance_plan_dues', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('financial_period_id')->constrained('financial_periods')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('maintenance_plan_id')->constrained('maintenance_plans')->restrictOnDelete();
            $table->string('due_key', 180);
            $table->timestamp('due_at')->index();
            $table->decimal('meter_target', 20, 4)->nullable();
            $table->string('status', 30)->default('open')->index();
            $table->timestamp('generated_at');
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('converted_at')->nullable();
            $table->foreignId('converted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['maintenance_plan_id', 'due_key'], 'maintenance_plan_dues_plan_key_unique');
            $table->index(['company_id', 'financial_period_id', 'branch_id', 'status'], 'maintenance_plan_dues_context_status_index');
        });

        Schema::create('maintenance_meter_readings', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('maintenance_plan_id')->constrained('maintenance_plans')->restrictOnDelete();
            $table->string('basis', 30);
            $table->decimal('reading_value', 20, 4)->nullable();
            $table->boolean('is_triggered')->default(false);
            $table->string('reading_type', 30)->default('reading');
            $table->timestamp('recorded_at')->index();
            $table->string('idempotency_key', 100);
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'idempotency_key'], 'maintenance_meter_readings_idempotency_unique');
            $table->index(['maintenance_plan_id', 'basis', 'recorded_at'], 'maintenance_meter_readings_plan_basis_index');
        });

        Schema::table('maintenance_work_orders', function (Blueprint $table): void {
            $table->foreignId('maintenance_plan_due_id')->nullable()->unique()->after('maintenance_request_id')->constrained('maintenance_plan_dues')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_work_orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('maintenance_plan_due_id');
        });
        Schema::dropIfExists('maintenance_meter_readings');
        Schema::dropIfExists('maintenance_plan_dues');
        Schema::dropIfExists('maintenance_plans');
    }
};
