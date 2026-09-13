<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('hr_attendance_events', function (Blueprint $table): void {
            $table->dropUnique('hr_attendance_events_idempotency_key_unique');
            $table->unique(['employee_id', 'idempotency_key'], 'hr_att_event_employee_idempotency_unique');
            $table->index(['company_id', 'occurred_at'], 'hr_att_event_company_occurred_idx');
        });

        Schema::table('hr_attendance_sessions', function (Blueprint $table): void {
            $table->string('scheduled_start_time', 8)->nullable()->after('shift_id');
            $table->string('scheduled_end_time', 8)->nullable()->after('scheduled_start_time');
            $table->boolean('scheduled_crosses_midnight')->default(false)->after('scheduled_end_time');
            $table->unsignedSmallInteger('allowed_late_minutes')->default(0)->after('scheduled_crosses_midnight');
            $table->unsignedSmallInteger('allowed_early_leave_minutes')->default(0)->after('allowed_late_minutes');
            $table->boolean('overtime_enabled')->default(false)->after('allowed_early_leave_minutes');
            $table->index(['company_id', 'work_date', 'status', 'started_at'], 'hr_att_session_report_idx');
        });

        DB::table('hr_attendance_sessions')
            ->orderBy('id')
            ->chunkById(200, function ($sessions): void {
                foreach ($sessions as $session) {
                    $employee = DB::table('hr_employees')->where('id', $session->employee_id)->first();
                    $shift = $session->shift_id === null
                        ? null
                        : DB::table('hr_shifts')->where('id', $session->shift_id)->first();

                    DB::table('hr_attendance_sessions')->where('id', $session->id)->update([
                        'scheduled_start_time' => $shift?->start_time,
                        'scheduled_end_time' => $shift?->end_time,
                        'scheduled_crosses_midnight' => (bool) ($shift?->crosses_midnight ?? false),
                        'allowed_late_minutes' => (int) ($employee?->allow_late_minutes ?? 0),
                        'allowed_early_leave_minutes' => (int) ($employee?->allow_early_leave_minutes ?? 0),
                        'overtime_enabled' => (bool) ($employee?->overtime_enabled ?? false),
                    ]);
                }
            });

        Schema::table('hr_attendance_daily_records', function (Blueprint $table): void {
            $table->index(['company_id', 'work_date', 'branch_id'], 'hr_att_daily_report_idx');
        });

        $this->restoreEmployeeActiveUniqueIndexes();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('hr_attendance_daily_records', function (Blueprint $table): void {
            $table->dropIndex('hr_att_daily_report_idx');
        });

        Schema::table('hr_attendance_sessions', function (Blueprint $table): void {
            $table->dropIndex('hr_att_session_report_idx');
            $table->dropColumn([
                'scheduled_start_time',
                'scheduled_end_time',
                'scheduled_crosses_midnight',
                'allowed_late_minutes',
                'allowed_early_leave_minutes',
                'overtime_enabled',
            ]);
        });

        Schema::table('hr_attendance_events', function (Blueprint $table): void {
            $table->dropIndex('hr_att_event_company_occurred_idx');
            $table->dropUnique('hr_att_event_employee_idempotency_unique');
            $table->unique('idempotency_key');
        });

    }

    private function restoreEmployeeActiveUniqueIndexes(): void
    {
        if (! Schema::hasTable('hr_employees') || ! in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            return;
        }

        $grammar = DB::getQueryGrammar();
        $table = $grammar->wrapTable('hr_employees');
        $deletedAt = $grammar->wrap('deleted_at');

        foreach (['doc_number', 'doc_num', 'employee_code', 'national_id', 'email', 'work_email', 'social_insurance_number'] as $column) {
            if (! Schema::hasColumn('hr_employees', $column)) {
                continue;
            }

            $legacyIndex = $grammar->wrap("hr_employees_{$column}_unique");
            $activeIndex = $grammar->wrap("hr_employees_{$column}_unique_active");
            $wrappedColumn = $grammar->wrap($column);

            DB::statement("DROP INDEX IF EXISTS {$legacyIndex}");
            DB::statement("DROP INDEX IF EXISTS {$activeIndex}");
            DB::statement("CREATE UNIQUE INDEX {$activeIndex} ON {$table} ({$wrappedColumn}) WHERE {$deletedAt} IS NULL AND {$wrappedColumn} IS NOT NULL");
        }
    }
};
