<?php

use App\Models\User;
use Database\Seeders\DefaultOperatingContextSeeder;
use Illuminate\Support\Facades\Schema;
use Modules\Accounting\Models\CostCenter;
use Modules\Accounting\Services\CostCenterService;
use Modules\Auth\Database\Seeders\PermissionSeeder;
use Modules\Auth\Models\Role;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\MenuService;
use Modules\Core\Services\OperatingContextService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

function costCenterSetOperatingContext(Company $company, Branch $branch, FinancialPeriod $period): void
{
    if (! request()->hasSession()) {
        request()->setLaravelSession(app('session.store'));
    }

    session([
        OperatingContextService::CompanyIdKey => $company->getKey(),
        OperatingContextService::CompanyDocNumKey => $company->doc_num,
        OperatingContextService::BranchIdKey => $branch->getKey(),
        OperatingContextService::BranchDocNumKey => $branch->doc_num,
        OperatingContextService::FinancialPeriodIdKey => $period->getKey(),
        OperatingContextService::FinancialPeriodDocNumKey => $period->doc_num,
    ]);
}

function costCenterEnsureOperatingContext(): array
{
    app(DefaultOperatingContextSeeder::class)->run();

    $company = Company::query()->where('status', 'active')->orderBy('id')->firstOrFail();
    $branch = Branch::query()->where('company_id', $company->getKey())->where('status', 'active')->orderBy('id')->firstOrFail();
    $period = FinancialPeriod::query()->where('company_id', $company->getKey())->where('is_closed', false)->orderBy('id')->firstOrFail();

    costCenterSetOperatingContext($company, $branch, $period);

    return compact('company', 'branch', 'period');
}

function costCenterCreateOperatingContext(string $name): array
{
    $documentNumbers = app(DocumentNumberService::class);

    $company = Company::query()->create([
        ...$documentNumbers->next('companies', Company::class),
        'name' => $name,
        'legal_name' => $name,
        'status' => 'active',
        'country' => 'Egypt',
    ]);
    $branch = Branch::query()->create([
        ...$documentNumbers->next('branches', Branch::class),
        'company_id' => $company->getKey(),
        'name' => "{$name} Branch",
        'type' => Branch::TypeAdministrative,
        'status' => 'active',
    ]);
    $period = FinancialPeriod::query()->create([
        ...$documentNumbers->nextForCompany('financial_periods', FinancialPeriod::class, $company->getKey()),
        'company_id' => $company->getKey(),
        'name' => (string) now()->year,
        'from_date' => now()->startOfYear()->toDateString(),
        'to_date' => now()->endOfYear()->toDateString(),
        'is_closed' => false,
        'allows_opening_entries' => true,
    ]);

    costCenterSetOperatingContext($company, $branch, $period);

    return compact('company', 'branch', 'period');
}

function costCenterActor(array $permissions): User
{
    costCenterEnsureOperatingContext();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $user = User::factory()->create();
    $user->givePermissionTo($permissions);
    auth()->login($user);
    request()->setUserResolver(fn (): User => $user);

    return $user;
}

function costCenterPayload(array $overrides = []): array
{
    return [
        'cost_center_code' => '100',
        'name' => 'مركز تكلفة اختبار',
        'parent_doc_num' => null,
        'is_group' => '0',
        'status' => 'active',
        'notes' => null,
        ...$overrides,
    ];
}

function costCenterDataTableQuery(int $orderColumn = 2, string $direction = 'asc'): array
{
    $columns = [
        'checkbox',
        'doc_num',
        'cost_center_code',
        'name',
        'parent',
        'is_group',
        'status',
        'created_by',
        'created_at',
        'updated_by',
        'updated_at',
        'actions',
    ];

    return [
        'draw' => 1,
        'start' => 0,
        'length' => 10,
        'search' => ['value' => '', 'regex' => 'false'],
        'order' => [['column' => $orderColumn, 'dir' => $direction]],
        'columns' => collect($columns)->map(fn (string $column): array => [
            'data' => $column,
            'name' => $column,
            'searchable' => ! in_array($column, ['checkbox', 'actions'], true) ? 'true' : 'false',
            'orderable' => ! in_array($column, ['checkbox', 'actions'], true) ? 'true' : 'false',
            'search' => ['value' => '', 'regex' => 'false'],
        ])->all(),
    ];
}

