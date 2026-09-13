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
        Schema::create('hr_attendance_sessions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_uuid')->unique();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('assigned_branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('shift_id')->nullable()->constrained('hr_shifts')->nullOnDelete();
            $table->date('work_date')->index();
            $table->string('status', 20)->default('open')->index();
            $table->timestamp('started_at')->index();
            $table->timestamp('ended_at')->nullable()->index();
            $table->unsignedInteger('total_break_minutes')->default(0);
            $table->unsignedInteger('worked_minutes')->default(0);
            $table->string('source', 30)->default('self_service')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['employee_id', 'work_date']);
        });

        Schema::create('hr_attendance_open_sessions', function (Blueprint $table): void {
            $table->foreignId('employee_id')->primary()->constrained('hr_employees')->cascadeOnDelete();
            $table->foreignId('session_id')->unique()->constrained('hr_attendance_sessions')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('hr_attendance_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_uuid')->unique();
            $table->foreignId('session_id')->constrained('hr_attendance_sessions')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('assigned_branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('actual_branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('event_type', 30)->index();
            $table->timestamp('occurred_at')->index();
            $table->timestamp('received_at')->index();
            $table->string('source', 30)->default('self_service')->index();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->decimal('accuracy_meters', 10, 2)->nullable();
            $table->unsignedInteger('distance_meters')->nullable();
            $table->string('geofence_status', 30)->default('unavailable')->index();
            $table->string('location_source', 30)->nullable();
            $table->uuid('idempotency_key')->unique();
            $table->ipAddress('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->text('notes')->nullable();
            $table->json('client_context')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['employee_id', 'occurred_at']);
            $table->index(['assigned_branch_id', 'occurred_at']);
        });

        Schema::table('hr_attendance_daily_records', function (Blueprint $table): void {
            $table->foreignId('company_id')->nullable()->after('employee_id')->constrained('companies')->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->after('company_id')->constrained('branches')->nullOnDelete();
            $table->foreignId('shift_id')->nullable()->after('branch_id')->constrained('hr_shifts')->nullOnDelete();
            $table->unsignedInteger('total_break_minutes')->default(0)->after('check_out_at');
            $table->unsignedInteger('worked_minutes')->default(0)->after('total_break_minutes');
            $table->timestamp('last_calculated_at')->nullable()->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('hr_attendance_daily_records', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('shift_id');
            $table->dropConstrainedForeignId('branch_id');
            $table->dropConstrainedForeignId('company_id');
            $table->dropColumn(['total_break_minutes', 'worked_minutes', 'last_calculated_at']);
        });

        Schema::dropIfExists('hr_attendance_events');
        Schema::dropIfExists('hr_attendance_open_sessions');
        Schema::dropIfExists('hr_attendance_sessions');
    }
};
