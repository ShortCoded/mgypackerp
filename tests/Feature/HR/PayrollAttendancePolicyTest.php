<?php

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Services\AccountClassificationRegistry;
use Modules\Auth\Models\Role;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\Currency;
use Modules\Core\Services\OperatingContextService;
use Modules\HR\Models\HrEmployee;
use Modules\HR\Models\HrEmployeeServiceRequest;
use Modules\HR\Models\HrLeaveType;
use Modules\HR\Models\HrPayrollAttendancePolicy;
use Modules\HR\Models\HrShift;
use Modules\HR\Services\PayrollAttendanceEffectService;
use Modules\HR\Services\PayrollAttendancePolicyService;
use Modules\HR\Services\PayrollCalculationService;
use Modules\HR\Services\PayrollLifecycleService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/** @return array<string, mixed> */
function payrollAttendancePolicyFixture(): array
{
    $company = Company::factory()->create();
    $branch = Branch::query()->create([
        'company_id' => $company->getKey(),
        'doc_number' => 8801,
        'doc_num' => 'PAY-POL-BR-08801',
        'name' => 'Payroll Policy Branch',
        'type' => Branch::TypeAdministrative,
        'status' => 'active',
    ]);
    $currency = Currency::query()->create([
        'company_id' => $company->getKey(),
        'doc_number' => 8801,
        'doc_num' => 'PAY-POL-CUR-08801',
        'name' => 'Egyptian Pound',
        'code' => 'EGP',
        'minor_unit_name' => 'Piastre',
        'minor_unit_factor' => 100,
        'is_main' => true,
        'status' => 'active',
    ]);
    $employee = HrEmployee::query()->create([
        'doc_number' => 8801,
        'doc_num' => 'PAY-POL-EMP-08801',
        'employee_code' => 'POL-E001',
        'full_name' => 'Payroll Policy Employee',
        'name' => 'Payroll Policy Employee',
        'person_type' => 'fixed_employee',
        'status' => 'active',
        'company_id' => $company->getKey(),
        'branch_id' => $branch->getKey(),
        'hire_date' => '2026-01-01',
        'contract_start_date' => '2026-01-01',
        'pay_basis' => 'monthly_salary',
        'payroll_currency_id' => $currency->getKey(),
        'exchange_rate' => 1,
        'basic_salary' => '9000.00',
        'hourly_wage' => '100.0000',
        'overtime_enabled' => true,
    ]);
    DB::table('hr_employee_salary_assignments')->insert([
        'employee_id' => $employee->getKey(),
        'effective_from' => '2026-01-01',
        'basic_salary' => '9000.00',
        'components' => json_encode(['items' => [], 'overtime_hourly_rate' => '100.0000'], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    foreach ([
        ['code' => 'BASIC', 'name' => 'Basic Salary', 'kind' => 'earning'],
        ['code' => 'OVERTIME', 'name' => 'Overtime', 'kind' => 'earning'],
        ['code' => 'ATTENDANCE-DED', 'name' => 'Attendance Deduction', 'kind' => 'deduction'],
    ] as $item) {
        DB::table('hr_payroll_items')->insert([
            'code' => $item['code'],
            'name' => $item['name'],
            'item_kind' => $item['kind'],
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    return compact('company', 'branch', 'currency', 'employee');
}

/** @param array<string, mixed> $fixture */
function seedPayrollAttendanceEvidence(array $fixture): void
{
    foreach ([
        ['date' => '2026-12-30', 'status' => 'present', 'check_out_at' => '2026-12-30 18:00:00', 'late' => 60, 'early' => 30, 'overtime' => 120],
        ['date' => '2026-12-31', 'status' => 'absent', 'check_out_at' => null, 'late' => 0, 'early' => 0, 'overtime' => 0],
        ['date' => '2027-01-01', 'status' => 'absent', 'check_out_at' => null, 'late' => 0, 'early' => 0, 'overtime' => 0],
        ['date' => '2027-01-02', 'status' => 'absent', 'check_out_at' => null, 'late' => 0, 'early' => 0, 'overtime' => 0],
    ] as $record) {
        DB::table('hr_attendance_daily_records')->insert([
            'employee_id' => $fixture['employee']->getKey(),
            'company_id' => $fixture['company']->getKey(),
            'branch_id' => $fixture['branch']->getKey(),
            'work_date' => $record['date'],
            'check_in_at' => $record['status'] === 'present' ? $record['date'].' 08:00:00' : null,
            'check_out_at' => $record['check_out_at'],
            'worked_minutes' => $record['status'] === 'present' ? 480 : 0,
            'late_minutes' => $record['late'],
            'early_leave_minutes' => $record['early'],
            'overtime_minutes' => $record['overtime'],
            'status' => $record['status'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    HrEmployeeServiceRequest::query()->create([
        'employee_id' => $fixture['employee']->getKey(),
        'company_id' => $fixture['company']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'request_type' => 'overtime',
        'details' => 'Approved overtime',
        'requested_from' => '2026-12-30',
        'requested_to' => '2026-12-30',
        'requested_minutes' => 120,
        'status' => HrEmployeeServiceRequest::StatusApproved,
        'submitted_at' => now(),
        'resolved_at' => now(),
    ]);

    $paid = HrLeaveType::query()->create([
        'code' => 'PAID-YEAR-END',
        'name' => 'Paid Year End Leave',
        'status' => 'active',
        'metadata' => ['payment_status' => 'paid', 'requires_balance' => false],
    ]);
    $unpaid = HrLeaveType::query()->create([
        'code' => 'UNPAID-YEAR-END',
        'name' => 'Unpaid Year End Leave',
        'status' => 'active',
        'metadata' => ['payment_status' => 'unpaid', 'requires_balance' => false],
    ]);

    foreach ([
        ['type' => $paid, 'date' => '2027-01-01', 'payment_status' => 'paid'],
        ['type' => $unpaid, 'date' => '2027-01-02', 'payment_status' => 'unpaid'],
    ] as $leave) {
        $leaveRequestId = DB::table('hr_leave_requests')->insertGetId([
            'employee_id' => $fixture['employee']->getKey(),
            'leave_type_id' => $leave['type']->getKey(),
            'payment_status' => $leave['payment_status'],
            'status' => 'approved',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('hr_leave_request_days')->insert([
            'leave_request_id' => $leaveRequestId,
            'leave_date' => $leave['date'],
            'day_fraction' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

test('attendance payroll effects are safely disabled when no policy exists and overtime stays unchanged', function (): void {
    $fixture = payrollAttendancePolicyFixture();
    seedPayrollAttendanceEvidence($fixture);

    $result = app(PayrollCalculationService::class)->calculate($fixture['company']->getKey(), [
        'period_start' => '2026-12-01',
        'period_end' => '2026-12-31',
        'branch_doc_num' => $fixture['branch']->doc_num,
    ]);

    expect($result)->toMatchArray([
        'gross' => '9200.0000',
        'deductions' => '0.0000',
        'payable' => '9200.0000',
    ])->and(DB::table('hr_payslip_items')->where('source_type', 'attendance_policy')->count())->toBe(0)
        ->and((float) DB::table('hr_payslip_items')->where('source_type', 'approved_overtime')->value('amount'))->toBe(200.0);

    $input = json_decode((string) DB::table('hr_payroll_inputs')->value('payload'), true, 512, JSON_THROW_ON_ERROR);
    expect($input['payroll_attendance_effects']['policies'])->toBe([])
        ->and($input['payroll_attendance_effects']['summary'])->toMatchArray([
            'absence_days' => 1,
            'paid_leave_days' => 0,
            'unpaid_leave_days' => 0,
        ]);
});

test('versioned policy deducts absence late early and unpaid leave exactly once across year boundary', function (): void {
    $fixture = payrollAttendancePolicyFixture();
    seedPayrollAttendanceEvidence($fixture);
    HrLeaveType::query()->where('code', 'PAID-YEAR-END')->update(['metadata' => json_encode(['payment_status' => 'unpaid'])]);
    HrLeaveType::query()->where('code', 'UNPAID-YEAR-END')->update(['metadata' => json_encode(['payment_status' => 'paid'])]);
    $policy = HrPayrollAttendancePolicy::query()->create([
        'company_id' => $fixture['company']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'branch_scope_key' => 'branch:'.$fixture['branch']->getKey(),
        'effective_from' => '2026-12-01',
        'deduct_absence' => true,
        'deduct_late' => true,
        'deduct_early_leave' => true,
        'deduct_unpaid_leave' => true,
        'monthly_partial_method' => HrPayrollAttendancePolicy::MonthlyFixedDivisor,
        'salary_day_divisor' => 30,
        'standard_day_minutes' => 480,
        'deduction_payroll_item_code' => 'ATTENDANCE-DED',
        'status' => 'active',
    ]);

    $first = app(PayrollCalculationService::class)->calculate($fixture['company']->getKey(), [
        'period_start' => '2026-12-30',
        'period_end' => '2027-01-02',
        'branch_doc_num' => $fixture['branch']->doc_num,
    ]);
    $second = app(PayrollCalculationService::class)->calculate($fixture['company']->getKey(), [
        'period_start' => '2026-12-30',
        'period_end' => '2027-01-02',
        'branch_doc_num' => $fixture['branch']->doc_num,
    ]);

    expect($first)->toMatchArray([
        'gross' => '1400.0000',
        'deductions' => '656.2500',
        'payable' => '743.7500',
    ])->and($second)->toMatchArray($first)
        ->and(DB::table('hr_payslip_items')->where('source_type', 'attendance_policy')->count())->toBe(4)
        ->and(DB::table('hr_payslip_items')->where('source_type', 'approved_overtime')->count())->toBe(1)
        ->and((float) DB::table('hr_payslip_items')->where('source_type', 'attendance_policy')->sum('amount'))->toBe(656.25);

    $snapshots = DB::table('hr_payslip_items')
        ->where('source_type', 'attendance_policy')
        ->orderBy('id')
        ->pluck('source_snapshot')
        ->map(fn (string $snapshot): array => json_decode($snapshot, true, 512, JSON_THROW_ON_ERROR));
    expect($snapshots->pluck('effect_type')->sort()->values()->all())->toBe(['absence', 'early_leave', 'late', 'unpaid_leave'])
        ->and($snapshots->every(fn (array $snapshot): bool => $snapshot['policy']['id'] === $policy->getKey()))->toBeTrue()
        ->and($snapshots->firstWhere('effect_type', 'unpaid_leave')['leave_request_ids'])->toHaveCount(1);

    $inputBeforePolicyChange = (string) DB::table('hr_payroll_inputs')->value('payload');
    $policy->update(['salary_day_divisor' => 26]);
    expect((string) DB::table('hr_payroll_inputs')->value('payload'))->toBe($inputBeforePolicyChange);
});

test('configured attendance deduction methods caps and same-day precedence affect the saved payslip once', function (): void {
    $fixture = payrollAttendancePolicyFixture();
    seedPayrollAttendanceEvidence($fixture);
    $policy = HrPayrollAttendancePolicy::query()->create([
        'company_id' => $fixture['company']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'branch_scope_key' => 'branch:'.$fixture['branch']->getKey(),
        'effective_from' => '2026-12-01',
        'deduct_absence' => true,
        'deduct_late' => true,
        'deduct_early_leave' => true,
        'deduct_unpaid_leave' => true,
        'monthly_partial_method' => HrPayrollAttendancePolicy::MonthlyFixedDivisor,
        'salary_day_divisor' => 30,
        'standard_day_minutes' => 480,
        'deduction_payroll_item_code' => 'ATTENDANCE-DED',
        'deduction_rules' => [
            'absence' => ['method' => 'fixed', 'value' => '200'],
            'late' => ['method' => 'fixed', 'value' => '50', 'cap' => '40'],
            'early_leave' => ['method' => 'percentage', 'value' => '50'],
            'unpaid_leave' => ['method' => 'percentage', 'value' => '50'],
        ],
        'same_day_late_early_mode' => 'higher',
        'deduction_rounding_mode' => 'half_up',
        'status' => 'active',
    ]);

    $result = app(PayrollCalculationService::class)->calculate($fixture['company']->getKey(), [
        'period_start' => '2026-12-30',
        'period_end' => '2027-01-02',
        'branch_doc_num' => $fixture['branch']->doc_num,
    ]);
    $deductions = DB::table('hr_payslip_items')->where('source_type', 'attendance_policy')->get()
        ->mapWithKeys(function (object $item): array {
            $snapshot = json_decode((string) $item->source_snapshot, true, 512, JSON_THROW_ON_ERROR);

            return [$snapshot['effect_type'] => bcadd((string) $item->amount, '0', 4)];
        });

    expect($policy->fresh()->deduction_rules['late']['cap'])->toBe('40')
        ->and($result['deductions'])->toBe('390.0000')
        ->and($result['payable'])->toBe('1010.0000')
        ->and($deductions->all())->toBe([
            'absence' => '200.0000',
            'late' => '40.0000',
            'unpaid_leave' => '150.0000',
        ]);
});

test('tiered minute rules and rounding apply to attendance evidence without changing historical defaults', function (): void {
    $fixture = payrollAttendancePolicyFixture();
    seedPayrollAttendanceEvidence($fixture);
    $policy = HrPayrollAttendancePolicy::query()->create([
        'company_id' => $fixture['company']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'branch_scope_key' => 'branch:'.$fixture['branch']->getKey(),
        'effective_from' => '2026-12-01',
        'deduct_late' => true,
        'deduct_early_leave' => true,
        'monthly_partial_method' => HrPayrollAttendancePolicy::MonthlyFixedDivisor,
        'salary_day_divisor' => 30,
        'standard_day_minutes' => 480,
        'deduction_payroll_item_code' => 'ATTENDANCE-DED',
        'deduction_rules' => [
            'late' => ['method' => 'tiered', 'tiers' => [['up_to' => 30, 'amount' => '5'], ['up_to' => null, 'amount' => '12.34']]],
            'early_leave' => ['method' => 'percentage', 'value' => '33.3'],
        ],
        'same_day_late_early_mode' => 'sum',
        'deduction_rounding_mode' => 'up',
        'status' => 'active',
    ]);

    $effects = app(PayrollAttendanceEffectService::class)->calculate(
        $fixture['employee'], '2026-12-30', '2026-12-30', '9000', '100',
    );
    $amounts = collect($effects['deductions'])->pluck('amount', 'effect_type')->all();

    expect($amounts)->toBe(['early_leave' => '6.2500', 'late' => '12.3400'])
        ->and($effects['deductions'][0]['snapshot']['policy']['deduction_rounding_mode'])->toBe('up');

    $policy->update(['deduction_rounding_mode' => 'down']);
    $roundedDown = app(PayrollAttendanceEffectService::class)->calculate(
        $fixture['employee'], '2026-12-30', '2026-12-30', '9000', '100',
    );
    expect(collect($roundedDown['deductions'])->pluck('amount', 'effect_type')->all())
        ->toBe(['early_leave' => '6.2400', 'late' => '12.3400']);
});

test('dated branch policy prices overnight attendance by work date after an employee transfer', function (): void {
    $fixture = payrollAttendancePolicyFixture();
    $destination = Branch::query()->create([
        'company_id' => $fixture['company']->getKey(),
        'doc_number' => 8802,
        'doc_num' => 'PAY-POL-BR-08802',
        'name' => 'Synthetic destination branch',
        'type' => Branch::TypeAdministrative,
        'status' => 'active',
    ]);
    DB::table('hr_employee_organization_assignments')->insert([
        ['company_id' => $fixture['company']->getKey(), 'employee_id' => $fixture['employee']->getKey(), 'branch_id' => $fixture['branch']->getKey(), 'effective_from' => '2026-09-01', 'effective_to' => '2026-09-10', 'source_type' => 'initial_verified', 'created_at' => now(), 'updated_at' => now()],
        ['company_id' => $fixture['company']->getKey(), 'employee_id' => $fixture['employee']->getKey(), 'branch_id' => $destination->getKey(), 'effective_from' => '2026-09-11', 'effective_to' => null, 'source_type' => 'transfer', 'created_at' => now(), 'updated_at' => now()],
    ]);
    $fixture['employee']->update(['branch_id' => $destination->getKey()]);
    foreach ([[$fixture['branch'], '5'], [$destination, '10']] as [$branch, $amount]) {
        HrPayrollAttendancePolicy::query()->create([
            'company_id' => $fixture['company']->getKey(),
            'branch_id' => $branch->getKey(),
            'branch_scope_key' => 'branch:'.$branch->getKey(),
            'effective_from' => '2026-09-01',
            'deduct_late' => true,
            'monthly_partial_method' => HrPayrollAttendancePolicy::MonthlyFixedDivisor,
            'salary_day_divisor' => 30,
            'standard_day_minutes' => 480,
            'deduction_payroll_item_code' => 'ATTENDANCE-DED',
            'deduction_rules' => ['late' => ['method' => 'fixed', 'value' => $amount]],
            'status' => 'active',
        ]);
    }
    foreach ([
        ['date' => '2026-09-10', 'checkout' => '2026-09-11 06:00:00', 'branch' => $fixture['branch']->getKey(), 'late' => 10],
        ['date' => '2026-09-11', 'checkout' => '2026-09-12 06:00:00', 'branch' => $destination->getKey(), 'late' => 20],
    ] as $record) {
        DB::table('hr_attendance_daily_records')->insert([
            'employee_id' => $fixture['employee']->getKey(), 'company_id' => $fixture['company']->getKey(),
            'branch_id' => $record['branch'], 'work_date' => $record['date'],
            'check_in_at' => $record['date'].' 22:00:00', 'check_out_at' => $record['checkout'],
            'worked_minutes' => 480, 'late_minutes' => $record['late'], 'overtime_minutes' => 60, 'status' => 'present',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        HrEmployeeServiceRequest::query()->create([
            'employee_id' => $fixture['employee']->getKey(), 'company_id' => $fixture['company']->getKey(),
            'branch_id' => $record['branch'], 'request_type' => 'overtime', 'details' => 'Synthetic approved shift overtime',
            'requested_from' => $record['date'], 'requested_minutes' => 60,
            'status' => HrEmployeeServiceRequest::StatusApproved,
        ]);
    }

    $effects = app(PayrollAttendanceEffectService::class)->calculate(
        $fixture['employee'], '2026-09-10', '2026-09-11', '9000', '100',
    );
    $deductions = collect($effects['deductions'])->pluck('amount', 'policy_id')->values()->all();
    expect($deductions)->toBe(['5.0000', '10.0000'])
        ->and($effects['summary']['late_minutes'])->toBe(30)
        ->and($effects['attendance']['finalized_days'])->toBe(2)
        ->and($effects['overtime']['minutes'])->toBe(120)
        ->and($effects['overtime']['amount'])->toBe('200.0000');

    $laterDay = app(PayrollAttendanceEffectService::class)->calculate(
        $fixture['employee'], '2026-09-12', '2026-09-12', '9000', '100',
    );
    expect($laterDay['approved_request_ids'])->toBe([]);

    HrEmployeeServiceRequest::query()->whereDate('requested_from', '2026-09-10')->update(['branch_id' => $destination->getKey()]);
    expect(fn () => app(PayrollAttendanceEffectService::class)->calculate(
        $fixture['employee'], '2026-09-10', '2026-09-11', '9000', '100',
    ))->toThrow(DomainException::class);
    HrEmployeeServiceRequest::query()->whereDate('requested_from', '2026-09-10')->update(['branch_id' => $fixture['branch']->getKey()]);

    DB::table('hr_attendance_daily_records')->where('work_date', '2026-09-10')->update(['branch_id' => $destination->getKey()]);
    expect(fn () => app(PayrollAttendanceEffectService::class)->calculate(
        $fixture['employee'], '2026-09-10', '2026-09-11', '9000', '100',
    ))->toThrow(DomainException::class, __('hr_payroll.messages.attendance_branch_mismatch', [
        'employee' => $fixture['employee']->doc_num,
        'date' => '2026-09-10',
    ]));
});

test('deduction cap applies once when salary versions split one payroll run', function (): void {
    $fixture = payrollAttendancePolicyFixture();
    DB::table('hr_employee_salary_assignments')->where('employee_id', $fixture['employee']->getKey())->update(['effective_to' => '2026-09-15']);
    DB::table('hr_employee_salary_assignments')->insert([
        'employee_id' => $fixture['employee']->getKey(), 'effective_from' => '2026-09-16',
        'basic_salary' => '9000.00', 'components' => json_encode(['items' => []], JSON_THROW_ON_ERROR),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    foreach (['2026-09-10', '2026-09-20'] as $date) {
        DB::table('hr_attendance_daily_records')->insert([
            'employee_id' => $fixture['employee']->getKey(), 'company_id' => $fixture['company']->getKey(),
            'branch_id' => $fixture['branch']->getKey(), 'work_date' => $date,
            'check_in_at' => $date.' 08:30:00', 'check_out_at' => $date.' 16:00:00',
            'worked_minutes' => 450, 'late_minutes' => 30, 'status' => 'present',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    HrPayrollAttendancePolicy::query()->create([
        'company_id' => $fixture['company']->getKey(), 'branch_id' => $fixture['branch']->getKey(),
        'branch_scope_key' => 'branch:'.$fixture['branch']->getKey(), 'effective_from' => '2026-01-01',
        'deduct_late' => true, 'monthly_partial_method' => HrPayrollAttendancePolicy::MonthlyFixedDivisor,
        'salary_day_divisor' => 30, 'standard_day_minutes' => 480,
        'deduction_payroll_item_code' => 'ATTENDANCE-DED',
        'deduction_rules' => ['late' => ['method' => 'fixed', 'value' => '30', 'cap' => '40']],
        'status' => 'active',
    ]);

    $result = app(PayrollCalculationService::class)->calculate($fixture['company']->getKey(), [
        'period_start' => '2026-09-01', 'period_end' => '2026-09-30',
        'branch_doc_num' => $fixture['branch']->doc_num,
    ]);
    $deduction = DB::table('hr_payslip_items')->where('source_type', 'attendance_policy')->sole();
    $snapshot = json_decode((string) $deduction->source_snapshot, true, 512, JSON_THROW_ON_ERROR);
    expect($result['gross'])->toBe('9000.0000')
        ->and($result['deductions'])->toBe('40.0000')
        ->and($result['payable'])->toBe('8960.0000')
        ->and($snapshot['unrounded_amount'])->toBe('60.00000000')
        ->and($snapshot['rate_segments'])->toHaveCount(2);
});

test('policy versions close adjacent periods and reject duplicate starts', function (): void {
    $fixture = payrollAttendancePolicyFixture();
    $actorId = User::factory()->create()->getKey();
    $service = app(PayrollAttendancePolicyService::class);
    $data = [
        'branch_doc_num' => $fixture['branch']->doc_num,
        'effective_from' => '2026-01-01',
        'deduct_absence' => false,
        'deduct_late' => false,
        'deduct_early_leave' => false,
        'deduct_unpaid_leave' => false,
        'salary_day_divisor' => 30,
        'standard_day_minutes' => 480,
        'deduction_payroll_item_code' => null,
    ];

    $first = $service->createVersion($fixture['company']->getKey(), $data, $actorId);
    $second = $service->createVersion($fixture['company']->getKey(), [...$data, 'effective_from' => '2027-01-01'], $actorId);

    expect($first->refresh()->effective_to?->toDateString())->toBe('2026-12-31')
        ->and($second->effective_to)->toBeNull()
        ->and(fn () => $service->createVersion($fixture['company']->getKey(), $data, $actorId))
        ->toThrow(DomainException::class, __('hr_payroll_policies.validation.effective_from_unique'));
});

test('canonical leave payment status is non null and defaults safely for legacy inserts', function (): void {
    $fixture = payrollAttendancePolicyFixture();
    $leaveType = HrLeaveType::query()->create([
        'code' => 'LEGACY-DEFAULT-PAID',
        'name' => 'Legacy Default Paid',
        'status' => 'active',
        'metadata' => ['payment_status' => 'unpaid', 'requires_balance' => false],
    ]);
    $leaveRequestId = DB::table('hr_leave_requests')->insertGetId([
        'employee_id' => $fixture['employee']->getKey(),
        'leave_type_id' => $leaveType->getKey(),
        'status' => 'approved',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::table('hr_leave_requests')->where('id', $leaveRequestId)->value('payment_status'))->toBe('paid')
        ->and(fn () => DB::table('hr_leave_requests')->insert([
            'employee_id' => $fixture['employee']->getKey(),
            'leave_type_id' => $leaveType->getKey(),
            'payment_status' => null,
            'status' => 'approved',
            'created_at' => now(),
            'updated_at' => now(),
        ]))->toThrow(QueryException::class);
});

test('latest applicable branch policy wins and half cent attendance effects round without floats', function (): void {
    $fixture = payrollAttendancePolicyFixture();
    $fixture['employee']->update(['basic_salary' => '1.0000']);
    DB::table('hr_employee_salary_assignments')->where('employee_id', $fixture['employee']->getKey())->update([
        'basic_salary' => '1.0000',
        'updated_at' => now(),
    ]);
    HrPayrollAttendancePolicy::query()->create([
        'company_id' => $fixture['company']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'branch_scope_key' => 'branch:'.$fixture['branch']->getKey(),
        'effective_from' => '2027-01-01',
        'deduct_late' => false,
        'monthly_partial_method' => HrPayrollAttendancePolicy::MonthlyFixedDivisor,
        'salary_day_divisor' => 1,
        'standard_day_minutes' => 200,
        'deduction_payroll_item_code' => 'ATTENDANCE-DED',
        'status' => 'active',
    ]);
    $latest = HrPayrollAttendancePolicy::query()->create([
        'company_id' => $fixture['company']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'branch_scope_key' => 'branch:'.$fixture['branch']->getKey(),
        'effective_from' => '2027-02-01',
        'deduct_late' => true,
        'monthly_partial_method' => HrPayrollAttendancePolicy::MonthlyFixedDivisor,
        'salary_day_divisor' => 1,
        'standard_day_minutes' => 200,
        'deduction_payroll_item_code' => 'ATTENDANCE-DED',
        'status' => 'active',
    ]);
    DB::table('hr_attendance_daily_records')->insert([
        'employee_id' => $fixture['employee']->getKey(),
        'company_id' => $fixture['company']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'work_date' => '2027-02-01',
        'check_in_at' => '2027-02-01 08:01:00',
        'check_out_at' => '2027-02-01 16:00:00',
        'worked_minutes' => 479,
        'late_minutes' => 1,
        'status' => 'present',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $result = app(PayrollCalculationService::class)->calculate($fixture['company']->getKey(), [
        'period_start' => '2027-02-01',
        'period_end' => '2027-02-01',
        'branch_doc_num' => $fixture['branch']->doc_num,
    ]);

    expect($result['deductions'])->toBe('0.0100')
        ->and($result['payable'])->toBe('0.9900')
        ->and((int) DB::table('hr_payslip_items')->where('source_type', 'attendance_policy')->value('source_id'))->toBe($latest->getKey())
        ->and((string) DB::table('hr_payslip_items')->where('source_type', 'attendance_policy')->value('amount'))->toBe('0.01');
});

test('leave type crud includes detail activation soft delete visibility and restore permissions', function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $permissions = [
        'hr.leave_types.view',
        'hr.leave_types.create',
        'hr.leave_types.update',
        'hr.leave_types.delete',
        'hr.leave_types.view_deleted',
        'hr.leave_types.restore',
    ];
    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $actor = User::factory()->create();
    $actor->givePermissionTo($permissions);

    $this->actingAs($actor)->post(route('admin.hr.leave-types.store'), [
        'code' => 'annual-crud',
        'name' => 'Annual CRUD Leave',
        'payment_status' => 'paid',
        'requires_balance' => '1',
        'annual_entitlement_days' => '21',
        'carry_forward_max_days' => '5',
        'status' => 'active',
        'notes' => 'Initial policy',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $leaveType = HrLeaveType::query()->where('code', 'ANNUAL-CRUD')->sole();
    expect($leaveType->metadata)->toMatchArray([
        'payment_status' => 'paid',
        'requires_balance' => true,
        'annual_entitlement_days' => '21',
        'carry_forward_max_days' => '5',
    ]);
    $this->actingAs($actor)->get(route('admin.hr.leave-types.show', $leaveType))->assertOk()->assertSee('Annual CRUD Leave');

    $this->actingAs($actor)->patch(route('admin.hr.leave-types.update', $leaveType), [
        'code' => 'ANNUAL-CRUD',
        'name' => 'Annual CRUD Leave',
        'payment_status' => 'unpaid',
        'requires_balance' => '0',
        'annual_entitlement_days' => null,
        'carry_forward_max_days' => null,
        'status' => 'inactive',
    ])->assertRedirect()->assertSessionHasNoErrors();
    expect($leaveType->refresh()->status)->toBe('inactive')
        ->and($leaveType->isPaid())->toBeFalse()
        ->and($leaveType->requiresBalance())->toBeFalse();

    $this->actingAs($actor)->delete(route('admin.hr.leave-types.destroy', $leaveType))->assertRedirect();
    expect($leaveType->refresh()->trashed())->toBeTrue()
        ->and((int) $leaveType->deleted_by)->toBe((int) $actor->getKey());
    $trashResponse = $this->actingAs($actor)->get(route('admin.hr.leave-types.index', ['trash' => 'only']))->assertOk();
    expect($trashResponse->viewData('trash'))->toBe('only')
        ->and($trashResponse->viewData('leaveTypes')->count())->toBe(1);
    $trashResponse->assertSee('ANNUAL-CRUD');
    $this->actingAs($actor)->get(route('admin.hr.leave-types.show', $leaveType->getKey()))->assertOk()->assertSee('Annual CRUD Leave');

    $this->actingAs($actor)->patch(route('admin.hr.leave-types.restore', $leaveType->getKey()))->assertRedirect();
    expect($leaveType->refresh()->trashed())->toBeFalse()
        ->and((int) $leaveType->restored_by)->toBe((int) $actor->getKey());

    $viewer = User::factory()->create();
    $viewer->givePermissionTo('hr.leave_types.view');
    $this->actingAs($viewer)->post(route('admin.hr.leave-types.store'), [])->assertForbidden();
});

test('legacy lowercase leave codes normalize and block a later uppercase duplicate', function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Permission::findOrCreate('hr.leave_types.create', 'web');
    $actor = User::factory()->create();
    $actor->givePermissionTo('hr.leave_types.create');
    $legacy = HrLeaveType::query()->create([
        'code' => ' legacy-case ',
        'name' => 'Legacy Case Leave',
        'status' => 'active',
        'metadata' => ['payment_status' => 'paid', 'requires_balance' => false],
    ]);

    expect($legacy->code)->toBe('LEGACY-CASE');
    $this->actingAs($actor)->post(route('admin.hr.leave-types.store'), [
        'code' => 'LEGACY-CASE',
        'name' => 'Duplicate Case Leave',
        'payment_status' => 'paid',
        'requires_balance' => '0',
        'status' => 'active',
    ])->assertSessionHasErrors('code');
    expect(HrLeaveType::query()->where('code', 'LEGACY-CASE')->count())->toBe(1);
});

test('payroll attendance policy http workflow enforces company and branch scope', function (): void {
    $fixture = payrollAttendancePolicyFixture();
    $otherBranch = Branch::query()->create([
        'company_id' => $fixture['company']->getKey(),
        'doc_number' => 8802,
        'doc_num' => 'PAY-POL-BR-08802',
        'name' => 'Forbidden Payroll Policy Branch',
        'type' => Branch::TypeAdministrative,
        'status' => 'active',
    ]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    foreach (['hr.payroll_attendance_policies.view', 'hr.payroll_attendance_policies.manage'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $actor = User::factory()->create();
    $role = Role::query()->create([
        'name' => 'Scoped payroll policy '.uniqid(),
        'guard_name' => 'web',
        'company_access_restricted' => true,
        'branch_access_restricted' => true,
        'financial_period_access_restricted' => false,
    ]);
    $actor->assignRole($role);
    $role->givePermissionTo(['hr.payroll_attendance_policies.view', 'hr.payroll_attendance_policies.manage']);
    $role->companyAccessCompanies()->attach($fixture['company']->getKey());
    $role->branchAccessBranches()->attach($fixture['branch']->getKey());
    $session = [
        OperatingContextService::CompanyIdKey => $fixture['company']->getKey(),
        OperatingContextService::CompanyDocNumKey => $fixture['company']->doc_num,
    ];
    $payload = [
        'branch_doc_num' => $fixture['branch']->doc_num,
        'effective_from' => '2026-01-01',
        'deduct_absence' => '1',
        'monthly_partial_method' => HrPayrollAttendancePolicy::MonthlyCalendarDays,
        'weekly_accrual_method' => HrPayrollAttendancePolicy::WeeklyFinalizedAttendance,
        'weekly_work_days' => '5',
        'daily_accrual_method' => HrPayrollAttendancePolicy::DailyFinalizedAttendance,
        'hourly_accrual_method' => HrPayrollAttendancePolicy::HourlyFinalizedMinutes,
        'hourly_rounding_mode' => 'nearest',
        'hourly_rounding_increment_minutes' => '15',
        'shift_accrual_method' => HrPayrollAttendancePolicy::ShiftFinalizedAttendance,
        'piece_accrual_method' => HrPayrollAttendancePolicy::PieceApprovedOutput,
        'salary_day_divisor' => 30,
        'standard_day_minutes' => 480,
        'deduction_payroll_item_code' => 'ATTENDANCE-DED',
        'deduction_rules' => ['absence' => ['method' => 'fixed', 'value' => '100', 'cap' => '200']],
        'same_day_late_early_mode' => 'higher',
        'deduction_rounding_mode' => 'down',
    ];

    $policyResponse = $this->actingAs($actor)->withSession($session)
        ->get(route('admin.hr.payroll-attendance-policies.index'))
        ->assertOk()
        ->assertSee(route('admin.select2.branches'), false)
        ->assertSee('js-select2-ajax', false)
        ->assertSee('deduction_rules[late][method]', false)
        ->assertSee('name="weekly_work_days"', false)
        ->assertSee(HrPayrollAttendancePolicy::WeeklyFinalizedAttendanceOrPaidLeave, false)
        ->assertSee(HrPayrollAttendancePolicy::HourlyFinalizedMinutesOrPaidLeave, false)
        ->assertSee(HrPayrollAttendancePolicy::ShiftFinalizedAttendanceOrPaidLeave, false)
        ->assertSee('same_day_late_early_mode', false);
    expect($policyResponse->viewData('branches')->pluck('doc_num')->all())->toBe([$fixture['branch']->doc_num]);
    $this->actingAs($actor)->withSession($session)
        ->post(route('admin.hr.payroll-attendance-policies.store'), $payload)
        ->assertRedirect()
        ->assertSessionHasNoErrors();
    $this->assertDatabaseHas('hr_payroll_attendance_policies', [
        'company_id' => $fixture['company']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'deduct_absence' => true,
        'monthly_partial_method' => HrPayrollAttendancePolicy::MonthlyCalendarDays,
        'weekly_accrual_method' => HrPayrollAttendancePolicy::WeeklyFinalizedAttendance,
        'weekly_work_days' => 5,
        'piece_accrual_method' => HrPayrollAttendancePolicy::PieceApprovedOutput,
        'hourly_rounding_mode' => 'nearest',
        'hourly_rounding_increment_minutes' => 15,
        'same_day_late_early_mode' => 'higher',
        'deduction_rounding_mode' => 'down',
    ]);
    expect(HrPayrollAttendancePolicy::query()->where('company_id', $fixture['company']->getKey())->sole()->deduction_rules['absence']['value'])->toBe('100');

    $this->actingAs($actor)->withSession($session)
        ->post(route('admin.hr.payroll-attendance-policies.store'), [
            ...$payload,
            'effective_from' => '2027-01-01',
            'hourly_rounding_increment_minutes' => null,
        ])->assertSessionHasErrors('hourly_rounding_increment_minutes');

    $this->actingAs($actor)->withSession($session)
        ->post(route('admin.hr.payroll-attendance-policies.store'), [
            ...$payload,
            'effective_from' => '2027-01-01',
            'weekly_work_days' => null,
        ])->assertSessionHasErrors('weekly_work_days');

    $this->actingAs($actor)->withSession($session)
        ->post(route('admin.hr.payroll-attendance-policies.store'), [
            ...$payload,
            'effective_from' => '2027-01-01',
            'deduction_rules' => ['absence' => ['method' => 'percentage', 'value' => '101']],
        ])->assertSessionHasErrors('deduction_rules.absence.value');
    $this->actingAs($actor)->withSession($session)
        ->post(route('admin.hr.payroll-attendance-policies.store'), [
            ...$payload,
            'effective_from' => '2027-01-01',
            'deduction_rules' => ['late' => ['method' => 'tiered', 'tiers' => [
                ['up_to' => '30', 'amount' => '5'],
                ['up_to' => '15', 'amount' => '10'],
            ]]],
        ])->assertSessionHasErrors('deduction_rules.late.tiers');

    $this->actingAs($actor)->withSession($session)
        ->post(route('admin.hr.payroll-attendance-policies.store'), [...$payload, 'branch_doc_num' => $otherBranch->doc_num, 'effective_from' => '2027-01-01'])
        ->assertSessionHasErrors('branch_doc_num');
    $this->actingAs($actor)->withSession($session)
        ->post(route('admin.hr.payroll-attendance-policies.store'), [...$payload, 'branch_doc_num' => null, 'effective_from' => '2027-01-01'])
        ->assertSessionHasErrors('branch_doc_num');
    $this->actingAs($actor)->withSession($session)
        ->post(route('admin.hr.payroll-attendance-policies.standard-items'))
        ->assertForbidden();

    $paidLeavePayload = [
        ...$payload,
        'effective_from' => '2027-01-01',
        'weekly_accrual_method' => HrPayrollAttendancePolicy::WeeklyFinalizedAttendanceOrPaidLeave,
        'daily_accrual_method' => HrPayrollAttendancePolicy::DailyFinalizedAttendanceOrPaidLeave,
        'hourly_accrual_method' => HrPayrollAttendancePolicy::HourlyFinalizedMinutesOrPaidLeave,
        'shift_accrual_method' => HrPayrollAttendancePolicy::ShiftFinalizedAttendanceOrPaidLeave,
    ];
    $this->actingAs($actor)->withSession($session)
        ->post(route('admin.hr.payroll-attendance-policies.store'), [...$paidLeavePayload, 'weekly_work_days' => null])
        ->assertSessionHasErrors('weekly_work_days');
    $this->actingAs($actor)->withSession($session)
        ->post(route('admin.hr.payroll-attendance-policies.store'), $paidLeavePayload)
        ->assertRedirect()
        ->assertSessionHasNoErrors();
    $this->assertDatabaseHas('hr_payroll_attendance_policies', [
        'company_id' => $fixture['company']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'effective_from' => '2027-01-01 00:00:00',
        'weekly_accrual_method' => HrPayrollAttendancePolicy::WeeklyFinalizedAttendanceOrPaidLeave,
        'daily_accrual_method' => HrPayrollAttendancePolicy::DailyFinalizedAttendanceOrPaidLeave,
        'hourly_accrual_method' => HrPayrollAttendancePolicy::HourlyFinalizedMinutesOrPaidLeave,
        'shift_accrual_method' => HrPayrollAttendancePolicy::ShiftFinalizedAttendanceOrPaidLeave,
    ]);
});

test('standard payroll item setup fills missing items without changing existing payroll mappings', function (): void {
    $fixture = payrollAttendancePolicyFixture();
    app(AccountClassificationRegistry::class)->synchronize();
    $actor = User::factory()->create();
    $service = app(PayrollAttendancePolicyService::class);
    $basicBefore = DB::table('hr_payroll_items')->where('code', 'BASIC')->first();

    $created = $service->installStandardItems((int) $actor->getKey());

    expect($created)->toContain('ATTENDANCE-DEDUCTION', 'PAYROLL-TAX', 'SALARY-ADVANCE')
        ->not->toContain('BASIC', 'OVERTIME')
        ->and($service->missingStandardItems())->toBe([])
        ->and($service->installStandardItems((int) $actor->getKey()))->toBe([])
        ->and(DB::table('hr_payroll_items')->where('code', 'BASIC')->value('id'))->toBe($basicBefore->id)
        ->and(DB::table('hr_payroll_items')->where('code', 'BASIC')->value('account_classification_id'))->toBe($basicBefore->account_classification_id);

    DB::table('hr_payroll_items')->where('code', 'PAYROLL-TAX')->update(['status' => 'inactive']);
    expect(fn () => $service->installStandardItems((int) $actor->getKey()))
        ->toThrow(DomainException::class, __('hr_payroll_policies.validation.catalog_item_conflict', ['code' => 'PAYROLL-TAX']));

    DB::table('hr_payroll_items')->where('code', 'PAYROLL-TAX')->update(['status' => 'active', 'deleted_at' => now()]);
    expect(fn () => $service->installStandardItems((int) $actor->getKey()))
        ->toThrow(DomainException::class, __('hr_payroll_policies.validation.catalog_item_conflict', ['code' => 'PAYROLL-TAX']));
});

test('accrual policy migration refuses rollback after a rule is configured', function (): void {
    $fixture = payrollAttendancePolicyFixture();
    HrPayrollAttendancePolicy::query()->create([
        'company_id' => $fixture['company']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'branch_scope_key' => 'branch:'.$fixture['branch']->getKey(),
        'effective_from' => '2026-01-01',
        'monthly_partial_method' => HrPayrollAttendancePolicy::MonthlyCalendarDays,
        'salary_day_divisor' => 30,
        'standard_day_minutes' => 480,
        'status' => 'active',
    ]);

    $migration = require base_path('modules/HR/Database/Migrations/2026_09_30_120000_add_accrual_rules_to_hr_payroll_attendance_policies_table.php');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class);
    expect(DB::table('hr_payroll_attendance_policies')->whereNotNull('monthly_partial_method')->count())->toBe(1);
});

test('hourly rounding policy migration refuses rollback after a rule is configured', function (): void {
    $fixture = payrollAttendancePolicyFixture();
    HrPayrollAttendancePolicy::query()->create([
        'company_id' => $fixture['company']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'branch_scope_key' => 'branch:'.$fixture['branch']->getKey(),
        'effective_from' => '2026-01-01',
        'hourly_accrual_method' => HrPayrollAttendancePolicy::HourlyFinalizedMinutes,
        'hourly_rounding_mode' => 'nearest',
        'hourly_rounding_increment_minutes' => 15,
        'salary_day_divisor' => 30,
        'standard_day_minutes' => 480,
        'status' => 'active',
    ]);

    $migration = require base_path('database/migrations/2026_09_30_222022_add_hourly_rounding_to_hr_payroll_attendance_policies_table.php');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class);
    expect(DB::table('hr_payroll_attendance_policies')->where('hourly_rounding_increment_minutes', 15)->count())->toBe(1);
});

test('a salary assignment beginning within a payroll period uses the approved proration rule and snapshots it', function (): void {
    $fixture = payrollAttendancePolicyFixture();
    DB::table('hr_employee_salary_assignments')->where('employee_id', $fixture['employee']->getKey())->update([
        'effective_from' => '2026-09-15',
    ]);
    $policy = HrPayrollAttendancePolicy::query()->create([
        'company_id' => $fixture['company']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'branch_scope_key' => 'branch:'.$fixture['branch']->getKey(),
        'effective_from' => '2026-01-01',
        'monthly_partial_method' => HrPayrollAttendancePolicy::MonthlyCalendarDays,
        'salary_day_divisor' => 30,
        'standard_day_minutes' => 480,
        'status' => 'active',
    ]);

    $result = app(PayrollCalculationService::class)->calculate($fixture['company']->getKey(), [
        'period_start' => '2026-09-01',
        'period_end' => '2026-09-30',
        'branch_doc_num' => $fixture['branch']->doc_num,
    ]);
    $basicItem = DB::table('hr_payslip_items')->where('source_type', 'salary_assignment')->where('amount', '4800.0000')->sole();
    $snapshot = json_decode($basicItem->source_snapshot, true, 512, JSON_THROW_ON_ERROR);

    expect($result['gross'])->toBe('4800.0000')
        ->and(data_get($snapshot, 'accrual.method'))->toBe(HrPayrollAttendancePolicy::MonthlyCalendarDays)
        ->and(data_get($snapshot, 'accrual.evidence.accrued_days'))->toBe(16)
        ->and(data_get($snapshot, 'accrual.policy_snapshots.0.id'))->toBe($policy->getKey());
});

test('a short payroll run is prorated and overlapping payroll periods are rejected', function (): void {
    $fixture = payrollAttendancePolicyFixture();
    HrPayrollAttendancePolicy::query()->create([
        'company_id' => $fixture['company']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'branch_scope_key' => 'branch:'.$fixture['branch']->getKey(),
        'effective_from' => '2026-01-01',
        'monthly_partial_method' => HrPayrollAttendancePolicy::MonthlyCalendarDays,
        'salary_day_divisor' => 30,
        'standard_day_minutes' => 480,
        'status' => 'active',
    ]);

    $shortRun = app(PayrollCalculationService::class)->calculate($fixture['company']->getKey(), [
        'period_start' => '2026-09-01',
        'period_end' => '2026-09-15',
        'branch_doc_num' => $fixture['branch']->doc_num,
    ]);
    expect($shortRun['gross'])->toBe('4500.0000');

    expect(fn () => app(PayrollCalculationService::class)->calculate($fixture['company']->getKey(), [
        'period_start' => '2026-09-01',
        'period_end' => '2026-09-30',
        'branch_doc_num' => $fixture['branch']->doc_num,
    ]))->toThrow(DomainException::class, __('hr_payroll.messages.overlapping_period'));
    expect(DB::table('hr_payroll_periods')->where('company_id', $fixture['company']->getKey())->count())->toBe(1);
});

test('an assignment ending mid-month and a recorded employee departure prorate the final salary', function (): void {
    $fixture = payrollAttendancePolicyFixture();
    HrPayrollAttendancePolicy::query()->create([
        'company_id' => $fixture['company']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'branch_scope_key' => 'branch:'.$fixture['branch']->getKey(),
        'effective_from' => '2026-01-01',
        'monthly_partial_method' => HrPayrollAttendancePolicy::MonthlyCalendarDays,
        'salary_day_divisor' => 30,
        'standard_day_minutes' => 480,
        'status' => 'active',
    ]);
    DB::table('hr_employee_salary_assignments')->where('employee_id', $fixture['employee']->getKey())
        ->update(['effective_to' => '2026-09-15']);
    $fixture['employee']->forceFill(['status' => 'left', 'termination_date' => '2026-09-15'])->save();
    $monthRun = app(PayrollCalculationService::class)->calculate($fixture['company']->getKey(), [
        'period_start' => '2026-09-01',
        'period_end' => '2026-09-30',
        'branch_doc_num' => $fixture['branch']->doc_num,
    ]);
    expect($monthRun['gross'])->toBe('4500.0000');
});

test('partial monthly pay fails closed without a rule and sequential salaries accrue in one run', function (): void {
    $fixture = payrollAttendancePolicyFixture();
    DB::table('hr_employee_salary_assignments')->where('employee_id', $fixture['employee']->getKey())->update([
        'effective_from' => '2026-09-15',
    ]);
    $payload = [
        'period_start' => '2026-09-01',
        'period_end' => '2026-09-30',
        'branch_doc_num' => $fixture['branch']->doc_num,
    ];

    expect(fn () => app(PayrollCalculationService::class)->calculate($fixture['company']->getKey(), $payload))
        ->toThrow(DomainException::class, __('hr_payroll.messages.accrual_policy_required', [
            'employee' => $fixture['employee']->doc_num,
            'pay_basis' => 'monthly_salary',
            'date' => '2026-09-15',
        ]));

    HrPayrollAttendancePolicy::query()->create([
        'company_id' => $fixture['company']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'branch_scope_key' => 'branch:'.$fixture['branch']->getKey(),
        'effective_from' => '2026-01-01',
        'monthly_partial_method' => HrPayrollAttendancePolicy::MonthlyCalendarDays,
        'salary_day_divisor' => 30,
        'standard_day_minutes' => 480,
        'status' => 'active',
    ]);
    DB::table('hr_employee_salary_assignments')->insert([
        'employee_id' => $fixture['employee']->getKey(),
        'effective_from' => '2026-01-01',
        'effective_to' => '2026-09-14',
        'basic_salary' => '8000.0000',
        'components' => json_encode(['items' => []], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $result = app(PayrollCalculationService::class)->calculate($fixture['company']->getKey(), $payload);
    $basic = DB::table('hr_payslip_items')->where('source_type', 'salary_assignment')->orderBy('id')->get();
    $input = json_decode((string) DB::table('hr_payroll_inputs')->value('payload'), true, 512, JSON_THROW_ON_ERROR);

    expect($result['gross'])->toBe('8533.3333')
        ->and($basic)->toHaveCount(2)
        ->and($basic->pluck('amount')->map(fn (mixed $amount): string => bcadd((string) $amount, '0', 4))->all())
        ->toBe(['3733.3333', '4800.0000'])
        ->and($input['salary_sources'])->toHaveCount(2);
});

test('payroll refuses overlapping salary assignments that both predate the run', function (): void {
    $fixture = payrollAttendancePolicyFixture();
    DB::table('hr_employee_salary_assignments')->insert([
        'employee_id' => $fixture['employee']->getKey(),
        'effective_from' => '2026-06-01',
        'effective_to' => null,
        'basic_salary' => '8000.00',
        'components' => json_encode(['items' => []], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => app(PayrollCalculationService::class)->calculate($fixture['company']->getKey(), [
        'period_start' => '2026-09-01',
        'period_end' => '2026-09-30',
        'branch_doc_num' => $fixture['branch']->doc_num,
    ]))->toThrow(DomainException::class, __('hr_payroll.messages.multiple_salary_assignments_require_split', [
        'employee' => $fixture['employee']->doc_num,
    ]));
    expect(DB::table('hr_payslips')->count())->toBe(0);
});

test('a thirty day month preserves the exact nine thousand to twelve thousand salary boundary', function (): void {
    $fixture = payrollAttendancePolicyFixture();
    DB::table('hr_employee_salary_assignments')
        ->where('employee_id', $fixture['employee']->getKey())
        ->update(['effective_to' => '2026-09-20']);
    DB::table('hr_employee_salary_assignments')->insert([
        'employee_id' => $fixture['employee']->getKey(),
        'effective_from' => '2026-09-21',
        'basic_salary' => '12000.00',
        'components' => json_encode(['items' => []], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    HrPayrollAttendancePolicy::query()->create([
        'company_id' => $fixture['company']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'branch_scope_key' => 'branch:'.$fixture['branch']->getKey(),
        'effective_from' => '2026-01-01',
        'monthly_partial_method' => HrPayrollAttendancePolicy::MonthlyCalendarDays,
        'salary_day_divisor' => 30,
        'standard_day_minutes' => 480,
        'status' => 'active',
    ]);

    $result = app(PayrollCalculationService::class)->calculate($fixture['company']->getKey(), [
        'period_start' => '2026-09-01',
        'period_end' => '2026-09-30',
        'branch_doc_num' => $fixture['branch']->doc_num,
    ]);
    $basic = DB::table('hr_payslip_items')->where('source_type', 'salary_assignment')->orderBy('id')->get();
    $sources = $basic->map(fn (object $item): array => json_decode($item->source_snapshot, true, 512, JSON_THROW_ON_ERROR));

    expect($result['gross'])->toBe('10000.0000')
        ->and($basic->pluck('amount')->map(fn (mixed $amount): string => bcadd((string) $amount, '0', 4))->all())
        ->toBe(['6000.0000', '4000.0000'])
        ->and($sources->pluck('segment_from')->all())->toBe(['2026-09-01', '2026-09-21'])
        ->and($sources->pluck('segment_to')->all())->toBe(['2026-09-20', '2026-09-30']);
});

test('a thirty day month allocates a dated branch transfer without duplicating salary', function (): void {
    $fixture = payrollAttendancePolicyFixture();
    $destination = Branch::query()->create([
        'company_id' => $fixture['company']->getKey(),
        'doc_number' => 8802,
        'doc_num' => 'PAY-POL-BR-08802',
        'name' => 'Synthetic destination branch',
        'type' => Branch::TypeAdministrative,
        'status' => 'active',
    ]);
    DB::table('hr_employee_organization_assignments')->insert([
        [
            'company_id' => $fixture['company']->getKey(),
            'employee_id' => $fixture['employee']->getKey(),
            'branch_id' => $fixture['branch']->getKey(),
            'effective_from' => '2026-01-01',
            'effective_to' => '2026-09-10',
            'source_type' => 'initial_verified',
            'created_at' => now(), 'updated_at' => now(),
        ],
        [
            'company_id' => $fixture['company']->getKey(),
            'employee_id' => $fixture['employee']->getKey(),
            'branch_id' => $destination->getKey(),
            'effective_from' => '2026-09-11',
            'effective_to' => null,
            'source_type' => 'transfer',
            'created_at' => now(), 'updated_at' => now(),
        ],
    ]);
    $fixture['employee']->update(['branch_id' => $destination->getKey()]);
    foreach ([$fixture['branch'], $destination] as $branch) {
        HrPayrollAttendancePolicy::query()->create([
            'company_id' => $fixture['company']->getKey(),
            'branch_id' => $branch->getKey(),
            'branch_scope_key' => 'branch:'.$branch->getKey(),
            'effective_from' => '2026-01-01',
            'monthly_partial_method' => HrPayrollAttendancePolicy::MonthlyCalendarDays,
            'salary_day_divisor' => 30,
            'standard_day_minutes' => 480,
            'status' => 'active',
        ]);
    }

    $service = app(PayrollCalculationService::class);
    $first = $service->calculate($fixture['company']->getKey(), [
        'period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'branch_doc_num' => $fixture['branch']->doc_num,
    ]);
    $second = $service->calculate($fixture['company']->getKey(), [
        'period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'branch_doc_num' => $destination->doc_num,
    ]);

    expect($first['gross'])->toBe('3000.0000')
        ->and($second['gross'])->toBe('6000.0000')
        ->and(bcadd($first['gross'], $second['gross'], 4))->toBe('9000.0000')
        ->and(DB::table('hr_payslips')->orderBy('id')->pluck('branch_id')->all())->toBe([$fixture['branch']->getKey(), $destination->getKey()]);

    DB::table('hr_employee_salary_assignments')->where('employee_id', $fixture['employee']->getKey())
        ->update(['effective_to' => '2026-09-20']);
    DB::table('hr_employee_salary_assignments')->insert([
        'employee_id' => $fixture['employee']->getKey(),
        'effective_from' => '2026-09-21',
        'basic_salary' => '12000.00',
        'components' => json_encode(['items' => []], JSON_THROW_ON_ERROR),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $firstRecalculated = $service->calculate($fixture['company']->getKey(), [
        'period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'branch_doc_num' => $fixture['branch']->doc_num,
    ]);
    $secondRecalculated = $service->calculate($fixture['company']->getKey(), [
        'period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'branch_doc_num' => $destination->doc_num,
    ]);
    $secondItems = DB::table('hr_payslip_items as item')
        ->join('hr_payslips as slip', 'slip.id', '=', 'item.payslip_id')
        ->where('slip.payroll_run_id', $secondRecalculated['run_id'])
        ->where('item.source_type', 'salary_assignment')
        ->orderBy('item.id')->pluck('item.amount')->map(fn (mixed $amount): string => bcadd((string) $amount, '0', 4))->all();

    expect($firstRecalculated['gross'])->toBe('3000.0000')
        ->and($secondRecalculated['gross'])->toBe('7000.0000')
        ->and($secondItems)->toBe(['3000.0000', '4000.0000']);
});

test('a company payroll run creates separate payable slips for a dated branch transfer', function (): void {
    $fixture = payrollAttendancePolicyFixture();
    $destination = Branch::query()->create([
        'company_id' => $fixture['company']->getKey(),
        'doc_number' => 8802,
        'doc_num' => 'PAY-POL-BR-08802',
        'name' => 'Synthetic destination branch',
        'type' => Branch::TypeAdministrative,
        'status' => 'active',
    ]);
    DB::table('hr_employee_organization_assignments')->insert([
        [
            'company_id' => $fixture['company']->getKey(),
            'employee_id' => $fixture['employee']->getKey(),
            'branch_id' => $fixture['branch']->getKey(),
            'effective_from' => '2026-01-01',
            'effective_to' => '2026-09-10',
            'source_type' => 'initial_verified',
            'created_at' => now(), 'updated_at' => now(),
        ],
        [
            'company_id' => $fixture['company']->getKey(),
            'employee_id' => $fixture['employee']->getKey(),
            'branch_id' => $destination->getKey(),
            'effective_from' => '2026-09-11',
            'effective_to' => null,
            'source_type' => 'transfer',
            'created_at' => now(), 'updated_at' => now(),
        ],
    ]);
    $fixture['employee']->update(['branch_id' => $destination->getKey()]);
    foreach ([$fixture['branch'], $destination] as $branch) {
        HrPayrollAttendancePolicy::query()->create([
            'company_id' => $fixture['company']->getKey(),
            'branch_id' => $branch->getKey(),
            'branch_scope_key' => 'branch:'.$branch->getKey(),
            'effective_from' => '2026-01-01',
            'monthly_partial_method' => HrPayrollAttendancePolicy::MonthlyCalendarDays,
            'salary_day_divisor' => 30,
            'standard_day_minutes' => 480,
            'deduct_absence' => true,
            'deduction_payroll_item_code' => 'ATTENDANCE-DED',
            'status' => 'active',
        ]);
    }
    foreach ([['2026-09-10', $fixture['branch']], ['2026-09-11', $destination]] as [$date, $branch]) {
        DB::table('hr_attendance_daily_records')->insert([
            'employee_id' => $fixture['employee']->getKey(),
            'company_id' => $fixture['company']->getKey(),
            'branch_id' => $branch->getKey(),
            'work_date' => $date,
            'status' => 'absent',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $result = app(PayrollCalculationService::class)->calculate($fixture['company']->getKey(), [
        'period_start' => '2026-09-01', 'period_end' => '2026-09-30',
    ]);
    $slips = DB::table('hr_payslips')->where('payroll_run_id', $result['run_id'])->orderBy('branch_id')->get();

    expect($result)->toMatchArray(['employee_count' => 1, 'gross' => '9000.0000', 'deductions' => '600.0000', 'payable' => '8400.0000'])
        ->and($slips)->toHaveCount(2)
        ->and($slips->pluck('branch_id')->all())->toBe([$fixture['branch']->getKey(), $destination->getKey()])
        ->and($slips->pluck('gross_amount')->map(fn (mixed $amount): string => bcadd((string) $amount, '0', 4))->all())->toBe(['3000.0000', '6000.0000'])
        ->and($slips->pluck('net_amount')->map(fn (mixed $amount): string => bcadd((string) $amount, '0', 4))->all())->toBe(['2700.0000', '5700.0000'])
        ->and(DB::table('hr_payroll_inputs')->where('payroll_run_id', $result['run_id'])->count())->toBe(1);
});

test('company and branch payroll scopes cannot calculate the same employee twice in one period', function (bool $branchFirst): void {
    $fixture = payrollAttendancePolicyFixture();
    $base = ['period_start' => '2026-09-01', 'period_end' => '2026-09-30'];
    $branch = [...$base, 'branch_doc_num' => $fixture['branch']->doc_num];
    $firstPayload = $branchFirst ? $branch : $base;
    $secondPayload = $branchFirst ? $base : $branch;

    $first = app(PayrollCalculationService::class)->calculate($fixture['company']->getKey(), $firstPayload);
    expect($first['gross'])->toBe('9000.0000');
    expect(fn () => app(PayrollCalculationService::class)->calculate($fixture['company']->getKey(), $secondPayload))
        ->toThrow(DomainException::class, __('hr_payroll.messages.duplicate_employee_scope'));
    expect(DB::table('hr_payslips')->count())->toBe(1)
        ->and(DB::table('hr_payroll_runs')->count())->toBe(1)
        ->and((int) DB::table('hr_payslips')->value('payroll_run_id'))->toBe($first['run_id']);
})->with([
    'branch then company' => [true],
    'company then branch' => [false],
]);

test('an internal salary gap rolls back recalculation and preserves the previous calculated payslip', function (): void {
    $fixture = payrollAttendancePolicyFixture();
    $payload = [
        'period_start' => '2026-09-01',
        'period_end' => '2026-09-30',
        'branch_doc_num' => $fixture['branch']->doc_num,
    ];
    $initial = app(PayrollCalculationService::class)->calculate($fixture['company']->getKey(), $payload);
    DB::table('hr_employee_salary_assignments')->where('employee_id', $fixture['employee']->getKey())
        ->update(['effective_to' => '2026-09-10']);
    DB::table('hr_employee_salary_assignments')->insert([
        'employee_id' => $fixture['employee']->getKey(),
        'effective_from' => '2026-09-12',
        'basic_salary' => '12000.00',
        'components' => json_encode(['items' => []], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => app(PayrollCalculationService::class)->calculate($fixture['company']->getKey(), $payload))
        ->toThrow(DomainException::class)
        ->and(bcadd((string) DB::table('hr_payslips')->where('payroll_run_id', $initial['run_id'])->value('gross_amount'), '0', 4))
        ->toBe('9000.0000')
        ->and(DB::table('hr_payslip_items')->where('source_type', 'salary_assignment')->count())->toBe(1);
});

test('overtime crossing salary rates requires dated approvals and uses each segment rate', function (): void {
    $fixture = payrollAttendancePolicyFixture();
    DB::table('hr_employee_salary_assignments')->where('employee_id', $fixture['employee']->getKey())
        ->update(['effective_to' => '2026-09-10']);
    DB::table('hr_employee_salary_assignments')->insert([
        'employee_id' => $fixture['employee']->getKey(),
        'effective_from' => '2026-09-11',
        'basic_salary' => '12000.00',
        'components' => json_encode(['items' => [], 'overtime_hourly_rate' => '200.0000'], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    HrPayrollAttendancePolicy::query()->create([
        'company_id' => $fixture['company']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'branch_scope_key' => 'branch:'.$fixture['branch']->getKey(),
        'effective_from' => '2026-01-01',
        'monthly_partial_method' => HrPayrollAttendancePolicy::MonthlyCalendarDays,
        'salary_day_divisor' => 30,
        'standard_day_minutes' => 480,
        'status' => 'active',
    ]);
    foreach (['2026-09-10', '2026-09-11'] as $date) {
        DB::table('hr_attendance_daily_records')->insert([
            'employee_id' => $fixture['employee']->getKey(),
            'company_id' => $fixture['company']->getKey(),
            'branch_id' => $fixture['branch']->getKey(),
            'work_date' => $date,
            'check_in_at' => $date.' 08:00:00',
            'check_out_at' => $date.' 17:00:00',
            'worked_minutes' => 480,
            'overtime_minutes' => 60,
            'status' => 'present',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
    $request = HrEmployeeServiceRequest::query()->create([
        'employee_id' => $fixture['employee']->getKey(),
        'company_id' => $fixture['company']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'request_type' => 'overtime',
        'details' => 'Synthetic aggregate overtime',
        'requested_from' => '2026-09-10',
        'requested_to' => '2026-09-11',
        'requested_minutes' => 60,
        'status' => HrEmployeeServiceRequest::StatusApproved,
        'submitted_at' => now(),
        'resolved_at' => now(),
    ]);
    $payload = [
        'period_start' => '2026-09-01',
        'period_end' => '2026-09-30',
        'branch_doc_num' => $fixture['branch']->doc_num,
    ];

    expect(fn () => app(PayrollCalculationService::class)->calculate($fixture['company']->getKey(), $payload))
        ->toThrow(DomainException::class, __('hr_payroll.messages.overtime_approval_requires_dated_allocation', [
            'employee' => $fixture['employee']->doc_num,
        ]));
    $request->update(['requested_to' => '2026-09-10']);
    HrEmployeeServiceRequest::query()->create([
        'employee_id' => $fixture['employee']->getKey(),
        'company_id' => $fixture['company']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'request_type' => 'overtime',
        'details' => 'Synthetic dated overtime',
        'requested_from' => '2026-09-11',
        'requested_to' => '2026-09-11',
        'requested_minutes' => 60,
        'status' => HrEmployeeServiceRequest::StatusApproved,
        'submitted_at' => now(),
        'resolved_at' => now(),
    ]);
    $result = app(PayrollCalculationService::class)->calculate($fixture['company']->getKey(), $payload);
    $overtime = DB::table('hr_payslip_items')->where('source_type', 'approved_overtime')->orderBy('id')->get();
    $snapshots = $overtime->map(fn (object $item): array => json_decode($item->source_snapshot, true, 512, JSON_THROW_ON_ERROR));

    expect($result['gross'])->toBe('11300.0000')
        ->and($overtime->pluck('amount')->map(fn (mixed $amount): string => bcadd((string) $amount, '0', 4))->all())
        ->toBe(['100.0000', '200.0000'])
        ->and($snapshots->pluck('segment_from')->all())->toBe(['2026-09-01', '2026-09-11']);
});

test('weekly daily hourly and shift wages accrue approved paid leave without double counting attendance', function (): void {
    $fixture = payrollAttendancePolicyFixture();
    $employee = $fixture['employee'];
    HrPayrollAttendancePolicy::query()->create([
        'company_id' => $fixture['company']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'branch_scope_key' => 'branch:'.$fixture['branch']->getKey(),
        'effective_from' => '2026-01-01',
        'weekly_accrual_method' => HrPayrollAttendancePolicy::WeeklyFinalizedAttendanceOrPaidLeave,
        'weekly_work_days' => 5,
        'daily_accrual_method' => HrPayrollAttendancePolicy::DailyFinalizedAttendanceOrPaidLeave,
        'hourly_accrual_method' => HrPayrollAttendancePolicy::HourlyFinalizedMinutesOrPaidLeave,
        'shift_accrual_method' => HrPayrollAttendancePolicy::ShiftFinalizedAttendanceOrPaidLeave,
        'salary_day_divisor' => 30,
        'standard_day_minutes' => 480,
        'status' => 'active',
    ]);
    $shift = HrShift::query()->create([
        'doc_num' => 'PAID-LEAVE-SHIFT',
        'name' => 'Synthetic paid-leave shift',
        'start_time' => '08:00:00',
        'end_time' => '16:00:00',
        'break_minutes' => 0,
        'crosses_midnight' => false,
        'status' => 'active',
    ]);
    DB::table('hr_employee_shift_assignments')->insert([
        'employee_id' => $employee->getKey(),
        'shift_id' => $shift->getKey(),
        'effective_from' => '2026-01-01',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('hr_attendance_daily_records')->insert([
        'employee_id' => $employee->getKey(),
        'company_id' => $fixture['company']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'shift_id' => $shift->getKey(),
        'work_date' => '2026-09-01',
        'check_in_at' => '2026-09-01 08:00:00',
        'check_out_at' => '2026-09-01 16:00:00',
        'worked_minutes' => 480,
        'status' => 'present',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $leaveType = HrLeaveType::query()->create([
        'code' => 'ACCRUAL-PAID-LEAVE',
        'name' => 'Synthetic approved paid leave',
        'status' => 'active',
        'metadata' => ['payment_status' => 'paid', 'requires_balance' => false],
    ]);
    $leaveIds = [];
    foreach ([
        ['2026-09-01', '1.0000', 'paid'],
        ['2026-09-02', '1.0000', 'paid'],
        ['2026-09-03', '0.5000', 'paid'],
        ['2026-09-04', '1.0000', 'unpaid'],
    ] as [$leaveDate, $fraction, $paymentStatus]) {
        $leaveId = DB::table('hr_leave_requests')->insertGetId([
            'employee_id' => $employee->getKey(),
            'leave_type_id' => $leaveType->getKey(),
            'payment_status' => $paymentStatus,
            'status' => 'approved',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $leaveIds[$leaveDate] = $leaveId;
        DB::table('hr_leave_request_days')->insert([
            'leave_request_id' => $leaveId,
            'leave_date' => $leaveDate,
            'day_fraction' => $fraction,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    foreach ([
        ['weekly_wage', 'weekly_wage', '700.0000', '350.0000', '2.5000'],
        ['daily_wage', 'daily_wage', '300.0000', '750.0000', '2.5000'],
        ['hourly_wage', 'hourly_wage', '50.0000', '1000.0000', '20.0000'],
        ['shift_wage', 'shift_wage', '600.0000', '1500.0000', '2.5000'],
    ] as [$basis, $rateField, $rate, $expected, $expectedUnits]) {
        $employee->update(['pay_basis' => $basis, $rateField => $rate]);
        $result = app(PayrollCalculationService::class)->calculate($fixture['company']->getKey(), [
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-07',
            'branch_doc_num' => $fixture['branch']->doc_num,
        ]);
        $snapshot = json_decode((string) DB::table('hr_payslip_items')
            ->where('payroll_item_id', DB::table('hr_payroll_items')->where('code', 'BASIC')->value('id'))
            ->value('source_snapshot'), true, 512, JSON_THROW_ON_ERROR);

        expect($result['gross'])->toBe($expected)
            ->and(data_get($snapshot, 'accrual.evidence.units'))->toBe($expectedUnits)
            ->and(data_get($snapshot, 'accrual.evidence.paid_leave_request_ids'))->toBe([$leaveIds['2026-09-02'], $leaveIds['2026-09-03']])
            ->and(array_keys(data_get($snapshot, 'accrual.evidence.earning_by_date')))->toBe(['2026-09-01', '2026-09-02', '2026-09-03']);
    }

    DB::table('hr_attendance_daily_records')->insert([
        'employee_id' => $employee->getKey(),
        'company_id' => $fixture['company']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'shift_id' => $shift->getKey(),
        'work_date' => '2026-09-05',
        'check_in_at' => '2026-09-05 08:00:00',
        'check_out_at' => '2026-09-05 12:00:00',
        'worked_minutes' => 240,
        'status' => 'present',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $partialLeaveId = DB::table('hr_leave_requests')->insertGetId([
        'employee_id' => $employee->getKey(),
        'leave_type_id' => $leaveType->getKey(),
        'payment_status' => 'paid',
        'status' => 'approved',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('hr_leave_request_days')->insert([
        'leave_request_id' => $partialLeaveId,
        'leave_date' => '2026-09-05',
        'day_fraction' => '0.5000',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $employee->update(['pay_basis' => 'hourly_wage', 'hourly_wage' => '50.0000']);
    $partialDay = app(PayrollCalculationService::class)->calculate($fixture['company']->getKey(), [
        'period_start' => '2026-09-01',
        'period_end' => '2026-09-07',
        'branch_doc_num' => $fixture['branch']->doc_num,
    ]);
    $partialSnapshot = json_decode((string) DB::table('hr_payslip_items')
        ->where('payroll_item_id', DB::table('hr_payroll_items')->where('code', 'BASIC')->value('id'))
        ->value('source_snapshot'), true, 512, JSON_THROW_ON_ERROR);
    expect($partialDay['gross'])->toBe('1400.0000')
        ->and(data_get($partialSnapshot, 'accrual.evidence.paid_leave_minutes'))->toBe(960)
        ->and(data_get($partialSnapshot, 'accrual.evidence.paid_leave_request_ids'))->toContain($partialLeaveId)
        ->and(data_get($partialSnapshot, 'accrual.evidence.earning_by_date.2026-09-05'))->toBe('400.0000');

    $employee->update(['pay_basis' => 'shift_wage', 'shift_wage' => '600.0000']);
    DB::table('hr_employee_shift_assignments')->where('employee_id', $employee->getKey())->delete();
    expect(fn () => app(PayrollCalculationService::class)->calculate($fixture['company']->getKey(), [
        'period_start' => '2026-09-01',
        'period_end' => '2026-09-07',
        'branch_doc_num' => $fixture['branch']->doc_num,
    ]))->toThrow(DomainException::class, __('hr_payroll.messages.paid_leave_shift_assignment_required', [
        'employee' => $employee->doc_num, 'date' => '2026-09-02',
    ]));

    expect(fn () => app(PayrollCalculationService::class)->calculate($fixture['company']->getKey(), [
        'period_start' => '2026-09-08',
        'period_end' => '2026-09-10',
        'branch_doc_num' => $fixture['branch']->doc_num,
    ]))->toThrow(DomainException::class, __('hr_payroll.messages.accrual_evidence_required', ['pay_basis' => 'shift_wage']));
});

test('new or changed approved leave requires payroll recalculation before review or posting', function (): void {
    $fixture = payrollAttendancePolicyFixture();
    $companyId = $fixture['company']->getKey();
    $payload = [
        'period_start' => '2026-09-01',
        'period_end' => '2026-09-30',
        'branch_doc_num' => $fixture['branch']->doc_num,
    ];
    $calculations = app(PayrollCalculationService::class);
    $lifecycle = app(PayrollLifecycleService::class);
    $runId = $calculations->calculate($companyId, $payload)['run_id'];
    $leaveType = HrLeaveType::query()->create([
        'code' => 'LATE-PAYROLL-LEAVE',
        'name' => 'Synthetic late approved leave',
        'status' => 'active',
        'metadata' => ['payment_status' => 'paid', 'requires_balance' => false],
    ]);
    $leaveId = DB::table('hr_leave_requests')->insertGetId([
        'employee_id' => $fixture['employee']->getKey(),
        'leave_type_id' => $leaveType->getKey(),
        'payment_status' => 'paid',
        'status' => 'approved',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $leaveDayId = DB::table('hr_leave_request_days')->insertGetId([
        'leave_request_id' => $leaveId,
        'leave_date' => '2026-09-10',
        'day_fraction' => '1.0000',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => $lifecycle->submitForReview($runId, $companyId))
        ->toThrow(DomainException::class, __('hr_payroll.messages.leave_evidence_changed'));
    expect(DB::table('hr_payroll_runs')->where('id', $runId)->value('status'))->toBe('calculated');

    $calculations->calculate($companyId, $payload);
    $lifecycle->submitForReview($runId, $companyId);
    DB::table('hr_leave_request_days')->where('id', $leaveDayId)->update(['day_fraction' => '0.5000']);

    expect(fn () => $lifecycle->approve($runId, $companyId))
        ->toThrow(DomainException::class, __('hr_payroll.messages.leave_evidence_changed'));
    expect(DB::table('hr_payroll_runs')->where('id', $runId)->value('status'))->toBe('under_review')
        ->and(DB::table('hr_payroll_postings')->where('payroll_run_id', $runId)->exists())->toBeFalse();

    $lifecycle->returnForRecalculation($runId, $companyId);
    expect(DB::table('hr_payroll_runs')->where('id', $runId)->value('status'))->toBe('calculated');
    $calculations->calculate($companyId, $payload);
    $lifecycle->submitForReview($runId, $companyId);

    $snapshot = json_decode((string) DB::table('hr_payroll_attendance_inputs')
        ->where('payroll_run_id', $runId)->value('payload'), true, 512, JSON_THROW_ON_ERROR);
    expect(data_get($snapshot, 'canonical_leave_sources.0'))->toMatchArray([
        'leave_day_id' => $leaveDayId,
        'leave_request_id' => $leaveId,
        'day_fraction' => '0.5000',
    ])->and(DB::table('hr_payroll_runs')->where('id', $runId)->value('status'))->toBe('under_review');
});

test('dated hourly wage versions split one payroll without reading the mutable card rate', function (): void {
    $fixture = payrollAttendancePolicyFixture();
    $employee = $fixture['employee'];
    $employee->update(['pay_basis' => 'hourly_wage', 'hourly_wage' => '999.0000']);
    HrPayrollAttendancePolicy::query()->create([
        'company_id' => $fixture['company']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'branch_scope_key' => 'branch:'.$fixture['branch']->getKey(),
        'effective_from' => '2026-01-01',
        'hourly_accrual_method' => HrPayrollAttendancePolicy::HourlyFinalizedMinutes,
        'status' => 'active',
    ]);
    DB::table('hr_employee_salary_assignments')->where('employee_id', $employee->getKey())->update([
        'effective_to' => '2026-09-10',
        'pay_basis' => 'hourly_wage',
        'hourly_wage' => '50.0000',
    ]);
    DB::table('hr_employee_salary_assignments')->insert([
        'employee_id' => $employee->getKey(),
        'effective_from' => '2026-09-11',
        'pay_basis' => 'hourly_wage',
        'hourly_wage' => '80.0000',
        'basic_salary' => '0.00',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    foreach (['2026-09-10', '2026-09-11'] as $date) {
        DB::table('hr_attendance_daily_records')->insert([
            'employee_id' => $employee->getKey(),
            'company_id' => $fixture['company']->getKey(),
            'branch_id' => $fixture['branch']->getKey(),
            'work_date' => $date,
            'check_in_at' => $date.' 08:00:00',
            'check_out_at' => $date.' 16:00:00',
            'worked_minutes' => 480,
            'status' => 'present',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $result = app(PayrollCalculationService::class)->calculate($fixture['company']->getKey(), [
        'period_start' => '2026-09-01',
        'period_end' => '2026-09-30',
        'branch_doc_num' => $fixture['branch']->doc_num,
    ]);
    $basicSnapshots = DB::table('hr_payslip_items as item')
        ->join('hr_payroll_items as kind', 'kind.id', '=', 'item.payroll_item_id')
        ->where('kind.code', 'BASIC')
        ->orderBy('item.id')
        ->get(['item.amount', 'item.source_snapshot'])
        ->map(fn (object $item): array => [
            'amount' => bcadd((string) $item->amount, '0', 4),
            'rate' => data_get(json_decode($item->source_snapshot, true, 512, JSON_THROW_ON_ERROR), 'base_rate'),
        ])->all();

    expect($result['gross'])->toBe('1040.0000')
        ->and($basicSnapshots)->toBe([
            ['amount' => '400.0000', 'rate' => '50.0000'],
            ['amount' => '640.0000', 'rate' => '80.0000'],
        ]);

    DB::table('hr_employee_salary_assignments')->where('employee_id', $employee->getKey())
        ->update(['components' => json_encode(['items' => []], JSON_THROW_ON_ERROR)]);
    foreach (['2026-09-10', '2026-09-11'] as $date) {
        DB::table('hr_attendance_daily_records')->where('employee_id', $employee->getKey())
            ->where('work_date', $date)
            ->update(['check_out_at' => $date.' 17:00:00', 'worked_minutes' => 540, 'overtime_minutes' => 60]);
        HrEmployeeServiceRequest::query()->create([
            'employee_id' => $employee->getKey(),
            'company_id' => $fixture['company']->getKey(),
            'branch_id' => $fixture['branch']->getKey(),
            'request_type' => 'overtime',
            'details' => 'Synthetic dated wage overtime',
            'requested_from' => $date,
            'requested_to' => $date,
            'requested_minutes' => 60,
            'status' => HrEmployeeServiceRequest::StatusApproved,
            'submitted_at' => now(),
            'resolved_at' => now(),
        ]);
    }
    $result = app(PayrollCalculationService::class)->calculate($fixture['company']->getKey(), [
        'period_start' => '2026-09-01',
        'period_end' => '2026-09-30',
        'branch_doc_num' => $fixture['branch']->doc_num,
    ]);
    $overtime = DB::table('hr_payslip_items')->where('source_type', 'approved_overtime')->orderBy('id')->get();
    expect($result['gross'])->toBe('1170.0000')
        ->and($overtime->pluck('amount')->map(fn (mixed $amount): string => bcadd((string) $amount, '0', 4))->all())
        ->toBe(['50.0000', '80.0000']);

    DB::table('hr_employee_salary_assignments')->where('employee_id', $employee->getKey())
        ->where('effective_from', '2026-09-11')->update(['hourly_wage' => '90.0000']);
    expect(fn () => app(PayrollLifecycleService::class)->submitForReview(
        $result['run_id'], $fixture['company']->getKey(),
    ))->toThrow(DomainException::class, __('hr_payroll.messages.wage_evidence_changed'));
    expect(DB::table('hr_payroll_runs')->where('id', $result['run_id'])->value('status'))->toBe('calculated');
});

test('a dated daily to hourly wage-basis transition calculates each sourced segment once', function (): void {
    $fixture = payrollAttendancePolicyFixture();
    $employee = $fixture['employee'];
    $employee->update(['pay_basis' => 'hourly_wage', 'hourly_wage' => '999.0000']);
    HrPayrollAttendancePolicy::query()->create([
        'company_id' => $fixture['company']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'branch_scope_key' => 'branch:'.$fixture['branch']->getKey(),
        'effective_from' => '2026-01-01',
        'daily_accrual_method' => HrPayrollAttendancePolicy::DailyFinalizedAttendance,
        'hourly_accrual_method' => HrPayrollAttendancePolicy::HourlyFinalizedMinutes,
        'status' => 'active',
    ]);
    DB::table('hr_employee_salary_assignments')->where('employee_id', $employee->getKey())->update([
        'effective_to' => '2026-09-10',
        'basic_salary' => '0.00',
        'pay_basis' => 'daily_wage',
        'daily_wage' => '300.0000',
        'components' => json_encode(['items' => []], JSON_THROW_ON_ERROR),
    ]);
    DB::table('hr_employee_salary_assignments')->insert([
        'employee_id' => $employee->getKey(),
        'effective_from' => '2026-09-11',
        'pay_basis' => 'hourly_wage',
        'hourly_wage' => '50.0000',
        'basic_salary' => '0.00',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    foreach (['2026-09-10', '2026-09-11'] as $date) {
        DB::table('hr_attendance_daily_records')->insert([
            'employee_id' => $employee->getKey(),
            'company_id' => $fixture['company']->getKey(),
            'branch_id' => $fixture['branch']->getKey(),
            'work_date' => $date,
            'check_in_at' => $date.' 08:00:00',
            'check_out_at' => $date.' 16:00:00',
            'worked_minutes' => 480,
            'status' => 'present',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $result = app(PayrollCalculationService::class)->calculate($fixture['company']->getKey(), [
        'period_start' => '2026-09-01',
        'period_end' => '2026-09-30',
        'branch_doc_num' => $fixture['branch']->doc_num,
    ]);
    $basic = DB::table('hr_payslip_items as item')
        ->join('hr_payroll_items as kind', 'kind.id', '=', 'item.payroll_item_id')
        ->where('kind.code', 'BASIC')
        ->orderBy('item.id')
        ->get(['item.amount', 'item.source_snapshot'])
        ->map(fn (object $item): array => [
            'amount' => bcadd((string) $item->amount, '0', 4),
            'basis' => data_get(json_decode($item->source_snapshot, true, 512, JSON_THROW_ON_ERROR), 'pay_basis'),
        ])->all();

    expect($result['gross'])->toBe('700.0000')
        ->and($basic)->toBe([
            ['amount' => '300.0000', 'basis' => 'daily_wage'],
            ['amount' => '400.0000', 'basis' => 'hourly_wage'],
        ]);

    expect(app(PayrollLifecycleService::class)->submitForReview(
        $result['run_id'], $fixture['company']->getKey(),
    )->status)->toBe('under_review');
});

test('weekly daily hourly and shift pay use approved evidence while piece pay requires completed run output', function (): void {
    $fixture = payrollAttendancePolicyFixture();
    $shift = HrShift::query()->create([
        'doc_number' => 8810,
        'doc_num' => 'PAY-POL-SHIFT-08810',
        'name' => 'Payroll evidence shift',
        'start_time' => '08:00:00',
        'end_time' => '16:00:00',
        'break_minutes' => 0,
        'crosses_midnight' => false,
        'status' => 'active',
    ]);
    HrPayrollAttendancePolicy::query()->create([
        'company_id' => $fixture['company']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'branch_scope_key' => 'branch:'.$fixture['branch']->getKey(),
        'effective_from' => '2026-01-01',
        'weekly_accrual_method' => HrPayrollAttendancePolicy::WeeklyCalendarDays,
        'daily_accrual_method' => HrPayrollAttendancePolicy::DailyFinalizedAttendance,
        'hourly_accrual_method' => HrPayrollAttendancePolicy::HourlyFinalizedMinutes,
        'shift_accrual_method' => HrPayrollAttendancePolicy::ShiftFinalizedAttendance,
        'piece_accrual_method' => HrPayrollAttendancePolicy::PieceApprovedOutput,
        'salary_day_divisor' => 30,
        'standard_day_minutes' => 480,
        'status' => 'active',
    ]);
    DB::table('hr_attendance_daily_records')->insert([
        'employee_id' => $fixture['employee']->getKey(),
        'company_id' => $fixture['company']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'shift_id' => $shift->getKey(),
        'work_date' => '2026-09-01',
        'check_in_at' => '2026-09-01 08:00:00',
        'check_out_at' => '2026-09-01 16:00:00',
        'worked_minutes' => 480,
        'status' => 'present',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    foreach ([
        'weekly_wage' => ['weekly_wage' => '700.0000', 'expected' => '700.0000'],
        'daily_wage' => ['daily_wage' => '300.0000', 'expected' => '300.0000'],
        'hourly_wage' => ['hourly_wage' => '50.0000', 'expected' => '400.0000'],
        'shift_wage' => ['shift_wage' => '600.0000', 'expected' => '600.0000'],
    ] as $basis => $case) {
        $fixture['employee']->update(['pay_basis' => $basis, array_key_first($case) => $case[array_key_first($case)]]);
        $result = app(PayrollCalculationService::class)->calculate($fixture['company']->getKey(), [
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-07',
            'branch_doc_num' => $fixture['branch']->doc_num,
        ]);
        $snapshot = json_decode((string) DB::table('hr_payslip_items')->where('payroll_item_id', DB::table('hr_payroll_items')->where('code', 'BASIC')->value('id'))->value('source_snapshot'), true, 512, JSON_THROW_ON_ERROR);

        expect($result['gross'])->toBe($case['expected'])
            ->and(data_get($snapshot, 'accrual.pay_basis'))->toBe($basis);
    }

    HrPayrollAttendancePolicy::query()->where('company_id', $fixture['company']->getKey())->update([
        'weekly_accrual_method' => HrPayrollAttendancePolicy::WeeklyFinalizedAttendance,
        'weekly_work_days' => 5,
    ]);
    $fixture['employee']->update(['pay_basis' => 'weekly_wage', 'weekly_wage' => '700.0000']);
    $attendanceBasedWeek = app(PayrollCalculationService::class)->calculate($fixture['company']->getKey(), [
        'period_start' => '2026-09-01',
        'period_end' => '2026-09-07',
        'branch_doc_num' => $fixture['branch']->doc_num,
    ]);
    $weeklySnapshot = json_decode((string) DB::table('hr_payslip_items')->where('payroll_item_id', DB::table('hr_payroll_items')->where('code', 'BASIC')->value('id'))->value('source_snapshot'), true, 512, JSON_THROW_ON_ERROR);
    expect($attendanceBasedWeek['gross'])->toBe('140.0000')
        ->and(data_get($weeklySnapshot, 'accrual.method'))->toBe(HrPayrollAttendancePolicy::WeeklyFinalizedAttendance)
        ->and(data_get($weeklySnapshot, 'accrual.evidence.attendance_record_ids'))->toHaveCount(1)
        ->and(data_get($weeklySnapshot, 'accrual.evidence.weekly_work_days'))->toBe(5);
    expect(fn () => app(PayrollCalculationService::class)->calculate($fixture['company']->getKey(), [
        'period_start' => '2026-09-08',
        'period_end' => '2026-09-14',
        'branch_doc_num' => $fixture['branch']->doc_num,
    ]))->toThrow(DomainException::class, __('hr_payroll.messages.accrual_evidence_required', ['pay_basis' => 'weekly_wage']));

    HrPayrollAttendancePolicy::query()->where('company_id', $fixture['company']->getKey())->update([
        'daily_accrual_method' => HrPayrollAttendancePolicy::DailyCalendarDays,
    ]);
    $fixture['employee']->update(['pay_basis' => 'daily_wage', 'daily_wage' => '300.0000']);
    $calendarDaily = app(PayrollCalculationService::class)->calculate($fixture['company']->getKey(), [
        'period_start' => '2026-09-01',
        'period_end' => '2026-09-07',
        'branch_doc_num' => $fixture['branch']->doc_num,
    ]);
    expect($calendarDaily['gross'])->toBe('2100.0000');

    HrPayrollAttendancePolicy::query()->where('company_id', $fixture['company']->getKey())->update([
        'hourly_rounding_mode' => 'nearest',
        'hourly_rounding_increment_minutes' => 15,
    ]);
    DB::table('hr_attendance_daily_records')->where('employee_id', $fixture['employee']->getKey())->update([
        'check_out_at' => '2026-09-01 15:47:00',
        'worked_minutes' => 467,
    ]);
    $fixture['employee']->update(['pay_basis' => 'hourly_wage', 'hourly_wage' => '50.0000']);
    $rounded = app(PayrollCalculationService::class)->calculate($fixture['company']->getKey(), [
        'period_start' => '2026-09-01',
        'period_end' => '2026-09-07',
        'branch_doc_num' => $fixture['branch']->doc_num,
    ]);
    $roundedSnapshot = json_decode((string) DB::table('hr_payslip_items')
        ->where('payroll_item_id', DB::table('hr_payroll_items')->where('code', 'BASIC')->value('id'))
        ->value('source_snapshot'), true, 512, JSON_THROW_ON_ERROR);
    expect($rounded['gross'])->toBe('387.5000')
        ->and(data_get($roundedSnapshot, 'accrual.evidence.raw_worked_minutes'))->toBe(467)
        ->and(data_get($roundedSnapshot, 'accrual.evidence.worked_minutes'))->toBe(465)
        ->and(data_get($roundedSnapshot, 'accrual.evidence.rounding_records.0.policy_id'))->toBeGreaterThan(0);

    foreach (['down' => '375.0000', 'up' => '400.0000'] as $mode => $expected) {
        HrPayrollAttendancePolicy::query()->where('company_id', $fixture['company']->getKey())->update([
            'hourly_rounding_mode' => $mode,
            'hourly_rounding_increment_minutes' => 30,
        ]);
        $result = app(PayrollCalculationService::class)->calculate($fixture['company']->getKey(), [
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-07',
            'branch_doc_num' => $fixture['branch']->doc_num,
        ]);
        expect($result['gross'])->toBe($expected);
    }

    $fixture['employee']->update(['pay_basis' => 'piece_rate', 'piece_rate' => '10.0000']);
    expect(fn () => app(PayrollCalculationService::class)->calculate($fixture['company']->getKey(), [
        'period_start' => '2026-09-01',
        'period_end' => '2026-09-07',
        'branch_doc_num' => $fixture['branch']->doc_num,
    ]))->toThrow(DomainException::class, __('hr_payroll.messages.accrual_evidence_required', [
        'pay_basis' => 'piece_rate',
    ]));
});

test('scheduled weekly and daily wages require an explicit assigned calendar and pay only selected work or holiday days', function (): void {
    $fixture = payrollAttendancePolicyFixture();
    $employee = $fixture['employee'];
    $calendarId = DB::table('hr_work_calendars')->insertGetId([
        'company_id' => $fixture['company']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'code' => 'PAY-SCHEDULE-1',
        'name' => 'Synthetic payroll schedule',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $assignmentId = DB::table('hr_work_calendar_assignments')->insertGetId([
        'employee_id' => $employee->getKey(),
        'calendar_id' => $calendarId,
        'effective_from' => '2026-09-01',
        'effective_to' => '2026-09-03',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    foreach (['2026-09-01' => 'working', '2026-09-02' => 'holiday_paid', '2026-09-03' => 'off'] as $date => $type) {
        DB::table('hr_work_calendar_days')->insert([
            'calendar_id' => $calendarId,
            'work_date' => $date,
            'day_type' => $type,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
    $policy = HrPayrollAttendancePolicy::query()->create([
        'company_id' => $fixture['company']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'branch_scope_key' => 'branch:'.$fixture['branch']->getKey(),
        'effective_from' => '2026-09-01',
        'weekly_accrual_method' => HrPayrollAttendancePolicy::WeeklyScheduledWorkAndPaidHoliday,
        'weekly_work_days' => 5,
        'daily_accrual_method' => HrPayrollAttendancePolicy::DailyScheduledWorkAndPaidHoliday,
        'salary_day_divisor' => 30,
        'standard_day_minutes' => 480,
        'status' => 'active',
    ]);

    foreach ([
        ['basis' => 'weekly_wage', 'field' => 'weekly_wage', 'rate' => '700.0000', 'expected' => '280.0000'],
        ['basis' => 'daily_wage', 'field' => 'daily_wage', 'rate' => '300.0000', 'expected' => '600.0000'],
    ] as $case) {
        $employee->update(['pay_basis' => $case['basis'], $case['field'] => $case['rate']]);
        $result = app(PayrollCalculationService::class)->calculate($fixture['company']->getKey(), [
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-03',
            'branch_doc_num' => $fixture['branch']->doc_num,
        ]);
        $snapshot = json_decode((string) DB::table('hr_payslip_items')
            ->where('payroll_item_id', DB::table('hr_payroll_items')->where('code', 'BASIC')->value('id'))
            ->value('source_snapshot'), true, 512, JSON_THROW_ON_ERROR);
        expect($result['gross'])->toBe($case['expected'])
            ->and(data_get($snapshot, 'accrual.evidence.calendar_days'))->toHaveCount(3)
            ->and(data_get($snapshot, 'accrual.evidence.calendar_days.0.assignment_id'))->toBe($assignmentId)
            ->and(data_get($snapshot, 'accrual.evidence.calendar_days.1.day_type'))->toBe('holiday_paid');
    }

    $policy->update([
        'weekly_accrual_method' => HrPayrollAttendancePolicy::WeeklyScheduledWork,
        'daily_accrual_method' => HrPayrollAttendancePolicy::DailyScheduledWork,
    ]);
    $employee->update(['pay_basis' => 'weekly_wage', 'weekly_wage' => '700.0000']);
    expect(app(PayrollCalculationService::class)->calculate($fixture['company']->getKey(), [
        'period_start' => '2026-09-01', 'period_end' => '2026-09-03', 'branch_doc_num' => $fixture['branch']->doc_num,
    ])['gross'])->toBe('140.0000');
    $employee->update(['pay_basis' => 'daily_wage', 'daily_wage' => '300.0000']);
    expect(app(PayrollCalculationService::class)->calculate($fixture['company']->getKey(), [
        'period_start' => '2026-09-01', 'period_end' => '2026-09-03', 'branch_doc_num' => $fixture['branch']->doc_num,
    ])['gross'])->toBe('300.0000');

    DB::table('hr_work_calendar_days')->where('calendar_id', $calendarId)->where('work_date', '2026-09-03')->delete();
    expect(fn () => app(PayrollCalculationService::class)->calculate($fixture['company']->getKey(), [
        'period_start' => '2026-09-01', 'period_end' => '2026-09-03', 'branch_doc_num' => $fixture['branch']->doc_num,
    ]))->toThrow(DomainException::class);
});

test('a classified paid holiday does not create an absence deduction when an absent biometric row exists', function (): void {
    $fixture = payrollAttendancePolicyFixture();
    $employee = $fixture['employee'];
    HrPayrollAttendancePolicy::query()->create([
        'company_id' => $fixture['company']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'branch_scope_key' => 'branch:'.$fixture['branch']->getKey(),
        'effective_from' => '2026-09-01',
        'deduct_absence' => true,
        'deduction_payroll_item_code' => 'ATTENDANCE-DED',
        'monthly_partial_method' => HrPayrollAttendancePolicy::MonthlyCalendarDays,
        'salary_day_divisor' => 30,
        'standard_day_minutes' => 480,
        'status' => 'active',
    ]);
    $calendarId = DB::table('hr_work_calendars')->insertGetId([
        'company_id' => $fixture['company']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'code' => 'PAY-ABS-SCHEDULE', 'name' => 'Synthetic absence schedule', 'status' => 'active',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('hr_work_calendar_assignments')->insert([
        'employee_id' => $employee->getKey(), 'calendar_id' => $calendarId,
        'effective_from' => '2026-09-01', 'effective_to' => '2026-09-02',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    foreach (['2026-09-01' => 'working', '2026-09-02' => 'holiday_paid'] as $date => $dayType) {
        DB::table('hr_work_calendar_days')->insert([
            'calendar_id' => $calendarId, 'work_date' => $date, 'day_type' => $dayType,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('hr_attendance_daily_records')->insert([
            'employee_id' => $employee->getKey(), 'company_id' => $fixture['company']->getKey(),
            'branch_id' => $fixture['branch']->getKey(), 'work_date' => $date,
            'worked_minutes' => 0, 'status' => 'absent',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $result = app(PayrollCalculationService::class)->calculate($fixture['company']->getKey(), [
        'period_start' => '2026-09-01', 'period_end' => '2026-09-30',
        'branch_doc_num' => $fixture['branch']->doc_num,
    ]);
    expect($result['gross'])->toBe('9000.0000')
        ->and($result['deductions'])->toBe('300.0000')
        ->and($result['payable'])->toBe('8700.0000');
});

test('salary-time absence deduction uses the scheduled daily wage rate', function (): void {
    $fixture = payrollAttendancePolicyFixture();
    $employee = $fixture['employee'];
    $employee->update(['pay_basis' => 'daily_wage', 'daily_wage' => '300.0000']);
    HrPayrollAttendancePolicy::query()->create([
        'company_id' => $fixture['company']->getKey(), 'branch_id' => $fixture['branch']->getKey(),
        'branch_scope_key' => 'branch:'.$fixture['branch']->getKey(), 'effective_from' => '2026-09-01',
        'deduct_absence' => true, 'deduction_payroll_item_code' => 'ATTENDANCE-DED',
        'daily_accrual_method' => HrPayrollAttendancePolicy::DailyScheduledWork,
        'salary_day_divisor' => 30, 'standard_day_minutes' => 480, 'status' => 'active',
    ]);
    $calendarId = DB::table('hr_work_calendars')->insertGetId([
        'company_id' => $fixture['company']->getKey(), 'branch_id' => $fixture['branch']->getKey(),
        'code' => 'PAY-DAILY-SCHEDULE', 'name' => 'Synthetic daily schedule', 'status' => 'active',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('hr_work_calendar_assignments')->insert([
        'employee_id' => $employee->getKey(), 'calendar_id' => $calendarId,
        'effective_from' => '2026-09-01', 'effective_to' => '2026-09-01',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('hr_work_calendar_days')->insert([
        'calendar_id' => $calendarId, 'work_date' => '2026-09-01', 'day_type' => 'working',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('hr_attendance_daily_records')->insert([
        'employee_id' => $employee->getKey(), 'company_id' => $fixture['company']->getKey(),
        'branch_id' => $fixture['branch']->getKey(), 'work_date' => '2026-09-01',
        'worked_minutes' => 0, 'status' => 'absent', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $result = app(PayrollCalculationService::class)->calculate($fixture['company']->getKey(), [
        'period_start' => '2026-09-01', 'period_end' => '2026-09-01',
        'branch_doc_num' => $fixture['branch']->doc_num,
    ]);
    expect($result['gross'])->toBe('300.0000')
        ->and($result['deductions'])->toBe('300.0000')
        ->and($result['payable'])->toBe('0.0000');
});