test('cost center permissions are discovered and menu item appears under chart of accounts', function () {
    $this->seed(PermissionSeeder::class);

    $admin = Role::query()->where('name', 'admin')->where('guard_name', 'web')->firstOrFail();

    foreach (['view', 'create', 'edit', 'delete', 'clone', 'view_trashed', 'restore', 'print', 'export', 'document_number.control', 'document_number_settings.update'] as $action) {
        expect(Permission::query()->where('name', "cost_centers.{$action}")->exists())->toBeTrue()
            ->and($admin->hasPermissionTo("cost_centers.{$action}"))->toBeTrue();
    }

    $actor = costCenterActor(['accounts.view', 'cost_centers.view']);
    $menu = app(MenuService::class)->getMenu($actor);
    $generalLedger = collect($menu)->firstWhere('label', 'general_ledger');
    $children = collect($generalLedger['children'] ?? [])->pluck('label')->all();

    expect($children)->toContain('chart_of_accounts', 'cost_centers')
        ->and(array_search('cost_centers', $children, true))->toBe(array_search('chart_of_accounts', $children, true) + 1);
});

test('cost centers index create form and data endpoint use the tree screen without account-only fields', function () {
    $actor = costCenterActor(['cost_centers.view', 'cost_centers.create', 'cost_centers.edit', 'cost_centers.delete', 'cost_centers.clone', 'cost_centers.export']);

    $this->actingAs($actor)
        ->get(route('admin.accounting.cost-centers.index'))
        ->assertOk()
        ->assertSee(__('cost_centers.title'))
        ->assertSee(__('cost_centers.actions.expand_all'))
        ->assertSee(__('cost_centers.actions.collapse_all'))
        ->assertSee('class="gap-2 d-none align-items-center cost-centers-tree-controls"', false)
        ->assertSee('data-cost-centers-tree-expand-all', false)
        ->assertSee('data-cost-centers-tree-collapse-all', false)
        ->assertSee(route('admin.accounting.cost-centers.data'), false)
        ->assertSee(route('admin.accounting.cost-centers.tree'), false)
        ->assertDontSee(__('accounts.attributes.classification'))
        ->assertDontSee(__('accounts.attributes.statement_type'))
        ->assertDontSee(__('accounts.attributes.normal_balance'))
        ->assertDontSee(__('accounts.attributes.is_postable'));

    $this->actingAs($actor)
        ->get(route('admin.accounting.cost-centers.create'))
        ->assertOk()
        ->assertSee(__('cost_centers.attributes.cost_center_code'))
        ->assertSee(__('cost_centers.attributes.parent'))
        ->assertSee(__('cost_centers.attributes.is_group'))
        ->assertDontSee(__('accounts.attributes.classification'))
        ->assertDontSee(__('accounts.attributes.normal_balance'))
        ->assertDontSee('account_type');

    expect(Schema::hasColumn('cost_centers', 'is_group'))->toBeTrue();

    $this->actingAs($actor)
        ->getJson(route('admin.accounting.cost-centers.data'))
        ->assertOk()
        ->assertJsonMissingPath('data.0.id')
        ->assertJsonStructure(['data']);
});

test('cost centers tree javascript exposes accessible controls and keyboard navigation', function (): void {
    $script = file_get_contents(public_path('assets/js/modules/Accounting/cost-centers.js'));

    expect($script)
        ->toContain('data-cost-centers-tree-expand-all')
        ->toContain('data-cost-centers-tree-collapse-all')
        ->toContain('role="tree"')
        ->toContain('role="treeitem"')
        ->toContain('aria-expanded')
        ->toContain('aria-level')
        ->toContain('tabindex="-1"')
        ->toContain('keydown.costCentersTree')
        ->toContain('ArrowDown')
        ->toContain('ArrowUp')
        ->toContain('ArrowRight')
        ->toContain('ArrowLeft')
        ->toContain('Home')
        ->toContain('End')
        ->toContain('Enter')
        ->toContain('visibleTreeNodes')
        ->toContain('.collapse-hidden, .treeview-list:not(.show)')
        ->toContain("document.documentElement.getAttribute('dir')");
});

