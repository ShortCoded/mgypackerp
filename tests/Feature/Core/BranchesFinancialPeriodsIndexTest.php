<?php

use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\OperatingContextService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

function coreCrudIndexActor(array $permissions): User
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $user = User::factory()->create();
    $user->givePermissionTo($permissions);

    return $user;
}

function coreCrudOperatingContext(object $test): Company
{
    static $documentNumber = 6300;

    $documentNumber++;
    $company = Company::query()->create([
        'doc_number' => $documentNumber,
        'doc_num' => 'Company-'.str_pad((string) $documentNumber, 5, '0', STR_PAD_LEFT),
        'name' => 'Core Crud Company '.$documentNumber,
        'status' => 'active',
        'is_main' => ! Company::query()->where('is_main', true)->exists(),
    ]);
    $branch = Branch::query()->create([
        'doc_number' => $documentNumber,
        'doc_num' => 'Branch-'.str_pad((string) $documentNumber, 5, '0', STR_PAD_LEFT),
        'company_id' => $company->getKey(),
        'name' => 'Core Crud Branch '.$documentNumber,
        'type' => 'administrative',
        'status' => 'active',
    ]);
    $period = FinancialPeriod::query()->create([
        'doc_number' => $documentNumber,
        'doc_num' => 'Period-'.str_pad((string) $documentNumber, 5, '0', STR_PAD_LEFT),
        'company_id' => $company->getKey(),
        'name' => 'Core Crud Period '.$documentNumber,
        'from_date' => '2026-01-01',
        'to_date' => '2026-12-31',
        'is_closed' => false,
    ]);

    $test->withSession([
        OperatingContextService::CompanyIdKey => $company->getKey(),
        OperatingContextService::CompanyDocNumKey => $company->doc_num,
        OperatingContextService::BranchIdKey => $branch->getKey(),
        OperatingContextService::BranchDocNumKey => $branch->doc_num,
        OperatingContextService::FinancialPeriodIdKey => $period->getKey(),
        OperatingContextService::FinancialPeriodDocNumKey => $period->doc_num,
    ]);

    return $company;
}

beforeEach(function (): void {
    $this->coreCrudCompany = coreCrudOperatingContext($this);
});

test('branches index renders with stable datatable structure', function () {
    $actor = coreCrudIndexActor([
        'branches.view',
        'branches.create',
        'branches.delete',
        'branches.view_trashed',
        'branches.restore',
        'branches.document_number_settings.update',
    ]);

    $this->actingAs($actor)
        ->get(route('admin.branches.index'))
        ->assertOk()
        ->assertSee(__('branches.title'))
        ->assertSee('branches-datatable-card', false)
        ->assertSee('erp-datatable-wrapper', false)
        ->assertSee('erp-datatable-scroll', false)
        ->assertSee('id="branches-document-number-settings"', false)
        ->assertSee('id="branches_trash_filter"', false)
        ->assertSee('id="bulk_actions_bar"', false)
        ->assertSee('id="bulk_action_select"', false)
        ->assertSee('id="bulk_action_apply"', false)
        ->assertSee('id="select_all_records"', false)
        ->assertSee('window.branchesMessages', false)
        ->assertSee('window.dataTableTranslations', false)
        ->assertSee(route('admin.branches.data'), false)
        ->assertSee(route('admin.branches.bulk-delete'), false)
        ->assertSee('vendors/sweetalert2/sweetalert2.all.min.js', false)
        ->assertSee('assets/js/modules/Core/branches.js', false)
        ->assertDontSee('branches.attributes.company');
});

