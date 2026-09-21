<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Auth\Models\Role;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\Reports\ReportPdfService;
use Modules\HR\Exports\HrWorkforceReportExport;
use Modules\HR\Models\HrDepartment;
use Modules\HR\Models\HrEmployee;
use Modules\HR\Models\HrEmployeeServiceRequest;
use Modules\HR\Models\HrEmploymentType;
use Modules\HR\Models\HrJob;
use Modules\HR\Models\HrLeaveType;
use Modules\HR\Models\HrSection;
use Modules\HR\Services\HrEmployeeRequestService;
use Modules\HR\Services\HrWorkforceReportService;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/** @return array<string, mixed> */
function workforceReportFixture(int $suffix = 1): array
{
    $company = Company::factory()->create(['name' => 'Workforce Company '.$suffix, 'status' => 'active', 'is_main' => $suffix % 2 === 0]);
    $branch = Branch::query()->create([
        'doc_number' => 9800 + $suffix,
        'doc_num' => 'WR-BR-'.$suffix,
        'company_id' => $company->getKey(),
        'name' => 'Workforce Branch '.$suffix,
        'type' => Branch::TypeAdministrative,
        'status' => 'active',
    ]);
    $department = HrDepartment::query()->create(['doc_number' => 9800 + $suffix, 'doc_num' => 'WR-DEPT-'.$suffix, 'name' => 'Department '.$suffix, 'status' => 'active']);
    $section = HrSection::query()->create(['doc_number' => 9800 + $suffix, 'doc_num' => 'WR-SEC-'.$suffix, 'department_id' => $department->getKey(), 'name' => 'Section '.$suffix, 'status' => 'active']);
    $job = HrJob::query()->create(['doc_number' => 9800 + $suffix, 'doc_num' => 'WR-JOB-'.$suffix, 'name' => 'Job '.$suffix, 'status' => 'active']);
    $employmentType = HrEmploymentType::query()->create(['doc_number' => 9800 + $suffix, 'doc_num' => 'WR-ET-'.$suffix, 'name' => 'Employment Type '.$suffix, 'status' => 'active']);
    $employee = HrEmployee::query()->create([
        'doc_number' => 9800 + $suffix,
        'doc_num' => 'WR-EMP-'.$suffix,
        'employee_code' => 'WRE-'.$suffix,
        'public_uuid' => (string) Str::uuid(),
        'full_name' => 'Workforce Employee '.$suffix,
        'name' => 'Workforce Employee '.$suffix,
        'company_id' => $company->getKey(),
        'branch_id' => $branch->getKey(),
        'department_id' => $department->getKey(),
        'section_id' => $section->getKey(),
        'job_id' => $job->getKey(),
        'employment_type_id' => $employmentType->getKey(),
        'hire_date' => '2026-01-'.str_pad((string) $suffix, 2, '0', STR_PAD_LEFT),
        'gender' => $suffix % 2 === 0 ? 'female' : 'male',
        'status' => 'active',
        'basic_salary' => 9000 + $suffix,
    ]);

    return compact('company', 'branch', 'department', 'section', 'job', 'employmentType', 'employee');
}

/** @return array<string, mixed> */
function workforceAdditionalEmployee(array $fixture, int $suffix): array
{
    $branch = Branch::query()->create([
        'doc_number' => 9800 + $suffix,
        'doc_num' => 'WR-BR-'.$suffix,
        'company_id' => $fixture['company']->getKey(),
        'name' => 'Workforce Branch '.$suffix,
        'type' => Branch::TypeAdministrative,
        'status' => 'active',
    ]);
    $employee = HrEmployee::query()->create([
        'doc_number' => 9800 + $suffix,
        'doc_num' => 'WR-EMP-'.$suffix,
        'employee_code' => 'WRE-'.$suffix,
        'public_uuid' => (string) Str::uuid(),
        'full_name' => 'Workforce Employee '.$suffix,
        'name' => 'Workforce Employee '.$suffix,
        'company_id' => $fixture['company']->getKey(),
        'branch_id' => $branch->getKey(),
        'department_id' => $fixture['department']->getKey(),
        'section_id' => $fixture['section']->getKey(),
        'job_id' => $fixture['job']->getKey(),
        'employment_type_id' => $fixture['employmentType']->getKey(),
        'hire_date' => '2026-01-02',
        'gender' => 'female',
        'status' => 'active',
        'basic_salary' => 12000,
    ]);

    return [...$fixture, 'branch' => $branch, 'employee' => $employee];
}