test('cost center crud tree and exports are company scoped and public-doc-number based', function () {
    $actor = costCenterActor([
        'cost_centers.view',
        'cost_centers.create',
        'cost_centers.edit',
        'cost_centers.delete',
        'cost_centers.view_trashed',
        'cost_centers.restore',
        'cost_centers.export',
    ]);

    $rootResponse = $this->actingAs($actor)
        ->postJson(route('admin.accounting.cost-centers.store'), costCenterPayload([
            'cost_center_code' => '100',
            'name' => 'Factory',
            'is_group' => '1',
        ]))
        ->assertOk()
        ->assertJsonPath('success', true);

    $root = CostCenter::query()->where('doc_num', $rootResponse->json('data.doc_num'))->firstOrFail();

    $childResponse = $this->actingAs($actor)
        ->postJson(route('admin.accounting.cost-centers.store'), costCenterPayload([
            'cost_center_code' => '',
            'name' => 'Cutting Line',
            'parent_doc_num' => $root->doc_num,
        ]))
        ->assertOk()
        ->assertJsonPath('success', true);

    $child = CostCenter::query()->where('doc_num', $childResponse->json('data.doc_num'))->firstOrFail();

    expect($root->doc_num)->toStartWith('CC-')
        ->and($root->is_group)->toBeTrue()
        ->and($child->parent_id)->toBe($root->getKey())
        ->and($child->cost_center_code)->toBe('1001')
        ->and($child->company_id)->toBe($root->company_id);

    $this->actingAs($actor)
        ->getJson(route('admin.accounting.cost-centers.tree'))
        ->assertOk()
        ->assertJsonPath('data.0.cost_center_code', '100')
        ->assertJsonPath('data.0.is_group', true)
        ->assertJsonPath('data.0.children.0.cost_center_code', '1001');

    $data = $this->actingAs($actor)
        ->getJson(route('admin.accounting.cost-centers.data', costCenterDataTableQuery()))
        ->assertOk()
        ->assertJsonMissingPath('data.0.id')
        ->json('data');

    expect(json_encode($data))->toContain('Factory', 'Cutting Line')
        ->not->toContain('account_type', 'normal_balance', 'is_postable');

    $this->actingAs($actor)
        ->get(route('admin.accounting.cost-centers.export.csv'))
        ->assertOk();

    $this->actingAs($actor)
        ->deleteJson(route('admin.accounting.cost-centers.destroy', $root->doc_num))
        ->assertStatus(422);

    $this->actingAs($actor)
        ->deleteJson(route('admin.accounting.cost-centers.destroy', $child->doc_num))
        ->assertOk()
        ->assertJsonPath('success', true);

    expect(CostCenter::withTrashed()->whereKey($child->getKey())->first()?->trashed())->toBeTrue();

    $this->actingAs($actor)
        ->patchJson(route('admin.accounting.cost-centers.restore', $child->doc_num))
        ->assertOk()
        ->assertJsonPath('success', true);

    expect(CostCenter::withTrashed()->whereKey($child->getKey())->first()?->trashed())->toBeFalse();
});

test('cost center create normal save behaves as save and new', function () {
    $actor = costCenterActor(['cost_centers.view', 'cost_centers.create', 'cost_centers.edit']);

    $response = $this->actingAs($actor)
        ->postJson(route('admin.accounting.cost-centers.store'), costCenterPayload([
            'cost_center_code' => '210',
            'name' => 'Packaging Center',
        ]))
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('submit_action', 'save_new')
        ->assertJsonPath('reset_form', true);

    expect($response->json('redirect'))->toBeNull()
        ->and(CostCenter::query()->where('cost_center_code', '210')->where('name', 'Packaging Center')->exists())->toBeTrue();

    $this->actingAs($actor)
        ->get(route('admin.accounting.cost-centers.create'))
        ->assertOk()
        ->assertDontSee('Packaging Center', false)
        ->assertDontSee('210', false);
});

