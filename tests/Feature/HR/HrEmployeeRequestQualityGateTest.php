<?php

use App\Models\User;
use Illuminate\Support\Str;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\Currency;
use Modules\Core\Services\OperatingContextService;
use Modules\HR\Models\HrEmployee;
use Modules\HR\Models\HrEmployeeServiceRequest;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/** @return array<string, mixed> */
function employeeRequestQualityFixture(int $suffix = 1, array $employeeOverrides = []): array
{
    $company = Company::factory()->create(['is_main' => $suffix % 2 === 0]);
    $branch = Branch::query()->create([
        'doc_number' => 9500 + $suffix,
        'doc_num' => 'Branch-REQ-QA-'.$suffix,
        'company_id' => $company->getKey(),
        'name' => 'Request QA Branch '.$suffix,
        'type' => Branch::TypeAdministrative,
        'status' => 'active',
    ]);
    $currency = Currency::query()->create([
        'doc_number' => 9500 + $suffix,
        'doc_num' => 'CUR-REQ-QA-'.$suffix,
        'company_id' => $company->getKey(),
        'name' => 'Request Currency '.$suffix,
        'code' => 'Q'.$suffix,
        'is_main' => true,
        'status' => 'active',
    ]);
    $user = User::factory()->create();
    $employee = HrEmployee::query()->create([
        'doc_number' => 9500 + $suffix,
        'doc_num' => 'HRE-REQ-QA-'.$suffix,
        'public_uuid' => (string) Str::uuid(),
        'full_name' => 'Request QA Employee '.$suffix,
        'name' => 'Request QA Employee '.$suffix,
        'company_id' => $company->getKey(),
        'branch_id' => $branch->getKey(),
        'user_id' => $user->getKey(),
        'status' => 'active',
        ...$employeeOverrides,
    ]);

    return compact('company', 'branch', 'currency', 'user', 'employee');
}

/** @return array<string, mixed> */
function employeeRequestQualityAdminSession(array $fixture): array
{
    return [
        OperatingContextService::CompanyIdKey => $fixture['company']->getKey(),
        OperatingContextService::CompanyDocNumKey => $fixture['company']->doc_num,
    ];
}

function employeeRequestQualityReviewer(array $permissions): User
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $reviewer = User::factory()->create();
    $reviewer->givePermissionTo($permissions);

    return $reviewer;
}

/** @return array<string, mixed> */
function employeeRequestRecord(array $fixture, array $overrides = []): array
{
    return [
        'employee_id' => $fixture['employee']->getKey(),
        'company_id' => $fixture['company']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'request_type' => 'other',
        'details' => 'Quality gate request',
        'status' => HrEmployeeServiceRequest::StatusSubmitted,
        'submitted_at' => now(),
        'created_by' => $fixture['user']->getKey(),
        ...$overrides,
    ];
}

