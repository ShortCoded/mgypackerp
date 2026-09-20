<?php

namespace Database\Seeders;

use App\Models\User;
use DomainException;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use LogicException;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\AccountClassification;
use Modules\Accounting\Models\CostCenter;
use Modules\Accounting\Services\AccountClassificationRegistry;
use Modules\Auth\Models\Role;
use Modules\Auth\Services\PermissionRegistryService;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\Currency;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\NumericFormatService;
use Modules\Finance\Models\Cashbox;
use Modules\Finance\Models\CashboxCurrency;
use Modules\Finance\Models\CashVoucher;
use Modules\Finance\Services\CashVoucherService;
use Modules\HR\Models\HrDepartment;
use Modules\HR\Models\HrDepartmentCostCenterDefault;
use Modules\HR\Models\HrEmployee;
use Modules\HR\Models\HrEmployeeServiceRequest;
use Modules\HR\Models\HrEmployeeShiftAssignment;
use Modules\HR\Models\HrEmploymentType;
use Modules\HR\Models\HrJob;
use Modules\HR\Models\HrLeaveType;
use Modules\HR\Models\HrPayrollAttendancePolicy;
use Modules\HR\Models\HrSection;
use Modules\HR\Models\HrShift;
use Modules\HR\Services\PayrollCalculationService;
use Modules\HR\Services\PayrollLifecycleService;
use Modules\HR\Services\PayrollPaymentService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class HrFullCycleBrowserE2eSeeder extends Seeder
{
    public const Marker = 'HR-E2E-FULL-CYCLE';

    private const PayrollStart = '2026-09-01';

    private const PayrollEnd = '2026-09-30';

    private const PaymentIdempotencyKey = '00000000-0000-4000-8000-000000998801';

    public function run(): void
    {
        $this->assertSafeEnvironment();
        $credentials = $this->credentials();

        $previousActor = Auth::user();

        try {
            DB::transaction(function () use ($credentials): void {
                $this->ensurePermissions();
                app(AccountClassificationRegistry::class)->synchronize();

                $company = $this->company();
                $branch = $this->branch($company);
                $period = $this->financialPeriod($company);
                $currency = $this->currency($company);
                $role = $this->role($company, $branch, $period);
                $operator = $this->operator($credentials, $company, $branch, $period, $role);
                $reviewer = $this->reviewer($company, $branch, $period, $role);

                Auth::login($operator);

                $accounts = $this->accounts($company);
                [$department, $section, $job, $employmentType, $shift] = $this->employmentMasters($operator);
                $costCenter = $this->costCenter($company, $operator);
                HrDepartmentCostCenterDefault::query()->updateOrCreate(
                    ['company_id' => $company->getKey(), 'department_id' => $department->getKey()],
                    ['cost_center_id' => $costCenter->getKey()],
                );

                $employee = $this->employee(
                    $company,
                    $branch,
                    $currency,
                    $operator,
                    $department,
                    $section,
                    $job,
                    $employmentType,
                    $shift,
                );

                $this->salary($employee, $operator);
                $this->shiftAssignment($employee, $shift, $operator);
                $leaveTypes = $this->leaveTypes($operator);
                $this->leaveScenario($employee, $leaveTypes, $operator, $reviewer);
                $this->attendanceScenario($employee, $company, $branch);
                $this->payrollPolicy($company, $branch, $operator);
                $this->payrollItems();
                $cashbox = $this->cashbox($company, $branch, $currency, $accounts['cash'], $operator);

                $this->completePayroll($company, $branch, $cashbox);
            }, attempts: 3);
        } finally {
            if ($previousActor instanceof User) {
                Auth::login($previousActor);
            } else {
                Auth::logout();
            }
        }
    }

    private function assertSafeEnvironment(): void
    {
        $environment = (string) config('app.env');

        if (! in_array($environment, ['local', 'testing'], true)) {
            throw new LogicException('The HR full-cycle E2E fixture is restricted to local and testing environments.');
        }

        if ($environment === 'local' && ! filter_var(env('HR_E2E_FIXTURE_ENABLED', false), FILTER_VALIDATE_BOOL)) {
            throw new LogicException('Set HR_E2E_FIXTURE_ENABLED=true explicitly before creating the local HR E2E fixture.');
        }
    }

    private function ensurePermissions(): void
    {
        $now = now();
        collect(app(PermissionRegistryService::class)->all())
            ->map(fn (string $permission): array => [
                'name' => $permission,
                'guard_name' => 'web',
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->chunk(500)
            ->each(fn ($permissions): int => Permission::query()->upsert(
                $permissions->all(),
                ['name', 'guard_name'],
                [],
            ));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** @return array{username: string, email: string, password: string} */
    private function credentials(): array
    {
        if (config('app.env') === 'testing') {
            return [
                'username' => 'hr_e2e_operator',
                'email' => 'hr-e2e-operator@example.test',
                'password' => 'testing-only-hr-e2e-password',
            ];
        }

        $username = trim((string) env('HR_E2E_USERNAME', ''));
        $email = trim((string) env('HR_E2E_EMAIL', ''));
        $password = (string) env('HR_E2E_PASSWORD', '');

        if ($username === '' || $email === '' || mb_strlen($password) < 12) {
            throw new LogicException('HR_E2E_USERNAME, HR_E2E_EMAIL, and an HR_E2E_PASSWORD of at least 12 characters are required.');
        }

        return compact('username', 'email', 'password');
    }

    private function company(): Company
    {
        return Company::withTrashed()->updateOrCreate(
            ['doc_num' => 'Company-'.self::Marker],
            [
                'doc_number' => 998801,
                'name' => 'HR E2E Factory',
                'legal_name' => 'HR E2E Factory',
                'status' => 'active',
                'is_main' => false,
                'country' => 'Egypt',
                'notes' => self::Marker,
                'deleted_at' => null,
            ],
        );
    }

    private function branch(Company $company): Branch
    {
        return Branch::withTrashed()->updateOrCreate(
            ['doc_num' => 'Branch-'.self::Marker],
            [
                'doc_number' => 998801,
                'company_id' => $company->getKey(),
                'name' => 'HR E2E Factory Branch',
                'type' => Branch::TypeFactory,
                'attendance_latitude' => '30.0444000',
                'attendance_longitude' => '31.2357000',
                'attendance_radius_meters' => 150,
                'attendance_max_accuracy_meters' => 50,
                'attendance_location_policy' => 'required',
                'status' => 'active',
                'notes' => self::Marker,
                'deleted_at' => null,
            ],
        );
    }

    private function financialPeriod(Company $company): FinancialPeriod
    {
        return FinancialPeriod::withTrashed()->updateOrCreate(
            ['doc_num' => 'FinancialPeriod-'.self::Marker],
            [
                'doc_number' => 998801,
                'company_id' => $company->getKey(),
                'name' => 'HR E2E 2026',
                'from_date' => '2026-01-01',
                'to_date' => '2026-12-31',
                'is_closed' => false,
                'allows_opening_entries' => true,
                'notes' => self::Marker,
                'deleted_at' => null,
            ],
        );
    }

    private function currency(Company $company): Currency
    {
        return Currency::withTrashed()->updateOrCreate(
            ['company_id' => $company->getKey(), 'code' => 'EGP'],
            [
                'doc_number' => 998801,
                'doc_num' => 'Currency-'.self::Marker,
                'name' => 'الجنيه المصري',
                'minor_unit_name' => 'قرش',
                'minor_unit_factor' => 100,
                'is_main' => true,
                'status' => 'active',
                'notes' => self::Marker,
                'deleted_at' => null,
            ],
        );
    }

    private function role(Company $company, Branch $branch, FinancialPeriod $period): Role
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role = Role::withTrashed()->firstOrNew(['name' => self::Marker, 'guard_name' => 'web']);
        $role->forceFill([
            'doc_number' => 998801,
            'doc_num' => 'Role-'.self::Marker,
            'company_access_restricted' => true,
            'branch_access_restricted' => true,
            'financial_period_access_restricted' => true,
            'deleted_at' => null,
        ])->save();
        $role->syncPermissions(Permission::query()
            ->where('guard_name', 'web')
            ->whereIn('name', app(PermissionRegistryService::class)->all())
            ->get());
        $role->companyAccessCompanies()->sync([$company->getKey()]);
        $role->branchAccessBranches()->sync([$branch->getKey()]);
        $role->financialPeriodAccessPeriods()->sync([$period->getKey()]);

        return $role;
    }

    /** @param array{username: string, email: string, password: string} $credentials */
    private function operator(array $credentials, Company $company, Branch $branch, FinancialPeriod $period, Role $role): User
    {
        $conflict = User::withTrashed()
            ->where(fn ($query) => $query->where('email', $credentials['email'])->orWhere('username', $credentials['username']))
            ->where('doc_num', '<>', 'User-'.self::Marker)
            ->exists();
        if ($conflict) {
            throw new LogicException('The requested HR E2E credentials belong to a non-fixture user.');
        }

        $user = User::withTrashed()->updateOrCreate(
            ['doc_num' => 'User-'.self::Marker],
            [
                'doc_number' => 998801,
                'name' => 'HR E2E Operator',
                'email' => $credentials['email'],
                'username' => $credentials['username'],
                'password' => Hash::make($credentials['password']),
                'status' => 'active',
                'locale' => 'ar',
                'email_verified_at' => now(),
                'default_company_id' => $company->getKey(),
                'default_branch_id' => $branch->getKey(),
                'default_financial_period_id' => $period->getKey(),
                'notes' => self::Marker,
                'deleted_at' => null,
            ],
        );
        $user->forceFill(['email_verified_at' => $user->email_verified_at ?? now()])->save();
        $user->syncRoles([$role]);

        return $user;
    }

    private function reviewer(Company $company, Branch $branch, FinancialPeriod $period, Role $role): User
    {
        if (User::withTrashed()->where('email', 'hr-e2e-reviewer@example.test')->where('doc_num', '<>', 'User-'.self::Marker.'-REVIEWER')->exists()) {
            throw new LogicException('The reserved HR E2E reviewer identity belongs to a non-fixture user.');
        }

        $user = User::withTrashed()->updateOrCreate(
            ['doc_num' => 'User-'.self::Marker.'-REVIEWER'],
            [
                'doc_number' => 998802,
                'name' => 'HR E2E Reviewer',
                'email' => 'hr-e2e-reviewer@example.test',
                'username' => 'hr_e2e_reviewer',
                'password' => Hash::make(bin2hex(random_bytes(24))),
                'status' => 'active',
                'locale' => 'ar',
                'email_verified_at' => now(),
                'default_company_id' => $company->getKey(),
                'default_branch_id' => $branch->getKey(),
                'default_financial_period_id' => $period->getKey(),
                'notes' => self::Marker,
                'deleted_at' => null,
            ],
        );
        $user->syncRoles([$role]);

        return $user;
    }

    /** @return array{direct_labor: Account, payable: Account, cash: Account} */
    private function accounts(Company $company): array
    {
        return [
            'direct_labor' => $this->account($company, 'direct_labor_cost', 'E2E998801', 998801, 'HR E2E Direct Labor'),
            'payable' => $this->account($company, 'payroll_payable', 'E2E998802', 998802, 'HR E2E Payroll Payable'),
            'cash' => $this->account($company, 'cash', 'E2E998804', 998804, 'HR E2E Cash'),
        ];
    }

    private function account(Company $company, string $classificationCode, string $code, int $number, string $name): Account
    {
        $classification = AccountClassification::query()->where('code', $classificationCode)->firstOrFail();

        return Account::withTrashed()->updateOrCreate(
            ['company_id' => $company->getKey(), 'account_code' => $code],
            [
                'doc_number' => $number,
                'doc_num' => 'Account-'.self::Marker.'-'.$number,
                'name' => $name,
                'name_en' => $name,
                'account_classification_id' => $classification->getKey(),
                'account_type' => $classification->account_type,
                'statement_type' => $classification->statement_type,
                'normal_balance' => $classification->normal_balance,
                'is_group' => false,
                'is_postable' => true,
                'status' => 'active',
                'deleted_at' => null,
            ],
        );
    }

    /** @return array{HrDepartment, HrSection, HrJob, HrEmploymentType, HrShift} */
    private function employmentMasters(User $actor): array
    {
        $audit = ['created_by' => $actor->getKey(), 'updated_by' => $actor->getKey(), 'deleted_at' => null];
        $department = HrDepartment::withTrashed()->updateOrCreate(
            ['doc_num' => 'HrDepartment-'.self::Marker],
            ['doc_number' => 998801, 'code' => 'HR-E2E-DEPT', 'name' => 'إدارة اختبار دورة الموارد البشرية', 'status' => 'active', 'notes' => self::Marker, ...$audit],
        );
        $section = HrSection::withTrashed()->updateOrCreate(
            ['doc_num' => 'HrSection-'.self::Marker],
            ['doc_number' => 998801, 'department_id' => $department->getKey(), 'code' => 'HR-E2E-SEC', 'name' => 'قسم الاختبار', 'status' => 'active', 'notes' => self::Marker, ...$audit],
        );
        $job = HrJob::withTrashed()->updateOrCreate(
            ['doc_num' => 'HrJob-'.self::Marker],
            ['doc_number' => 998801, 'code' => 'HR-E2E-JOB', 'name' => 'مشغل اختبار', 'status' => 'active', 'notes' => self::Marker, ...$audit],
        );
        $employmentType = HrEmploymentType::withTrashed()->updateOrCreate(
            ['doc_num' => 'HrEmploymentType-'.self::Marker],
            ['doc_number' => 998801, 'code' => 'HR-E2E-FULL-TIME', 'name' => 'دوام كامل - اختبار', 'status' => 'active', 'notes' => self::Marker, ...$audit],
        );
        $shift = HrShift::withTrashed()->updateOrCreate(
            ['doc_num' => 'HrShift-'.self::Marker],
            ['doc_number' => 998801, 'name' => 'وردية اختبار 08-16', 'start_time' => '08:00:00', 'end_time' => '16:00:00', 'break_minutes' => 0, 'crosses_midnight' => false, 'status' => 'active', 'notes' => self::Marker, ...$audit],
        );

        return [$department, $section, $job, $employmentType, $shift];
    }

    private function costCenter(Company $company, User $actor): CostCenter
    {
        return CostCenter::withTrashed()->updateOrCreate(
            ['company_id' => $company->getKey(), 'doc_num' => 'CostCenter-'.self::Marker],
            [
                'doc_number' => 998801,
                'cost_center_code' => '11',
                'name' => 'تكلفة عمالة اختبار HR',
                'name_en' => 'HR E2E Labor',
                'is_group' => false,
                'status' => 'active',
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
                'deleted_at' => null,
            ],
        );
    }

    private function employee(
        Company $company,
        Branch $branch,
        Currency $currency,
        User $operator,
        HrDepartment $department,
        HrSection $section,
        HrJob $job,
        HrEmploymentType $employmentType,
        HrShift $shift,
    ): HrEmployee {
        return HrEmployee::withTrashed()->updateOrCreate(
            ['company_id' => $company->getKey(), 'doc_num' => 'HrEmployee-'.self::Marker],
            [
                'doc_number' => 998801,
                'employee_code' => 'HR-E2E-001',
                'full_name' => 'موظف اختبار دورة الموارد البشرية',
                'name' => 'موظف اختبار دورة الموارد البشرية',
                'person_type' => 'fixed_employee',
                'status' => 'active',
                'gender' => 'male',
                'user_id' => $operator->getKey(),
                'branch_id' => $branch->getKey(),
                'department_id' => $department->getKey(),
                'section_id' => $section->getKey(),
                'job_id' => $job->getKey(),
                'employment_type_id' => $employmentType->getKey(),
                'hire_date' => '2026-01-01',
                'contract_start_date' => '2026-01-01',
                'attendance_tracking_enabled' => true,
                'attendance_policy_type' => 'fixed_shift',
                'default_shift_id' => $shift->getKey(),
                'overtime_enabled' => true,
                'pay_basis' => 'monthly_salary',
                'payroll_currency_id' => $currency->getKey(),
                'exchange_rate' => 1,
                'basic_salary' => '12000.00',
                'hourly_wage' => '100.0000',
                'created_by' => $operator->getKey(),
                'updated_by' => $operator->getKey(),
                'notes' => self::Marker,
                'deleted_at' => null,
            ],
        );
    }

    private function salary(HrEmployee $employee, User $actor): void
    {
        DB::table('hr_employee_salary_assignments')->updateOrInsert(
            ['employee_id' => $employee->getKey(), 'effective_from' => '2026-01-01'],
            [
                'effective_to' => null,
                'basic_salary' => '12000.00',
                'components' => json_encode(['items' => [], 'overtime_hourly_rate' => '100.0000'], JSON_THROW_ON_ERROR),
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
                'deleted_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    private function shiftAssignment(HrEmployee $employee, HrShift $shift, User $actor): void
    {
        HrEmployeeShiftAssignment::withTrashed()->updateOrCreate(
            ['employee_id' => $employee->getKey(), 'effective_from' => '2026-01-01'],
            [
                'shift_id' => $shift->getKey(),
                'effective_to' => null,
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
                'deleted_at' => null,
            ],
        );
    }

    /** @return array{paid: HrLeaveType, unpaid: HrLeaveType} */
    private function leaveTypes(User $actor): array
    {
        $paid = HrLeaveType::withTrashed()->updateOrCreate(
            ['code' => 'HR-E2E-PAID'],
            [
                'name' => 'إجازة مدفوعة - اختبار',
                'status' => 'active',
                'metadata' => ['payment_status' => 'paid', 'requires_balance' => true, 'annual_entitlement_days' => 10],
                'notes' => self::Marker,
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
                'deleted_at' => null,
            ],
        );
        $unpaid = HrLeaveType::withTrashed()->updateOrCreate(
            ['code' => 'HR-E2E-UNPAID'],
            [
                'name' => 'إجازة بدون أجر - اختبار',
                'status' => 'active',
                'metadata' => ['payment_status' => 'unpaid', 'requires_balance' => false],
                'notes' => self::Marker,
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
                'deleted_at' => null,
            ],
        );

        return compact('paid', 'unpaid');
    }

    /** @param array{paid: HrLeaveType, unpaid: HrLeaveType} $leaveTypes */
    private function leaveScenario(HrEmployee $employee, array $leaveTypes, User $operator, User $reviewer): void
    {
        DB::table('hr_leave_balances')->updateOrInsert(
            ['employee_id' => $employee->getKey(), 'leave_type_id' => $leaveTypes['paid']->getKey(), 'balance_year' => 2026],
            ['opening_balance' => 10, 'current_balance' => 9, 'created_at' => now(), 'updated_at' => now()],
        );
        $balance = DB::table('hr_leave_balances')
            ->where('employee_id', $employee->getKey())
            ->where('leave_type_id', $leaveTypes['paid']->getKey())
            ->where('balance_year', 2026)
            ->firstOrFail();
        $ledger = DB::table('hr_leave_balance_ledger')
            ->where('leave_balance_id', $balance->id)
            ->where('notes', self::Marker.':paid-leave')
            ->first();
        if ($ledger === null) {
            DB::table('hr_leave_balance_ledger')->insert([
                'leave_balance_id' => $balance->id,
                'entry_type' => 'leave_request',
                'amount' => -1,
                'notes' => self::Marker.':paid-leave',
                'posted_at' => '2026-09-01 10:00:00',
            ]);
        }

        $this->approvedLeave($employee, $leaveTypes['paid'], '2026-09-07', true, $operator, $reviewer);
        $this->approvedLeave($employee, $leaveTypes['unpaid'], '2026-09-08', false, $operator, $reviewer);

        HrEmployeeServiceRequest::query()->updateOrCreate(
            ['public_uuid' => '00000000-0000-4000-8000-000000998803'],
            [
                'employee_id' => $employee->getKey(),
                'company_id' => $employee->company_id,
                'branch_id' => $employee->branch_id,
                'request_type' => 'overtime',
                'subject' => self::Marker.' approved overtime',
                'details' => self::Marker.' deterministic overtime',
                'requested_from' => '2026-09-09',
                'requested_to' => '2026-09-09',
                'requested_minutes' => 120,
                'status' => HrEmployeeServiceRequest::StatusApproved,
                'submitted_at' => '2026-09-09 16:00:00',
                'resolved_at' => '2026-09-09 17:00:00',
                'resolved_by' => $reviewer->getKey(),
                'created_by' => $operator->getKey(),
                'updated_by' => $reviewer->getKey(),
                'deleted_at' => null,
            ],
        );
    }

    private function approvedLeave(HrEmployee $employee, HrLeaveType $leaveType, string $date, bool $requiresBalance, User $operator, User $reviewer): void
    {
        $suffix = $requiresBalance ? '998801' : '998802';
        $serviceRequest = HrEmployeeServiceRequest::query()->updateOrCreate(
            ['public_uuid' => '00000000-0000-4000-8000-000000'.$suffix],
            [
                'employee_id' => $employee->getKey(),
                'company_id' => $employee->company_id,
                'branch_id' => $employee->branch_id,
                'request_type' => 'leave',
                'subject' => self::Marker.' '.$leaveType->code,
                'details' => self::Marker.' deterministic leave',
                'requested_from' => $date,
                'requested_to' => $date,
                'status' => HrEmployeeServiceRequest::StatusApproved,
                'submitted_at' => $date.' 08:00:00',
                'resolved_at' => $date.' 09:00:00',
                'resolved_by' => $reviewer->getKey(),
                'created_by' => $operator->getKey(),
                'updated_by' => $reviewer->getKey(),
                'deleted_at' => null,
            ],
        );
        $canonical = DB::table('hr_leave_requests')
            ->where('employee_id', $employee->getKey())
            ->where('leave_type_id', $leaveType->getKey())
            ->where('reason', self::Marker.':'.$date)
            ->first();
        if ($canonical === null) {
            $canonicalId = DB::table('hr_leave_requests')->insertGetId([
                'employee_id' => $employee->getKey(),
                'leave_type_id' => $leaveType->getKey(),
                'payment_status' => $leaveType->isPaid() ? 'paid' : 'unpaid',
                'status' => 'approved',
                'reason' => self::Marker.':'.$date,
                'created_by' => $operator->getKey(),
                'updated_by' => $reviewer->getKey(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('hr_leave_request_days')->insert([
                'leave_request_id' => $canonicalId,
                'leave_date' => $date,
                'day_fraction' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $canonicalId = (int) $canonical->id;
            DB::table('hr_leave_request_days')->updateOrInsert(
                ['leave_request_id' => $canonicalId, 'leave_date' => $date],
                ['day_fraction' => 1, 'created_at' => now(), 'updated_at' => now()],
            );
        }
        $payload = [
            'leave_type' => $leaveType->code,
            'leave_type_id' => $leaveType->getKey(),
            'leave_type_name' => $leaveType->name,
            'leave_days' => 1,
            'chargeable_dates' => [$date],
            'balance_year' => 2026,
            'requires_balance' => $requiresBalance,
            'payment_status' => $leaveType->isPaid() ? 'paid' : 'unpaid',
            'canonical_leave_request_id' => $canonicalId,
        ];
        if ($requiresBalance) {
            $payload['leave_balance_ledger_id'] = DB::table('hr_leave_balance_ledger')
                ->where('notes', self::Marker.':paid-leave')
                ->value('id');
        }
        $serviceRequest->update(['payload' => $payload]);
    }

    private function attendanceScenario(HrEmployee $employee, Company $company, Branch $branch): void
    {
        foreach ([
            ['2026-09-02', '08:00:00', '16:00:00', 480, 0, 0, 0, 'present'],
            ['2026-09-03', '08:30:00', '15:45:00', 435, 30, 15, 0, 'present'],
            ['2026-09-04', null, null, 0, 0, 0, 0, 'absent'],
            ['2026-09-07', null, null, 0, 0, 0, 0, 'absent'],
            ['2026-09-08', null, null, 0, 0, 0, 0, 'absent'],
            ['2026-09-09', '08:00:00', '18:00:00', 600, 0, 0, 120, 'present'],
        ] as [$date, $checkIn, $checkOut, $worked, $late, $early, $overtime, $status]) {
            DB::table('hr_attendance_daily_records')->updateOrInsert(
                ['employee_id' => $employee->getKey(), 'work_date' => $date],
                [
                    'company_id' => $company->getKey(),
                    'branch_id' => $branch->getKey(),
                    'check_in_at' => $checkIn === null ? null : $date.' '.$checkIn,
                    'check_out_at' => $checkOut === null ? null : $date.' '.$checkOut,
                    'worked_minutes' => $worked,
                    'late_minutes' => $late,
                    'early_leave_minutes' => $early,
                    'overtime_minutes' => $overtime,
                    'status' => $status,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }
    }

    private function payrollPolicy(Company $company, Branch $branch, User $actor): void
    {
        $scopeKey = 'branch:'.$branch->getKey();
        $policy = HrPayrollAttendancePolicy::withTrashed()
            ->where('company_id', $company->getKey())
            ->where('branch_id', $branch->getKey())
            ->whereDate('effective_from', '2026-01-01')
            ->first() ?? new HrPayrollAttendancePolicy;
        $overlapExists = HrPayrollAttendancePolicy::query()
            ->where('company_id', $company->getKey())
            ->where('branch_id', $branch->getKey())
            ->when($policy->exists, fn ($query) => $query->whereKeyNot($policy->getKey()))
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', '2026-01-01'))
            ->exists();
        if ($overlapExists) {
            throw new LogicException('The HR E2E payroll policy would overlap an existing branch policy.');
        }

        $policy->fill([
            'company_id' => $company->getKey(),
            'branch_id' => $branch->getKey(),
            'branch_scope_key' => $scopeKey,
            'effective_from' => '2026-01-01',
            'effective_to' => null,
            'deduct_absence' => true,
            'deduct_late' => true,
            'deduct_early_leave' => true,
            'deduct_unpaid_leave' => true,
            'salary_day_divisor' => 30,
            'standard_day_minutes' => 480,
            'deduction_payroll_item_code' => 'ATTENDANCE-DEDUCTION',
            'status' => 'active',
            'created_by' => $policy->created_by ?? $actor->getKey(),
            'updated_by' => $actor->getKey(),
        ]);
        $policy->deleted_at = null;
        $policy->save();
    }

    private function payrollItems(): void
    {
        foreach ([
            ['code' => 'BASIC', 'name' => 'Basic Salary', 'kind' => 'earning', 'classification' => 'salary_expense'],
            ['code' => 'OVERTIME', 'name' => 'Overtime', 'kind' => 'earning', 'classification' => 'salary_expense'],
            ['code' => 'ATTENDANCE-DEDUCTION', 'name' => 'Attendance Deduction', 'kind' => 'deduction', 'classification' => 'direct_labor_cost'],
        ] as $item) {
            $classificationId = AccountClassification::query()
                ->where('code', $item['classification'])
                ->value('id');
            if ($classificationId === null) {
                throw new LogicException("Payroll item {$item['code']} classification is unavailable.");
            }

            $existing = DB::table('hr_payroll_items')->where('code', $item['code'])->whereNull('deleted_at')->first();
            if ($existing !== null) {
                if ($existing->item_kind !== $item['kind'] || (int) $existing->account_classification_id !== (int) $classificationId) {
                    throw new LogicException("Payroll item {$item['code']} has an incompatible direction or classification.");
                }

                continue;
            }

            DB::table('hr_payroll_items')->insert([
                'code' => $item['code'],
                'name' => $item['name'],
                'item_kind' => $item['kind'],
                'account_classification_id' => $classificationId,
                'status' => 'active',
                'deleted_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function cashbox(Company $company, Branch $branch, Currency $currency, Account $cashAccount, User $actor): Cashbox
    {
        $cashbox = Cashbox::withTrashed()->updateOrCreate(
            ['company_id' => $company->getKey(), 'doc_num' => 'Cashbox-'.self::Marker],
            [
                'doc_number' => 998801,
                'branch_id' => $branch->getKey(),
                'account_id' => $cashAccount->getKey(),
                'name' => 'خزينة اختبار دورة HR',
                'status' => 'active',
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
                'deleted_at' => null,
            ],
        );
        CashboxCurrency::query()->updateOrCreate(
            ['cashbox_id' => $cashbox->getKey(), 'currency_id' => $currency->getKey()],
            ['is_default' => true, 'status' => 'active'],
        );

        return $cashbox;
    }

    private function completePayroll(Company $company, Branch $branch, Cashbox $cashbox): void
    {
        $run = DB::table('hr_payroll_runs as run')
            ->join('hr_payroll_periods as payroll_period', 'payroll_period.id', '=', 'run.payroll_period_id')
            ->where('payroll_period.company_id', $company->getKey())
            ->where('run.branch_id', $branch->getKey())
            ->whereDate('payroll_period.period_start', self::PayrollStart)
            ->whereDate('payroll_period.period_end', self::PayrollEnd)
            ->whereNull('run.deleted_at')
            ->first(['run.id', 'run.status']);

        if ($run === null) {
            $result = app(PayrollCalculationService::class)->calculate($company->getKey(), [
                'period_start' => self::PayrollStart,
                'period_end' => self::PayrollEnd,
                'branch_doc_num' => $branch->doc_num,
            ]);
            $run = (object) ['id' => $result['run_id'], 'status' => 'calculated'];
        }

        if ($run->status === 'calculated') {
            $run = app(PayrollLifecycleService::class)->submitForReview((int) $run->id, $company->getKey());
        }
        if (in_array($run->status, ['under_review', 'approved'], true)) {
            $approved = app(PayrollLifecycleService::class)->approve((int) $run->id, $company->getKey());
            $run = $approved['run'];
        }
        if ($run->status !== 'posted') {
            throw new DomainException('The HR E2E payroll run did not reach posted status.');
        }

        $net = app(NumericFormatService::class)->normalizeToScale(
            DB::table('hr_payslips')->where('payroll_run_id', $run->id)->sum('net_amount'),
            4,
        ) ?? '0.0000';
        $payment = app(PayrollPaymentService::class)->createCashPayment((int) $run->id, $company->getKey(), [
            'cashbox_doc_num' => $cashbox->doc_num,
            'amount' => $net,
            'payment_date' => '2026-09-30',
            'idempotency_key' => self::PaymentIdempotencyKey,
            'reference' => self::Marker,
        ]);
        app(CashVoucherService::class)->approve(CashVoucher::TypePayment, $payment['voucher'], $company->getKey());
        app(CashVoucherService::class)->approve(CashVoucher::TypePayment, $payment['voucher']->refresh(), $company->getKey());
    }
}