function workforceRequest(array $fixture, HrLeaveType $leaveType, User $approver, float $days, array $overrides = []): HrEmployeeServiceRequest
{
    return HrEmployeeServiceRequest::query()->create([
        'employee_id' => $fixture['employee']->getKey(),
        'company_id' => $fixture['company']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'request_type' => 'leave',
        'details' => 'Workforce report leave request',
        'requested_from' => '2026-09-01',
        'requested_to' => '2026-09-03',
        'payload' => [
            'leave_type_id' => $leaveType->getKey(),
            'leave_type_name' => $leaveType->name,
            'leave_days' => $days,
            'requires_balance' => true,
            'payment_status' => $leaveType->isPaid() ? 'paid' : 'unpaid',
        ],
        'status' => HrEmployeeServiceRequest::StatusApproved,
        'submitted_at' => '2026-08-20 09:00:00',
        'resolved_at' => '2026-08-21 10:00:00',
        'resolved_by' => $approver->getKey(),
        ...$overrides,
    ]);
}

test('employee report uses the same scoped master rows without compensation columns', function (): void {
    $first = workforceReportFixture(1);
    $second = workforceAdditionalEmployee($first, 12);
    $otherCompany = workforceReportFixture(2);
    $user = User::factory()->create();
    $service = app(HrWorkforceReportService::class);

    $allRows = $service->employeeRows($first['company']->getKey(), $user, []);
    expect($allRows->pluck('employee_code')->all())->toContain('WRE-1', 'WRE-12')->not->toContain('WRE-2')
        ->and(property_exists($allRows->first(), 'basic_salary'))->toBeFalse()
        ->and(property_exists($allRows->first(), 'payroll_currency_id'))->toBeFalse();

    $filtered = $service->employeeRows($first['company']->getKey(), $user, [
        'branch_doc_num' => $first['branch']->doc_num,
        'department_doc_num' => $first['department']->doc_num,
        'section_doc_num' => $first['section']->doc_num,
        'job_doc_num' => $first['job']->doc_num,
        'employment_type_doc_num' => $first['employmentType']->doc_num,
        'status' => 'active',
        'gender' => 'male',
        'hire_from' => '2026-01-01',
        'hire_to' => '2026-01-31',
    ]);
    expect($filtered)->toHaveCount(1)->and($filtered->first()->employee_code)->toBe('WRE-1');
    expect($otherCompany['employee']->exists)->toBeTrue();
});

test('employee and leave reports enforce restricted branch access inside a company', function (): void {
    $first = workforceReportFixture(11);
    $outside = workforceAdditionalEmployee($first, 12);
    $user = User::factory()->create();
    $role = Role::query()->create([
        'name' => 'Workforce Restricted '.Str::random(8),
        'guard_name' => 'web',
        'company_access_restricted' => true,
        'branch_access_restricted' => true,
        'financial_period_access_restricted' => false,
    ]);
    $role->companyAccessCompanies()->sync([$first['company']->getKey()]);
    $role->branchAccessBranches()->sync([$first['branch']->getKey()]);
    $user->assignRole($role);
    $approver = User::factory()->create(['name' => 'Scoped Approver']);
    $leaveType = HrLeaveType::query()->create(['code' => 'wr-paid', 'name' => 'Paid Leave', 'status' => 'active', 'metadata' => ['requires_balance' => true, 'payment_status' => 'paid']]);
    workforceRequest($first, $leaveType, $approver, 2);
    workforceRequest($outside, $leaveType, $approver, 5);
    $service = app(HrWorkforceReportService::class);

    expect($service->employeeRows($first['company']->getKey(), $user, []))->toHaveCount(1);
    $first['employee']->delete();
    $outside['employee']->delete();

    expect($service->employeeRows($first['company']->getKey(), $user, []))->toHaveCount(0)
        ->and($service->leaveRequestRows($first['company']->getKey(), $user, []))->toHaveCount(1)
        ->and($service->approvers($first['company']->getKey(), $user)->pluck('name')->all())->toBe(['Scoped Approver']);
});