test('branches data table renders trashed rows with restore dropdown action', function () {
    $actor = coreCrudIndexActor([
        'branches.view',
        'branches.view_trashed',
        'branches.restore',
        'branches.delete',
    ]);

    $company = Company::query()->create([
        'doc_number' => 901,
        'doc_num' => 'Company-00901',
        'name' => 'Branches Test Company',
        'status' => 'active',
    ]);

    $branch = Branch::query()->create([
        'doc_number' => 902,
        'doc_num' => 'Branch-00902',
        'company_id' => $company->getKey(),
        'name' => 'Trashed Test Branch',
        'type' => 'warehouse',
        'status' => 'active',
    ]);

    $branch->delete();

    $payload = $this->actingAs($actor)
        ->getJson(route('admin.branches.data', [
            'draw' => 1,
            'start' => 0,
            'length' => 10,
            'trash_filter' => 'trashed',
        ]))
        ->assertOk()
        ->json();

    $html = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

    expect($html)
        ->toContain('Branch-00902')
        ->toContain('dropstart')
        ->toContain('js-restore-record')
        ->toContain('data-branch-restore-url')
        ->not->toContain('js-delete-record');
});

test('branches bulk delete accepts public document numbers', function () {
    $actor = coreCrudIndexActor([
        'branches.delete',
    ]);

    $company = Company::query()->create([
        'doc_number' => 903,
        'doc_num' => 'Company-00903',
        'name' => 'Bulk Branch Company',
        'status' => 'active',
    ]);

    $branch = Branch::query()->create([
        'doc_number' => 904,
        'doc_num' => 'Branch-00904',
        'company_id' => $company->getKey(),
        'name' => 'Bulk Deleted Branch',
        'type' => 'administrative',
        'status' => 'active',
    ]);

    $this->actingAs($actor)
        ->deleteJson(route('admin.branches.bulk-delete'), [
            'doc_nums' => [$branch->doc_num],
        ])
        ->assertOk()
        ->assertJsonPath('success', true);

    expect(Branch::withTrashed()->where('doc_num', 'Branch-00904')->first()?->trashed())->toBeTrue();
});

test('branches restore uses public document number and restores trashed branch', function () {
    $actor = coreCrudIndexActor([
        'branches.restore',
    ]);

    $company = Company::query()->create([
        'doc_number' => 905,
        'doc_num' => 'Company-00905',
        'name' => 'Restore Branch Company',
        'status' => 'active',
    ]);

    $branch = Branch::query()->create([
        'doc_number' => 906,
        'doc_num' => 'Branch-00906',
        'company_id' => $company->getKey(),
        'name' => 'Restored Branch',
        'type' => 'administrative',
        'status' => 'active',
    ]);
    $branch->delete();

    $this->actingAs($actor)
        ->patchJson(route('admin.branches.restore', $branch->doc_num))
        ->assertOk()
        ->assertJsonPath('success', true);

    expect(Branch::query()->where('doc_num', 'Branch-00906')->exists())->toBeTrue();
});

test('financial periods index renders with stable datatable structure', function () {
    $actor = coreCrudIndexActor([
        'financial_periods.view',
        'financial_periods.create',
        'financial_periods.delete',
        'financial_periods.view_trashed',
        'financial_periods.restore',
        'financial_periods.document_number_settings.update',
    ]);

    $this->actingAs($actor)
        ->get(route('admin.financial-periods.index'))
        ->assertOk()
        ->assertSee(__('financial_periods.title'))
        ->assertSee('financial-periods-datatable-card', false)
        ->assertSee('erp-datatable-wrapper', false)
        ->assertSee('erp-datatable-scroll', false)
        ->assertSee('id="financial-periods-document-number-settings"', false)
        ->assertSee('id="financial_periods_trash_filter"', false)
        ->assertSee('id="bulk_actions_bar"', false)
        ->assertSee('id="bulk_action_select"', false)
        ->assertSee('id="bulk_action_apply"', false)
        ->assertSee('id="select_all_records"', false)
        ->assertSee('window.coreFinancialPeriodsMessages', false)
        ->assertSee('window.dataTableTranslations', false)
        ->assertSee(route('admin.financial-periods.data'), false)
        ->assertSee(route('admin.financial-periods.bulk-delete'), false)
        ->assertSee('vendors/sweetalert2/sweetalert2.all.min.js', false)
        ->assertSee('assets/js/modules/Core/financial-periods.js', false);
});

test('financial periods schema has company scope column', function () {
    expect(Schema::hasColumn('financial_periods', 'company_id'))->toBeTrue();
});