test('cost center code generation follows the account child code pattern', function () {
    $actor = costCenterActor(['cost_centers.view', 'cost_centers.create', 'cost_centers.edit']);

    $rootOneDocNum = $this->actingAs($actor)
        ->postJson(route('admin.accounting.cost-centers.store'), costCenterPayload([
            'cost_center_code' => '',
            'name' => 'Generated Root One',
            'is_group' => '1',
        ]))
        ->assertOk()
        ->assertJsonPath('success', true)
        ->json('data.doc_num');
    $rootOne = CostCenter::query()->where('doc_num', $rootOneDocNum)->firstOrFail();

    $rootTwoDocNum = $this->actingAs($actor)
        ->postJson(route('admin.accounting.cost-centers.store'), costCenterPayload([
            'cost_center_code' => '',
            'name' => 'Generated Root Two',
            'is_group' => '1',
        ]))
        ->assertOk()
        ->assertJsonPath('success', true)
        ->json('data.doc_num');
    $rootTwo = CostCenter::query()->where('doc_num', $rootTwoDocNum)->firstOrFail();

    $childOneDocNum = $this->actingAs($actor)
        ->postJson(route('admin.accounting.cost-centers.store'), costCenterPayload([
            'cost_center_code' => '',
            'name' => 'Generated Child One',
            'parent_doc_num' => $rootOne->doc_num,
        ]))
        ->assertOk()
        ->assertJsonPath('success', true)
        ->json('data.doc_num');
    $childOne = CostCenter::query()->where('doc_num', $childOneDocNum)->firstOrFail();

    $childTwoDocNum = $this->actingAs($actor)
        ->postJson(route('admin.accounting.cost-centers.store'), costCenterPayload([
            'cost_center_code' => '',
            'name' => 'Generated Child Two',
            'parent_doc_num' => $rootOne->doc_num,
        ]))
        ->assertOk()
        ->assertJsonPath('success', true)
        ->json('data.doc_num');
    $childTwo = CostCenter::query()->where('doc_num', $childTwoDocNum)->firstOrFail();

    $this->actingAs($actor)
        ->getJson(route('admin.accounting.cost-centers.next-code', ['parent_doc_num' => $rootOne->doc_num]))
        ->assertOk()
        ->assertJsonPath('data.cost_center_code', '13');

    expect($rootOne->cost_center_code)->toBe('1')
        ->and($rootTwo->cost_center_code)->toBe('2')
        ->and($childOne->cost_center_code)->toBe('11')
        ->and($childTwo->cost_center_code)->toBe('12');
});

test('cost center parent selector returns only active group cost centers in current company', function () {
    $primary = costCenterEnsureOperatingContext();
    $secondary = costCenterCreateOperatingContext('Cost Center Selector Other Company');
    $actor = costCenterActor(['cost_centers.view']);

    costCenterSetOperatingContext($primary['company'], $primary['branch'], $primary['period']);
    $activeGroup = CostCenter::query()->create([
        'company_id' => $primary['company']->getKey(),
        'doc_number' => 501,
        'doc_num' => 'CC-00501',
        'cost_center_code' => '501',
        'name' => 'Active Group Parent',
        'is_group' => true,
        'status' => 'active',
    ]);
    $nonGroup = CostCenter::query()->create([
        'company_id' => $primary['company']->getKey(),
        'doc_number' => 502,
        'doc_num' => 'CC-00502',
        'cost_center_code' => '502',
        'name' => 'Non Group Parent',
        'is_group' => false,
        'status' => 'active',
    ]);
    $inactiveGroup = CostCenter::query()->create([
        'company_id' => $primary['company']->getKey(),
        'doc_number' => 503,
        'doc_num' => 'CC-00503',
        'cost_center_code' => '503',
        'name' => 'Inactive Group Parent',
        'is_group' => true,
        'status' => 'inactive',
    ]);

    CostCenter::query()->create([
        'company_id' => $secondary['company']->getKey(),
        'doc_number' => 504,
        'doc_num' => 'CC-00504',
        'cost_center_code' => '504',
        'name' => 'Other Company Group Parent',
        'is_group' => true,
        'status' => 'active',
    ]);

    $payload = $this->actingAs($actor)
        ->getJson(route('admin.accounting.select2.cost-centers', ['q' => 'Parent']))
        ->assertOk()
        ->json('results');
    $text = json_encode($payload);

    expect($text)->toContain($activeGroup->name)
        ->not->toContain($nonGroup->name, $inactiveGroup->name, 'Other Company Group Parent');
});

test('cost center create validation failure preserves old input', function () {
    $actor = costCenterActor(['cost_centers.create']);

    $this->actingAs($actor)
        ->post(route('admin.accounting.cost-centers.store'), costCenterPayload([
            'cost_center_code' => '220',
            'name' => '',
        ]))
        ->assertSessionHasErrors(['name'])
        ->assertSessionHasInput('cost_center_code', '220');
});