test('leave request report keeps request counts and paid unpaid day totals as separate units', function (): void {
    $fixture = workforceReportFixture(21);
    $foreign = workforceReportFixture(22);
    $approver = User::factory()->create(['name' => 'Report Approver']);
    $user = User::factory()->create();
    $paid = HrLeaveType::query()->create(['code' => 'wr-paid-21', 'name' => 'Paid Leave', 'status' => 'active', 'metadata' => ['requires_balance' => true, 'payment_status' => 'paid']]);
    $unpaid = HrLeaveType::query()->create(['code' => 'wr-unpaid-21', 'name' => 'Unpaid Leave', 'status' => 'active', 'metadata' => ['requires_balance' => false, 'payment_status' => 'unpaid']]);
    workforceRequest($fixture, $paid, $approver, 2);
    workforceRequest($fixture, $unpaid, $approver, 1.5, ['requested_from' => '2026-09-05', 'requested_to' => '2026-09-06']);
    HrEmployeeServiceRequest::query()->create([
        'employee_id' => $fixture['employee']->getKey(), 'company_id' => $fixture['company']->getKey(), 'branch_id' => $fixture['branch']->getKey(),
        'request_type' => 'overtime', 'details' => 'Overtime request', 'requested_minutes' => 90, 'status' => 'submitted', 'submitted_at' => '2026-08-22 09:00:00',
    ]);
    workforceRequest($fixture, $paid, $approver, 4, ['status' => HrEmployeeServiceRequest::StatusSubmitted, 'requested_from' => '2026-09-11', 'requested_to' => '2026-09-14']);
    workforceRequest($fixture, $paid, $approver, 5, ['status' => HrEmployeeServiceRequest::StatusRejected, 'requested_from' => '2026-09-15', 'requested_to' => '2026-09-19']);
    workforceRequest($fixture, $unpaid, $approver, 6, ['status' => HrEmployeeServiceRequest::StatusCancelled, 'requested_from' => '2026-09-20', 'requested_to' => '2026-09-25']);
    workforceRequest($foreign, $paid, $approver, 9);
    $service = app(HrWorkforceReportService::class);
    $report = $service->leaveRequests($fixture['company']->getKey(), $user, ['date_from' => '2026-08-01', 'date_to' => '2026-08-31']);

    expect($report['rows'])->toHaveCount(6)
        ->and($report['totals'])->toBe(['request_count' => 6, 'leave_days' => 3.5, 'paid_leave_days' => 2.0, 'unpaid_leave_days' => 1.5]);
    $paidRows = $service->leaveRequestRows($fixture['company']->getKey(), $user, ['leave_type_code' => $paid->code]);
    expect($paidRows)->toHaveCount(3);
    $approvedPaid = $paidRows->firstWhere('status', HrEmployeeServiceRequest::StatusApproved);
    expect($approvedPaid->leave_type_name)->toBe('Paid Leave')
        ->and($approvedPaid->balance_impact)->toBe(-2.0);
});