test('all employee self-service request types persist their validated details under the authenticated employee', function (): void {
    $fixture = employeeRequestQualityFixture();
    $payloads = [
        'leave' => ['requested_from' => '2026-09-20', 'requested_to' => '2026-09-22', 'payload' => ['leave_type' => 'annual']],
        'attendance_adjustment' => ['requested_from' => '2026-09-20', 'payload' => ['requested_check_in' => '2026-09-20 08:00:00', 'requested_check_out' => '2026-09-20 16:00:00']],
        'overtime' => ['requested_from' => '2026-09-20', 'requested_minutes' => 90],
        'remote_work' => ['requested_from' => '2026-09-20', 'requested_to' => '2026-09-21'],
        'salary_advance' => ['amount' => '1250.50', 'currency_doc_num' => $fixture['currency']->doc_num],
        'device_asset' => ['payload' => ['asset_type' => 'Laptop']],
        'employment_letter' => ['payload' => ['letter_language' => 'ar']],
        'profile_update' => ['payload' => ['profile_field' => 'mobile', 'profile_value' => '01000000000']],
        'other' => [],
    ];

    foreach ($payloads as $type => $typePayload) {
        $this->actingAs($fixture['user'])
            ->post(route('employee.hr.requests.store'), [
                'request_type' => $type,
                'subject' => 'Request '.$type,
                'details' => 'Details for '.$type,
                'employee_id' => 999999,
                'company_id' => 999999,
                'status' => HrEmployeeServiceRequest::StatusApproved,
                ...$typePayload,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
    }

    expect(HrEmployeeServiceRequest::query()->count())->toBe(count(HrEmployeeServiceRequest::types()))
        ->and(HrEmployeeServiceRequest::query()->where('employee_id', '!=', $fixture['employee']->getKey())->exists())->toBeFalse()
        ->and(HrEmployeeServiceRequest::query()->where('company_id', '!=', $fixture['company']->getKey())->exists())->toBeFalse()
        ->and(HrEmployeeServiceRequest::query()->where('status', '!=', HrEmployeeServiceRequest::StatusSubmitted)->exists())->toBeFalse();

    $advance = HrEmployeeServiceRequest::query()->where('request_type', 'salary_advance')->sole();
    expect($advance->amount)->toBe('1250.50')->and($advance->currency_id)->toBe($fixture['currency']->getKey());
});

test('conditional validation rejects incomplete request-specific details', function (): void {
    $fixture = employeeRequestQualityFixture();
    $invalidPayloads = [
        ['request_type' => 'leave', 'errors' => ['requested_from', 'requested_to', 'payload.leave_type']],
        ['request_type' => 'attendance_adjustment', 'errors' => ['requested_from', 'payload.requested_check_in', 'payload.requested_check_out']],
        ['request_type' => 'overtime', 'errors' => ['requested_from', 'requested_minutes']],
        ['request_type' => 'salary_advance', 'errors' => ['amount', 'currency_doc_num']],
        ['request_type' => 'device_asset', 'errors' => ['payload.asset_type']],
        ['request_type' => 'employment_letter', 'payload' => ['letter_language' => 'fr'], 'errors' => ['payload.letter_language']],
        ['request_type' => 'profile_update', 'errors' => ['payload.profile_field', 'payload.profile_value']],
    ];

    foreach ($invalidPayloads as $payload) {
        $this->actingAs($fixture['user'])
            ->post(route('employee.hr.requests.store'), [
                'request_type' => $payload['request_type'],
                'details' => 'Validation check',
                'payload' => $payload['payload'] ?? [],
            ])
            ->assertSessionHasErrors($payload['errors']);
    }

    $this->assertDatabaseCount('hr_employee_service_requests', 0);
});

test('request payload rejects unknown fields and salary currencies are tenant-isolated in UI and validation', function (): void {
    $first = employeeRequestQualityFixture();
    $second = employeeRequestQualityFixture(2);

    $this->actingAs($first['user'])
        ->get(route('employee.hr.self-service.index'))
        ->assertOk()
        ->assertSee($first['currency']->name)
        ->assertDontSee($second['currency']->name);

    $this->post(route('employee.hr.requests.store'), [
        'request_type' => 'salary_advance',
        'details' => 'Cross-company currency attempt',
        'amount' => 100,
        'currency_doc_num' => $second['currency']->doc_num,
    ])->assertSessionHasErrors('currency_doc_num');

    $this->post(route('employee.hr.requests.store'), [
        'request_type' => 'leave',
        'details' => 'Unexpected payload key',
        'requested_from' => '2026-09-20',
        'requested_to' => '2026-09-21',
        'payload' => ['leave_type' => 'annual', 'admin_only' => 'spoofed'],
    ])->assertSessionHasErrors('payload');

    $this->assertDatabaseCount('hr_employee_service_requests', 0);
});

test('employees cannot cancel another employees request or a resolved request', function (): void {
    $first = employeeRequestQualityFixture();
    $second = employeeRequestQualityFixture(2);
    $request = HrEmployeeServiceRequest::query()->create(employeeRequestRecord($first));

    $this->actingAs($second['user'])
        ->patch(route('employee.hr.requests.cancel', $request))
        ->assertRedirect()
        ->assertSessionHasErrors('request');
    expect($request->refresh()->status)->toBe(HrEmployeeServiceRequest::StatusSubmitted);

    $request->update([
        'status' => HrEmployeeServiceRequest::StatusApproved,
        'resolved_at' => now(),
    ]);
    $this->actingAs($first['user'])
        ->patch(route('employee.hr.requests.cancel', $request))
        ->assertRedirect()
        ->assertSessionHasErrors('request');
    expect($request->refresh()->status)->toBe(HrEmployeeServiceRequest::StatusApproved);
});

test('request review is tenant-isolated idempotent and records the reviewer decision details', function (): void {
    $first = employeeRequestQualityFixture();
    $second = employeeRequestQualityFixture(2);
    $approved = HrEmployeeServiceRequest::query()->create(employeeRequestRecord($first));
    $rejected = HrEmployeeServiceRequest::query()->create(employeeRequestRecord($first, ['request_type' => 'leave']));
    $foreign = HrEmployeeServiceRequest::query()->create(employeeRequestRecord($second));
    $reviewer = employeeRequestQualityReviewer(['hr.hr_requests.view', 'hr.hr_requests.manage']);
    $session = employeeRequestQualityAdminSession($first);

    $this->actingAs($reviewer)->withSession($session)
        ->patch(route('admin.hr.hr-requests.review', $approved), ['decision' => 'approved', 'resolution_notes' => 'Approved by QA'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();
    expect($approved->refresh()->status)->toBe(HrEmployeeServiceRequest::StatusApproved)
        ->and($approved->resolved_by)->toBe($reviewer->getKey())
        ->and($approved->resolution_notes)->toBe('Approved by QA')
        ->and($approved->resolved_at)->not->toBeNull();

    $this->withSession($session)
        ->patch(route('admin.hr.hr-requests.review', $approved), ['decision' => 'rejected', 'resolution_notes' => 'Second decision'])
        ->assertSessionHasErrors('request');
    expect($approved->refresh()->status)->toBe(HrEmployeeServiceRequest::StatusApproved);

    $this->withSession($session)
        ->patch(route('admin.hr.hr-requests.review', $rejected), ['decision' => 'rejected', 'resolution_notes' => 'Missing documents'])
        ->assertSessionHasNoErrors();
    expect($rejected->refresh()->status)->toBe(HrEmployeeServiceRequest::StatusRejected)
        ->and($rejected->resolution_notes)->toBe('Missing documents');

    $this->withSession($session)
        ->patch(route('admin.hr.hr-requests.review', $foreign), ['decision' => 'approved'])
        ->assertNotFound();
    expect($foreign->refresh()->status)->toBe(HrEmployeeServiceRequest::StatusSubmitted);
});

test('reviewers cannot approve their own employee requests', function (): void {
    $fixture = employeeRequestQualityFixture();
    $request = HrEmployeeServiceRequest::query()->create(employeeRequestRecord($fixture));
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Permission::findOrCreate('hr.hr_requests.manage', 'web');
    $fixture['user']->givePermissionTo('hr.hr_requests.manage');

    $this->actingAs($fixture['user'])
        ->withSession(employeeRequestQualityAdminSession($fixture))
        ->patch(route('admin.hr.hr-requests.review', $request), ['decision' => 'approved'])
        ->assertRedirect()
        ->assertSessionHasErrors('request');

    expect($request->refresh()->status)->toBe(HrEmployeeServiceRequest::StatusSubmitted);
});

test('request inbox enforces view permission filters and company isolation with mobile cards', function (): void {
    $first = employeeRequestQualityFixture();
    $second = employeeRequestQualityFixture(2);
    $visible = HrEmployeeServiceRequest::query()->create(employeeRequestRecord($first, ['request_type' => 'leave']));
    HrEmployeeServiceRequest::query()->create(employeeRequestRecord($first, ['request_type' => 'overtime', 'status' => HrEmployeeServiceRequest::StatusApproved, 'details' => 'Filtered approved overtime']));
    HrEmployeeServiceRequest::query()->create(employeeRequestRecord($second, ['details' => 'Foreign tenant request']));
    $withoutPermission = User::factory()->create();

    $this->actingAs($withoutPermission)
        ->withSession(employeeRequestQualityAdminSession($first))
        ->get(route('admin.hr.hr-requests.index'))
        ->assertForbidden();

    $reviewer = employeeRequestQualityReviewer(['hr.hr_requests.view']);
    $response = $this->actingAs($reviewer)
        ->withSession(employeeRequestQualityAdminSession($first))
        ->get(route('admin.hr.hr-requests.index', ['type' => 'leave', 'status' => 'submitted']));
    $response->assertOk()
        ->assertSee($visible->details)
        ->assertDontSee('Foreign tenant request')
        ->assertDontSee('Filtered approved overtime')
        ->assertSee('col-12 col-xl-6', false);
});

test('unlinked and inactive employee accounts cannot create self-service requests', function (): void {
    $unlinked = User::factory()->create();
    $payload = ['request_type' => 'other', 'details' => 'Must not persist'];

    $this->actingAs($unlinked)
        ->post(route('employee.hr.requests.store'), $payload)
        ->assertSessionHasErrors('request');

    $inactive = employeeRequestQualityFixture(1, ['status' => 'inactive']);
    $this->actingAs($inactive['user'])
        ->post(route('employee.hr.requests.store'), $payload)
        ->assertSessionHasErrors('request');

    $this->assertDatabaseCount('hr_employee_service_requests', 0);
});

test('attendance and employee request translations stay complete in Arabic and English', function (): void {
    $flattenKeys = function (array $translations, string $prefix = '') use (&$flattenKeys): array {
        $keys = [];

        foreach ($translations as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value)) {
                $keys = [...$keys, ...$flattenKeys($value, $path)];
            } else {
                $keys[] = $path;
            }
        }

        sort($keys);

        return $keys;
    };

    foreach (['hr_attendance', 'hr_requests'] as $group) {
        $arabic = require resource_path("lang/ar/{$group}.php");
        $english = require resource_path("lang/en/{$group}.php");
        $arabicKeys = $flattenKeys($arabic);
        $englishKeys = $flattenKeys($english);

        expect($arabicKeys)->toBe($englishKeys);

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);

            foreach ($arabicKeys as $key) {
                $translated = __("{$group}.{$key}");

                expect($translated)->toBeString()->not->toBe('')->not->toBe("{$group}.{$key}");
            }
        }
    }

    foreach (['ar', 'en'] as $locale) {
        app()->setLocale($locale);

        foreach (HrEmployeeServiceRequest::types() as $type) {
            expect(__("hr_requests.types.{$type}"))->not->toBe("hr_requests.types.{$type}");
        }

        foreach (['submitted', 'approved', 'rejected', 'cancelled'] as $status) {
            expect(__("hr_requests.statuses.{$status}"))->not->toBe("hr_requests.statuses.{$status}");
        }

        foreach (['inside', 'outside', 'poor_accuracy', 'unavailable', 'unconfigured'] as $status) {
            expect(__("hr_attendance.geofence.{$status}"))->not->toBe("hr_attendance.geofence.{$status}");
        }
    }
});