test('financial periods data table renders trashed rows with restore dropdown action', function () {
    $actor = coreCrudIndexActor([
        'financial_periods.view',
        'financial_periods.view_trashed',
        'financial_periods.restore',
        'financial_periods.delete',
    ]);

    $period = FinancialPeriod::query()->create([
        'company_id' => $this->coreCrudCompany->getKey(),
        'doc_number' => 910,
        'doc_num' => 'Period-00910',
        'name' => 'Trashed Test Period',
        'from_date' => '2090-01-01',
        'to_date' => '2090-12-31',
        'is_closed' => false,
    ]);
    $period->delete();

    $payload = $this->actingAs($actor)
        ->getJson(route('admin.financial-periods.data', [
            'draw' => 1,
            'start' => 0,
            'length' => 10,
            'trash_filter' => 'trashed',
        ]))
        ->assertOk()
        ->json();

    $html = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

    expect($html)
        ->toContain('Period-00910')
        ->toContain('dropstart')
        ->toContain('js-restore-record')
        ->toContain('data-restore-url')
        ->not->toContain('js-delete-record');
});

test('financial periods data table renders translated status labels', function () {
    $actor = coreCrudIndexActor([
        'financial_periods.view',
    ]);

    FinancialPeriod::query()->create([
        'company_id' => $this->coreCrudCompany->getKey(),
        'doc_number' => 915,
        'doc_num' => 'Period-00915',
        'name' => 'Open Status Period',
        'from_date' => '2092-01-01',
        'to_date' => '2092-12-31',
        'is_closed' => false,
    ]);

    FinancialPeriod::query()->create([
        'company_id' => $this->coreCrudCompany->getKey(),
        'doc_number' => 916,
        'doc_num' => 'Period-00916',
        'name' => 'Closed Status Period',
        'from_date' => '2093-01-01',
        'to_date' => '2093-12-31',
        'is_closed' => true,
    ]);

    $payload = $this->actingAs($actor)
        ->getJson(route('admin.financial-periods.data', [
            'draw' => 1,
            'start' => 0,
            'length' => 10,
        ]))
        ->assertOk()
        ->json();

    $html = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

    expect($html)
        ->toContain(__('financial_periods.statuses.open'))
        ->toContain(__('financial_periods.statuses.closed'))
        ->not->toContain('financial_periods.statuses.open')
        ->not->toContain('financial_periods.statuses.closed');
});