test('leave payment classification is snapshotted at submission and persisted on approval', function (): void {
    $fixture = workforceReportFixture(25);
    $requester = User::factory()->create();
    $reviewer = User::factory()->create();
    $fixture['employee']->update(['user_id' => $requester->getKey()]);
    $leaveType = HrLeaveType::query()->create([
        'code' => 'snapshot-paid-25',
        'name' => 'Snapshot Paid Leave',
        'status' => 'active',
        'metadata' => ['requires_balance' => false, 'payment_status' => 'paid'],
    ]);
    $requests = app(HrEmployeeRequestService::class);
    $request = $requests->createForUser($requester, [
        'request_type' => 'leave',
        'details' => 'Snapshot stability request',
        'requested_from' => '2026-10-05',
        'requested_to' => '2026-10-06',
        'payload' => ['leave_type' => $leaveType->code],
    ]);
    expect($request->payload['payment_status'])->toBe('paid');

    $leaveType->update(['metadata' => ['requires_balance' => false, 'payment_status' => 'unpaid']]);
    $approved = $requests->review($request, $reviewer, HrEmployeeServiceRequest::StatusApproved, null);

    expect($approved->payload['payment_status'])->toBe('paid')
        ->and(DB::table('hr_leave_requests')->where('id', $approved->payload['canonical_leave_request_id'])->value('payment_status'))->toBe('paid');

    $legacyPayload = $approved->payload;
    unset($legacyPayload['payment_status']);
    $approved->update(['payload' => $legacyPayload]);
    $leaveType->update([
        'name' => 'Mutated Live Leave Name',
        'metadata' => ['requires_balance' => false, 'payment_status' => 'unpaid'],
    ]);
    $report = app(HrWorkforceReportService::class)->leaveRequests($fixture['company']->getKey(), $reviewer, []);
    expect($report['totals'])->toBe(['request_count' => 1, 'leave_days' => 2.0, 'paid_leave_days' => 2.0, 'unpaid_leave_days' => 0.0])
        ->and($report['rows']->sole()->leave_type_name)->toBe('Snapshot Paid Leave');
});

test('legacy leave service requests are backfilled once and remain immutable after leave type changes', function (): void {
    $fixture = workforceReportFixture(26);
    $reviewer = User::factory()->create();
    $paid = HrLeaveType::query()->create([
        'code' => 'legacy-paid-26',
        'name' => 'Legacy Paid Leave',
        'status' => 'active',
        'metadata' => ['requires_balance' => false, 'payment_status' => 'paid'],
    ]);
    $legacyRequest = HrEmployeeServiceRequest::query()->create([
        'employee_id' => $fixture['employee']->getKey(),
        'company_id' => $fixture['company']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'request_type' => 'leave',
        'details' => 'Legacy paid leave without immutable snapshot',
        'requested_from' => '2025-01-05',
        'requested_to' => '2025-01-05',
        'payload' => [
            'leave_type_id' => $paid->getKey(),
            'leave_days' => 1,
            'requires_balance' => false,
        ],
        'status' => HrEmployeeServiceRequest::StatusApproved,
        'submitted_at' => '2025-01-01 09:00:00',
        'resolved_at' => '2025-01-02 09:00:00',
        'resolved_by' => $reviewer->getKey(),
    ]);

    $migration = require base_path('modules/HR/Database/Migrations/2026_09_20_072127_create_hr_payroll_attendance_policies_table.php');
    expect($migration->backfillLegacyServiceRequestSnapshots())->toBe(1);
    $snapshot = $legacyRequest->refresh()->payload;
    expect($snapshot)->toMatchArray([
        'leave_type_id' => $paid->getKey(),
        'leave_type' => 'LEGACY-PAID-26',
        'leave_type_name' => 'Legacy Paid Leave',
        'payment_status' => 'paid',
        'requires_balance' => false,
    ]);
    $paid->update([
        'name' => 'Mutated Legacy Leave',
        'metadata' => ['requires_balance' => true, 'payment_status' => 'unpaid'],
    ]);

    $report = app(HrWorkforceReportService::class)->leaveRequests($fixture['company']->getKey(), $reviewer, []);

    expect($report['totals'])->toBe([
        'request_count' => 1,
        'leave_days' => 1.0,
        'paid_leave_days' => 1.0,
        'unpaid_leave_days' => 0.0,
    ])->and($report['rows']->sole()->leave_is_paid)->toBeTrue()
        ->and($report['rows']->sole()->leave_type_name)->toBe('Legacy Paid Leave');
});

test('workforce spreadsheet export preserves supplied headings and rows exactly', function (): void {
    $headings = ['Employee Code', 'Employee'];
    $rows = [['WRE-1', 'Workforce Employee 1']];
    $export = new HrWorkforceReportExport($headings, $rows);

    expect($export->headings())->toBe($headings)->and($export->array())->toBe($rows);
});