test('cost center create save actions and edit update behavior remain standard', function () {
    $actor = costCenterActor(['cost_centers.view', 'cost_centers.create', 'cost_centers.edit', 'cost_centers.clone']);

    $saveView = $this->actingAs($actor)
        ->postJson(route('admin.accounting.cost-centers.store'), costCenterPayload([
            'cost_center_code' => '230',
            'submit_action' => 'save_view',
        ]))
        ->assertOk()
        ->assertJsonPath('submit_action', 'save_view');
    $costCenter = CostCenter::query()->where('doc_num', $saveView->json('data.doc_num'))->firstOrFail();

    expect($saveView->json('redirect'))->toBe(route('admin.accounting.cost-centers.show', $costCenter->doc_num));

    $saveNew = $this->actingAs($actor)
        ->postJson(route('admin.accounting.cost-centers.store'), costCenterPayload([
            'cost_center_code' => '231',
            'submit_action' => 'save_new',
        ]))
        ->assertOk()
        ->assertJsonPath('submit_action', 'save_new')
        ->assertJsonPath('reset_form', true);

    expect($saveNew->json('redirect'))->toBeNull();

    $this->actingAs($actor)
        ->putJson(route('admin.accounting.cost-centers.update', $costCenter->doc_num), costCenterPayload([
            'cost_center_code' => $costCenter->cost_center_code,
            'name' => $costCenter->name,
            'submit_action' => 'save_edit',
        ]))
        ->assertOk()
        ->assertJsonPath('success', false)
        ->assertJsonPath('type', 'no_changes')
        ->assertJsonPath('submit_action', 'save');
});

test('cost center view displays audit information without internal user ids', function () {
    $actor = costCenterActor(['cost_centers.view', 'cost_centers.create', 'cost_centers.edit', 'cost_centers.delete', 'cost_centers.view_trashed']);
    $costCenter = app(CostCenterService::class)->create(costCenterPayload([
        'cost_center_code' => '240',
        'name' => 'Audit Cost Center',
    ]));

    $this->actingAs($actor)
        ->putJson(route('admin.accounting.cost-centers.update', $costCenter->doc_num), costCenterPayload([
            'cost_center_code' => $costCenter->cost_center_code,
            'name' => 'Updated Audit Cost Center',
        ]))
        ->assertOk();

    $costCenter->refresh();

    $view = $this->actingAs($actor)
        ->get(route('admin.accounting.cost-centers.show', $costCenter->doc_num))
        ->assertOk()
        ->assertSee(__('common.fields.created_by'))
        ->assertSee(__('common.fields.created_at'))
        ->assertSee(__('common.fields.updated_by'))
        ->assertSee(__('common.fields.updated_at'))
        ->assertSee($actor->name)
        ->assertSee($actor->doc_num)
        ->assertDontSee('>'.$actor->getKey().'<', false);

    expect($view->getContent())->toContain('aria-label="'.__('common.sections.audit_information').'"');

    $this->actingAs($actor)
        ->deleteJson(route('admin.accounting.cost-centers.destroy', $costCenter->doc_num))
        ->assertOk();

    $this->actingAs($actor)
        ->get(route('admin.accounting.cost-centers.show', $costCenter->doc_num))
        ->assertOk()
        ->assertSee(__('common.fields.deleted_by'))
        ->assertSee(__('common.fields.deleted_at'))
        ->assertSee($actor->name)
        ->assertSee($actor->doc_num)
        ->assertDontSee('>'.$actor->getKey().'<', false);
});