test('financial periods reject duplicate names and overlapping dates cleanly', function () {
    $actor = coreCrudIndexActor([
        'financial_periods.create',
        'financial_periods.edit',
    ]);

    $existing = FinancialPeriod::query()->create([
        'company_id' => $this->coreCrudCompany->getKey(),
        'doc_number' => 920,
        'doc_num' => 'Period-00920',
        'name' => 'FY 2091',
        'from_date' => '2091-01-01',
        'to_date' => '2091-12-31',
        'is_closed' => false,
    ]);

    $this->actingAs($actor)
        ->postJson(route('admin.financial-periods.store'), [
            'name' => '  FY 2091  ',
            'from_date' => '01/01/2092',
            'to_date' => '31/12/2092',
            'is_closed' => '0',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);

    $this->actingAs($actor)
        ->postJson(route('admin.financial-periods.store'), [
            'name' => 'FY 2091 Overlap',
            'from_date' => '01/06/2091',
            'to_date' => '31/05/2092',
            'is_closed' => '0',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['from_date'])
        ->assertJsonPath('errors.from_date.0', __('financial_periods.validation.date_inside_existing'));

    $this->actingAs($actor)
        ->putJson(route('admin.financial-periods.update', $existing->doc_num), [
            'name' => ' FY 2091 ',
            'from_date' => '01/01/2091',
            'to_date' => '31/12/2091',
            'is_closed' => '0',
        ])
        ->assertOk()
        ->assertJsonPath('type', 'no_changes');
});

test('financial period validation uses human readable date messages', function () {
    $actor = coreCrudIndexActor([
        'financial_periods.create',
    ]);

    FinancialPeriod::query()->create([
        'company_id' => $this->coreCrudCompany->getKey(),
        'doc_number' => 930,
        'doc_num' => 'Period-00930',
        'name' => 'FY 2094',
        'from_date' => '2094-01-01',
        'to_date' => '2094-12-31',
        'is_closed' => false,
    ]);

    $this->actingAs($actor)
        ->postJson(route('admin.financial-periods.store'), [
            'name' => 'Invalid Date Period',
            'from_date' => 'not-a-date',
            'to_date' => 'also-not-a-date',
            'is_closed' => '0',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['from_date', 'to_date'])
        ->assertJsonPath('errors.from_date.0', __('financial_periods.validation.from_date_invalid'))
        ->assertJsonPath('errors.to_date.0', __('financial_periods.validation.to_date_invalid'));

    $this->actingAs($actor)
        ->postJson(route('admin.financial-periods.store'), [
            'name' => 'Inside Existing Period',
            'from_date' => '01/06/2094',
            'to_date' => '31/05/2095',
            'is_closed' => '0',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['from_date'])
        ->assertJsonPath('errors.from_date.0', __('financial_periods.validation.date_inside_existing'));

    $this->actingAs($actor)
        ->postJson(route('admin.financial-periods.store'), [
            'name' => 'Contains Existing Period',
            'from_date' => '01/01/2093',
            'to_date' => '31/12/2095',
            'is_closed' => '0',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['from_date', 'to_date'])
        ->assertJsonPath('errors.from_date.0', __('financial_periods.validation.date_range_overlap'))
        ->assertJsonPath('errors.to_date.0', __('financial_periods.validation.date_range_overlap'));
});

test('financial periods are scoped by operating company for validation datatable and routes', function () {
    $actor = coreCrudIndexActor([
        'financial_periods.view',
        'financial_periods.create',
    ]);
    $companyA = $this->coreCrudCompany;

    FinancialPeriod::query()->create([
        'company_id' => $companyA->getKey(),
        'doc_number' => 777,
        'doc_num' => 'Period-00777',
        'name' => 'Company A Only Period',
        'from_date' => '2100-01-01',
        'to_date' => '2100-12-31',
        'is_closed' => false,
    ]);
    FinancialPeriod::query()->create([
        'company_id' => $companyA->getKey(),
        'doc_number' => 778,
        'doc_num' => 'Period-00778',
        'name' => 'Shared Financial Name',
        'from_date' => '2101-01-01',
        'to_date' => '2101-12-31',
        'is_closed' => false,
    ]);

    $companyB = coreCrudOperatingContext($this);
    FinancialPeriod::query()->create([
        'company_id' => $companyB->getKey(),
        'doc_number' => 777,
        'doc_num' => 'Period-00777',
        'name' => 'Company B Route Period',
        'from_date' => '2100-01-01',
        'to_date' => '2100-12-31',
        'is_closed' => false,
    ]);

    $this->actingAs($actor)
        ->postJson(route('admin.financial-periods.store'), [
            'name' => 'Shared Financial Name',
            'from_date' => '01/01/2101',
            'to_date' => '31/12/2101',
            'is_closed' => '0',
        ])
        ->assertOk()
        ->assertJsonPath('success', true);

    $this->actingAs($actor)
        ->postJson(route('admin.financial-periods.store'), [
            'name' => 'Company B Route Period',
            'from_date' => '01/01/2102',
            'to_date' => '31/12/2102',
            'is_closed' => '0',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);

    $payload = $this->actingAs($actor)
        ->getJson(route('admin.financial-periods.data', [
            'draw' => 1,
            'start' => 0,
            'length' => 10,
        ]))
        ->assertOk()
        ->json();

    $html = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

    expect($html)
        ->toContain('Company B Route Period')
        ->not->toContain('Company A Only Period');

    $this->actingAs($actor)
        ->get(route('admin.financial-periods.show', 'Period-00777'))
        ->assertOk()
        ->assertSee('Company B Route Period')
        ->assertDontSee('Company A Only Period');
});