test('workforce report translation files remain structurally aligned', function (): void {
    $english = require resource_path('lang/en/hr_workforce_reports.php');
    $arabic = require resource_path('lang/ar/hr_workforce_reports.php');

    expect(array_keys($arabic))->toBe(array_keys($english))
        ->and(array_keys($arabic['columns']))->toBe(array_keys($english['columns']))
        ->and(array_keys($arabic['filters']))->toBe(array_keys($english['filters']))
        ->and(array_keys($arabic['totals']))->toBe(array_keys($english['totals']));
});

test('employee report routes enforce permissions and keep screen csv xlsx and pdf filters aligned', function (): void {
    $first = workforceReportFixture(31);
    $outsideFilter = workforceAdditionalEmployee($first, 32);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Permission::findOrCreate('hr.employee_reports.view', 'web');
    Permission::findOrCreate('hr.employee_reports.export', 'web');
    $unauthorized = User::factory()->create();
    $actor = User::factory()->create();
    $actor->givePermissionTo(['hr.employee_reports.view', 'hr.employee_reports.export']);
    $session = [
        OperatingContextService::CompanyIdKey => $first['company']->getKey(),
        OperatingContextService::CompanyDocNumKey => $first['company']->doc_num,
    ];
    $filters = ['branch_doc_num' => $first['branch']->doc_num];

    $this->actingAs($unauthorized)->withSession($session)
        ->get(route('admin.hr.reports.employees'))
        ->assertForbidden();
    $this->get(route('admin.hr.reports.employees.export', ['format' => 'csv']))
        ->assertForbidden();

    $screen = $this->actingAs($actor)->withSession($session)
        ->get(route('admin.hr.reports.employees', $filters));
    $screen->assertOk()
        ->assertSee($first['employee']->full_name)
        ->assertDontSee($outsideFilter['employee']->full_name)
        ->assertDontSee((string) $first['employee']->basic_salary)
        ->assertSee('admin-report-page', false);

    $csv = $this->withSession($session)->get(route('admin.hr.reports.employees.export', [...$filters, 'format' => 'csv']));
    $csv->assertOk();
    $csvContents = file_get_contents($csv->baseResponse->getFile()->getPathname());
    expect($csvContents)->toContain($first['employee']->full_name)->not->toContain($outsideFilter['employee']->full_name);

    $this->withSession($session)->get(route('admin.hr.reports.employees.export', [...$filters, 'format' => 'xlsx']))
        ->assertOk()
        ->assertDownload('employee-report.xlsx');

    $pdf = Mockery::mock(ReportPdfService::class);
    $pdf->shouldReceive('stream')->once()->withArgs(fn (string $view, array $data, string $filename, string $orientation): bool => $view === 'reports.hr.workforce'
        && $filename === 'employee-report.pdf'
        && $orientation === 'L'
        && count($data['rows']) === 1
        && $data['rows'][0][0] === $first['employee']->employee_code)->andReturn(response('%PDF-1.4', 200, ['Content-Type' => 'application/pdf']));
    app()->instance(ReportPdfService::class, $pdf);
    $this->withSession($session)->get(route('admin.hr.reports.employees.export', [...$filters, 'format' => 'pdf']))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

test('leave report routes enforce permissions and preserve scoped paid unpaid totals across outputs', function (): void {
    $fixture = workforceReportFixture(41);
    $approver = User::factory()->create(['name' => 'HTTP Report Approver']);
    $paid = HrLeaveType::query()->create(['code' => 'http-paid-41', 'name' => 'HTTP Paid Leave', 'status' => 'active', 'metadata' => ['requires_balance' => true, 'payment_status' => 'paid']]);
    $unpaid = HrLeaveType::query()->create(['code' => 'http-unpaid-41', 'name' => 'HTTP Unpaid Leave', 'status' => 'active', 'metadata' => ['requires_balance' => false, 'payment_status' => 'unpaid']]);
    $paidRequest = workforceRequest($fixture, $paid, $approver, 2);
    $unpaidRequest = workforceRequest($fixture, $unpaid, $approver, 1, ['requested_from' => '2026-09-10', 'requested_to' => '2026-09-10']);
    $paid->update(['metadata' => ['requires_balance' => true, 'payment_status' => 'unpaid']]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Permission::findOrCreate('hr.leave_reports.view', 'web');
    Permission::findOrCreate('hr.leave_reports.export', 'web');
    $unauthorized = User::factory()->create();
    $actor = User::factory()->create();
    $actor->givePermissionTo(['hr.leave_reports.view', 'hr.leave_reports.export']);
    $session = [
        OperatingContextService::CompanyIdKey => $fixture['company']->getKey(),
        OperatingContextService::CompanyDocNumKey => $fixture['company']->doc_num,
    ];
    $filters = ['leave_type_code' => $paid->code];

    $this->actingAs($unauthorized)->withSession($session)
        ->get(route('admin.hr.reports.leave-requests'))
        ->assertForbidden();
    $this->get(route('admin.hr.reports.leave-requests.export', ['format' => 'csv']))
        ->assertForbidden();

    $screen = $this->actingAs($actor)->withSession($session)
        ->get(route('admin.hr.reports.leave-requests', $filters));
    $screen->assertOk()
        ->assertSee($paidRequest->public_uuid)
        ->assertDontSee($unpaidRequest->public_uuid)
        ->assertSee('<strong dir="ltr">2</strong>', false)
        ->assertSee('<strong dir="ltr">0</strong>', false)
        ->assertDontSee('<strong dir="ltr">2.000</strong>', false)
        ->assertDontSee('<strong dir="ltr">0.000</strong>', false);
    expect($screen->viewData('report')['totals'])->toBe([
        'request_count' => 1,
        'leave_days' => 2.0,
        'paid_leave_days' => 2.0,
        'unpaid_leave_days' => 0.0,
    ]);

    $csv = $this->withSession($session)->get(route('admin.hr.reports.leave-requests.export', [...$filters, 'format' => 'csv']));
    $csv->assertOk();
    $csvContents = file_get_contents($csv->baseResponse->getFile()->getPathname());
    expect($csvContents)->toContain($paidRequest->public_uuid)->not->toContain($unpaidRequest->public_uuid);
    $csvRows = collect(IOFactory::load($csv->baseResponse->getFile()->getPathname())->getActiveSheet()->toArray());
    $csvTotals = $csvRows->take(-4)->values();
    expect((float) ($csvTotals[2][1] ?? -1))->toBe(2.0)
        ->and((float) ($csvTotals[3][1] ?? -1))->toBe(0.0);

    $xlsx = $this->withSession($session)->get(route('admin.hr.reports.leave-requests.export', [...$filters, 'format' => 'xlsx']));
    $xlsx->assertOk()->assertDownload('leave-request-report.xlsx');
    $xlsxRows = collect(IOFactory::load($xlsx->baseResponse->getFile()->getPathname())->getActiveSheet()->toArray());
    $xlsxTotals = $xlsxRows->take(-4)->values();
    expect((float) ($xlsxTotals[2][1] ?? -1))->toBe(2.0)
        ->and((float) ($xlsxTotals[3][1] ?? -1))->toBe(0.0);

    $pdf = Mockery::mock(ReportPdfService::class);
    $pdf->shouldReceive('stream')->once()->withArgs(fn (string $view, array $data, string $filename, string $orientation): bool => $view === 'reports.hr.workforce'
        && $filename === 'leave-request-report.pdf'
        && $orientation === 'L'
        && collect($data['rows'])->contains(fn (array $row): bool => ($row[0] ?? null) === $paidRequest->public_uuid)
        && ! collect($data['rows'])->contains(fn (array $row): bool => ($row[0] ?? null) === $unpaidRequest->public_uuid)
        && (float) (collect($data['rows'])->take(-4)->values()[2][1] ?? -1) === 2.0
        && (float) (collect($data['rows'])->take(-4)->values()[3][1] ?? -1) === 0.0)->andReturn(response('%PDF-1.4', 200, ['Content-Type' => 'application/pdf']));
    app()->instance(ReportPdfService::class, $pdf);
    $this->withSession($session)->get(route('admin.hr.reports.leave-requests.export', [...$filters, 'format' => 'pdf']))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});
