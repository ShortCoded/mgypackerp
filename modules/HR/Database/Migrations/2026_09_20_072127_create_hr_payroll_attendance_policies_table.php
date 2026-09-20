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
        $duplicateLeaveTypeCodeExists = DB::table('hr_leave_types')
            ->selectRaw('LOWER(TRIM(code)) as normalized_code')
            ->groupByRaw('LOWER(TRIM(code))')
            ->havingRaw('COUNT(*) > 1')
            ->exists();
        if ($duplicateLeaveTypeCodeExists) {
            throw new RuntimeException('Cannot enforce unique leave type codes while duplicate or case-variant codes exist.');
        }
        DB::table('hr_leave_types')->update(['code' => DB::raw('UPPER(TRIM(code))')]);

        Schema::create('hr_payroll_attendance_policies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->cascadeOnDelete();
            $table->string('branch_scope_key', 80);
            $table->date('effective_from')->index();
            $table->date('effective_to')->nullable()->index();
            $table->boolean('deduct_absence')->default(false);
            $table->boolean('deduct_late')->default(false);
            $table->boolean('deduct_early_leave')->default(false);
            $table->boolean('deduct_unpaid_leave')->default(false);
            $table->unsignedSmallInteger('salary_day_divisor')->default(30);
            $table->unsignedSmallInteger('standard_day_minutes')->default(480);
            $table->string('deduction_payroll_item_code', 80)->nullable();
            $table->string('status', 30)->default('active')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes()->index();

            $table->index(
                ['company_id', 'branch_id', 'effective_from', 'effective_to'],
                'hr_payroll_attendance_policy_scope_dates_idx',
            );
            $table->unique(
                ['company_id', 'branch_scope_key', 'effective_from'],
                'hr_payroll_attendance_policy_scope_start_unique',
            );
        });

        Schema::table('hr_leave_types', function (Blueprint $table): void {
            $table->unique('code', 'hr_leave_types_code_unique');
        });

        Schema::table('hr_leave_requests', function (Blueprint $table): void {
            $table->string('payment_status', 20)->nullable()->default('paid')->after('leave_type_id')->index();
        });

        DB::table('hr_leave_types')
            ->select(['id', 'metadata'])
            ->orderBy('id')
            ->chunkById(200, function ($leaveTypes): void {
                foreach ($leaveTypes as $leaveType) {
                    $metadata = is_string($leaveType->metadata)
                        ? json_decode($leaveType->metadata, true)
                        : (array) $leaveType->metadata;
                    $paymentStatus = data_get($metadata, 'payment_status')
                        ?? data_get($metadata, 'payroll_treatment')
                        ?? ((bool) data_get($metadata, 'is_paid', true) ? 'paid' : 'unpaid');

                    DB::table('hr_leave_requests')
                        ->where('leave_type_id', $leaveType->id)
                        ->whereNull('payment_status')
                        ->update(['payment_status' => in_array($paymentStatus, ['paid', 'unpaid'], true) ? $paymentStatus : 'paid']);
                }
            });

        DB::table('hr_leave_requests')->whereNull('payment_status')->update(['payment_status' => 'paid']);
        Schema::table('hr_leave_requests', function (Blueprint $table): void {
            $table->string('payment_status', 20)->default('paid')->nullable(false)->change();
        });

        $this->backfillLegacyServiceRequestSnapshots();
    }

    public function backfillLegacyServiceRequestSnapshots(): int
    {
        if (! Schema::hasTable('hr_employee_service_requests')) {
            return 0;
        }

        $leaveTypes = DB::table('hr_leave_types')
            ->get(['id', 'code', 'name', 'metadata'])
            ->map(function (object $leaveType): object {
                $metadata = is_string($leaveType->metadata)
                    ? json_decode($leaveType->metadata, true)
                    : (array) $leaveType->metadata;
                $paymentStatus = data_get($metadata, 'payment_status')
                    ?? data_get($metadata, 'payroll_treatment')
                    ?? ((bool) data_get($metadata, 'is_paid', true) ? 'paid' : 'unpaid');

                $leaveType->metadata_snapshot = is_array($metadata) ? $metadata : [];
                $leaveType->payment_status_snapshot = in_array($paymentStatus, ['paid', 'unpaid'], true) ? $paymentStatus : 'paid';

                return $leaveType;
            });
        $leaveTypesById = $leaveTypes->keyBy(fn (object $leaveType): int => (int) $leaveType->id);
        $leaveTypesByCode = $leaveTypes->keyBy(fn (object $leaveType): string => mb_strtoupper(trim((string) $leaveType->code)));
        $updated = 0;

        DB::table('hr_employee_service_requests')
            ->where('request_type', 'leave')
            ->orderBy('id')
            ->chunkById(200, function ($requests) use ($leaveTypesById, $leaveTypesByCode, &$updated): void {
                foreach ($requests as $request) {
                    $payload = is_string($request->payload)
                        ? json_decode($request->payload, true)
                        : (array) $request->payload;
                    $payload = is_array($payload) ? $payload : [];
                    if (in_array($payload['payment_status'] ?? null, ['paid', 'unpaid'], true)
                        || (int) ($payload['canonical_leave_request_id'] ?? 0) > 0) {
                        continue;
                    }

                    $leaveTypeId = (int) ($payload['leave_type_id'] ?? 0);
                    $leaveTypeCode = mb_strtoupper(trim((string) ($payload['leave_type'] ?? $payload['leave_type_code'] ?? '')));
                    $leaveType = $leaveTypeId > 0 ? $leaveTypesById->get($leaveTypeId) : null;
                    $leaveType ??= $leaveTypeCode !== '' ? $leaveTypesByCode->get($leaveTypeCode) : null;

                    $payload['payment_status'] = $leaveType?->payment_status_snapshot ?? 'paid';
                    if ($leaveType !== null) {
                        $payload['leave_type_id'] ??= (int) $leaveType->id;
                        $payload['leave_type'] ??= (string) $leaveType->code;
                        $payload['leave_type_name'] ??= (string) $leaveType->name;
                        $payload['requires_balance'] ??= (bool) data_get($leaveType->metadata_snapshot, 'requires_balance', false);
                    }

                    DB::table('hr_employee_service_requests')
                        ->where('id', $request->id)
                        ->update(['payload' => json_encode($payload, JSON_THROW_ON_ERROR)]);
                    $updated++;
                }
            });

        return $updated;
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('hr_payroll_attendance_policies')->exists()
            || DB::table('hr_leave_requests')->whereNotNull('payment_status')->exists()) {
            throw new RuntimeException('Rollback refused because payroll attendance policies or authoritative leave payment snapshots exist.');
        }

        Schema::table('hr_leave_types', function (Blueprint $table): void {
            $table->dropUnique('hr_leave_types_code_unique');
        });

        Schema::table('hr_leave_requests', function (Blueprint $table): void {
            $table->dropIndex(['payment_status']);
            $table->dropColumn('payment_status');
        });

        Schema::dropIfExists('hr_payroll_attendance_policies');
    }
};