test('cost centers reject duplicate codes cross-company parents self parent and cycles', function () {
    $primary = costCenterEnsureOperatingContext();
    $secondary = costCenterCreateOperatingContext('Cost Center Second Company');
    $actor = costCenterActor(['cost_centers.view', 'cost_centers.create', 'cost_centers.edit']);

    costCenterSetOperatingContext($secondary['company'], $secondary['branch'], $secondary['period']);
    $secondaryRoot = app(CostCenterService::class)->create(costCenterPayload([
        'cost_center_code' => '100',
        'name' => 'Secondary Factory',
        'is_group' => '1',
    ]));
    $secondaryChild = app(CostCenterService::class)->create(costCenterPayload([
        'cost_center_code' => '',
        'name' => 'Secondary Line',
        'parent_doc_num' => $secondaryRoot->doc_num,
    ]));

    costCenterSetOperatingContext($primary['company'], $primary['branch'], $primary['period']);

    $primaryRootResponse = $this->actingAs($actor)
        ->postJson(route('admin.accounting.cost-centers.store'), costCenterPayload([
            'cost_center_code' => '100',
            'name' => 'Primary Factory',
            'is_group' => '1',
        ]))
        ->assertOk()
        ->assertJsonPath('success', true);

    $primaryRoot = CostCenter::query()->where('doc_num', $primaryRootResponse->json('data.doc_num'))->firstOrFail();

    $this->actingAs($actor)
        ->postJson(route('admin.accounting.cost-centers.store'), costCenterPayload([
            'cost_center_code' => '100',
            'name' => 'Primary Duplicate',
        ]))
        ->assertJsonValidationErrors(['cost_center_code']);

    $this->actingAs($actor)
        ->postJson(route('admin.accounting.cost-centers.store'), costCenterPayload([
            'cost_center_code' => '120',
            'name' => 'Cross Company Parent',
            'parent_doc_num' => $secondaryChild->doc_num,
        ]))
        ->assertJsonValidationErrors(['parent_doc_num']);

    $this->actingAs($actor)
        ->get(route('admin.accounting.cost-centers.show', $secondaryChild->doc_num))
        ->assertNotFound();

    $primaryChildResponse = $this->actingAs($actor)
        ->postJson(route('admin.accounting.cost-centers.store'), costCenterPayload([
            'cost_center_code' => '',
            'name' => 'Primary Line',
            'parent_doc_num' => $primaryRoot->doc_num,
        ]))
        ->assertOk();

    $primaryChild = CostCenter::query()->where('doc_num', $primaryChildResponse->json('data.doc_num'))->firstOrFail();

    $this->actingAs($actor)
        ->putJson(route('admin.accounting.cost-centers.update', $primaryRoot->doc_num), costCenterPayload([
            'cost_center_code' => $primaryRoot->cost_center_code,
            'name' => $primaryRoot->name,
            'parent_doc_num' => $primaryRoot->doc_num,
        ]))
        ->assertJsonValidationErrors(['parent_doc_num']);

    $this->actingAs($actor)
        ->putJson(route('admin.accounting.cost-centers.update', $primaryRoot->doc_num), costCenterPayload([
            'cost_center_code' => $primaryRoot->cost_center_code,
            'name' => $primaryRoot->name,
            'parent_doc_num' => $primaryChild->doc_num,
        ]))
        ->assertJsonValidationErrors(['parent_doc_num']);

    $dataContent = $this->actingAs($actor)
        ->getJson(route('admin.accounting.cost-centers.data', [
            ...costCenterDataTableQuery(),
            'length' => 100,
        ]))
        ->assertOk()
        ->getContent();

    expect($dataContent)->toContain('Primary Factory')
        ->not->toContain('Secondary Factory', 'Secondary Line');
});

test('cost center validation rejects non group and inactive parents', function () {
    $context = costCenterEnsureOperatingContext();
    $actor = costCenterActor(['cost_centers.view', 'cost_centers.create']);
    costCenterSetOperatingContext($context['company'], $context['branch'], $context['period']);

    $nonGroup = CostCenter::query()->create([
        'company_id' => $context['company']->getKey(),
        'doc_number' => 610,
        'doc_num' => 'CC-00610',
        'cost_center_code' => '610',
        'name' => 'Plain Cost Center',
        'is_group' => false,
        'status' => 'active',
    ]);
    $inactiveGroup = CostCenter::query()->create([
        'company_id' => $context['company']->getKey(),
        'doc_number' => 611,
        'doc_num' => 'CC-00611',
        'cost_center_code' => '611',
        'name' => 'Inactive Group Center',
        'is_group' => true,
        'status' => 'inactive',
    ]);

    $this->actingAs($actor)
        ->postJson(route('admin.accounting.cost-centers.store'), costCenterPayload([
            'cost_center_code' => '',
            'name' => 'Child Under Non Group',
            'parent_doc_num' => $nonGroup->doc_num,
        ]))
        ->assertJsonValidationErrors(['parent_doc_num']);

    $this->actingAs($actor)
        ->postJson(route('admin.accounting.cost-centers.store'), costCenterPayload([
            'cost_center_code' => '',
            'name' => 'Child Under Inactive Group',
            'parent_doc_num' => $inactiveGroup->doc_num,
        ]))
        ->assertJsonValidationErrors(['parent_doc_num']);
});
