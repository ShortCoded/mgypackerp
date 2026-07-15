<?php

use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Modules\Auth\Database\Seeders\PermissionSeeder;
use Modules\Auth\Models\Role;
use Modules\Auth\Services\PermissionRegistryService;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\MenuService;
use Modules\Core\Services\OperatingContextService;
use Modules\Sales\Models\ProjectStructure;
use Modules\Sales\Models\ProjectStructureModel;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

function projectStructureSetupActor(array $permissions): User
{
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

/**
 * @return array{company: Company, branch: Branch, period: FinancialPeriod}
 */
function projectStructureSetupContext(object $test): array
{
    static $documentNumber = 7200;

    $documentNumber++;

    $company = Company::query()->create([
        'doc_number' => $documentNumber,
        'doc_num' => 'Company-'.str_pad((string) $documentNumber, 5, '0', STR_PAD_LEFT),
        'name' => 'Project Structure Company '.$documentNumber,
        'status' => 'active',
        'is_main' => ! Company::query()->where('is_main', true)->exists(),
    ]);
    $branch = Branch::query()->create([
        'doc_number' => $documentNumber,
        'doc_num' => 'Branch-'.str_pad((string) $documentNumber, 5, '0', STR_PAD_LEFT),
        'company_id' => $company->getKey(),
        'name' => 'Project Structure Branch '.$documentNumber,
        'type' => 'administrative',
        'status' => 'active',
    ]);
    $period = FinancialPeriod::query()->create([
        'doc_number' => $documentNumber,
        'doc_num' => 'Period-'.str_pad((string) $documentNumber, 5, '0', STR_PAD_LEFT),
        'company_id' => $company->getKey(),
        'name' => 'Project Structure Period '.$documentNumber,
        'from_date' => '2026-01-01',
        'to_date' => '2026-12-31',
        'is_closed' => false,
        'status' => 'active',
    ]);
    $context = [
        OperatingContextService::CompanyIdKey => $company->getKey(),
        OperatingContextService::CompanyDocNumKey => $company->doc_num,
        OperatingContextService::BranchIdKey => $branch->getKey(),
        OperatingContextService::BranchDocNumKey => $branch->doc_num,
        OperatingContextService::FinancialPeriodIdKey => $period->getKey(),
        OperatingContextService::FinancialPeriodDocNumKey => $period->doc_num,
    ];

    if (! request()->hasSession()) {
        request()->setLaravelSession(app('session.store'));
    }

    session($context);
    $test->withSession($context);

    return ['company' => $company, 'branch' => $branch, 'period' => $period];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function projectStructureSetupStructurePayload(array $overrides = []): array
{
    return [
        'name' => 'Building',
        'code' => 'BLDG',
        'parent_doc_num' => null,
        'status' => 'active',
        'notes' => null,
        'submit_action' => 'save_new',
        ...$overrides,
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function projectStructureSetupModelPayload(array $overrides = []): array
{
    return [
        'name' => 'Standard Room',
        'code' => 'STD-ROOM',
        'short_name' => 'STD',
        'status' => 'active',
        'submit_action' => 'save_new',
        ...$overrides,
    ];
}

function projectStructureSetupCreateStructure(object $test, User $actor, array $overrides = []): ProjectStructure
{
    $response = $test->actingAs($actor)
        ->postJson(route('admin.sales.project-structures.store'), projectStructureSetupStructurePayload($overrides))
        ->assertOk()
        ->assertJsonPath('success', true);

    return ProjectStructure::query()->where('doc_num', $response->json('data.doc_num'))->firstOrFail();
}

function projectStructureSetupCreateModel(object $test, User $actor, array $overrides = []): ProjectStructureModel
{
    $response = $test->actingAs($actor)
        ->postJson(route('admin.sales.project-structure-models.store'), projectStructureSetupModelPayload($overrides))
        ->assertOk()
        ->assertJsonPath('success', true);

    return ProjectStructureModel::query()->where('doc_num', $response->json('data.doc_num'))->firstOrFail();
}

/**
 * @return array<string, mixed>
 */
function projectStructureSetupStructureDataTableQuery(array $overrides = []): array
{
    return [
        'draw' => 1,
        'start' => 0,
        'length' => 10,
        'search' => ['value' => '', 'regex' => 'false'],
        'order' => [['column' => 1, 'dir' => 'desc']],
        'columns' => [
            ['data' => 'checkbox', 'name' => 'checkbox', 'searchable' => 'false', 'orderable' => 'false', 'search' => ['value' => '', 'regex' => 'false']],
            ['data' => 'doc_num', 'name' => 'project_structures.doc_number', 'searchable' => 'true', 'orderable' => 'true', 'search' => ['value' => '', 'regex' => 'false']],
            ['data' => 'name', 'name' => 'name', 'searchable' => 'true', 'orderable' => 'true', 'search' => ['value' => '', 'regex' => 'false']],
            ['data' => 'code', 'name' => 'code', 'searchable' => 'true', 'orderable' => 'true', 'search' => ['value' => '', 'regex' => 'false']],
            ['data' => 'parent', 'name' => 'parent', 'searchable' => 'true', 'orderable' => 'true', 'search' => ['value' => '', 'regex' => 'false']],
            ['data' => 'status', 'name' => 'status', 'searchable' => 'true', 'orderable' => 'true', 'search' => ['value' => '', 'regex' => 'false']],
            ['data' => 'created_by', 'name' => 'created_by', 'searchable' => 'true', 'orderable' => 'true', 'search' => ['value' => '', 'regex' => 'false']],
            ['data' => 'created_at', 'name' => 'created_at', 'searchable' => 'true', 'orderable' => 'true', 'search' => ['value' => '', 'regex' => 'false']],
            ['data' => 'updated_by', 'name' => 'updated_by', 'searchable' => 'true', 'orderable' => 'true', 'search' => ['value' => '', 'regex' => 'false']],
            ['data' => 'updated_at', 'name' => 'updated_at', 'searchable' => 'true', 'orderable' => 'true', 'search' => ['value' => '', 'regex' => 'false']],
            ['data' => 'deleted_by', 'name' => 'deleted_by', 'searchable' => 'true', 'orderable' => 'true', 'search' => ['value' => '', 'regex' => 'false']],
            ['data' => 'deleted_at', 'name' => 'deleted_at', 'searchable' => 'true', 'orderable' => 'true', 'search' => ['value' => '', 'regex' => 'false']],
            ['data' => 'actions', 'name' => 'actions', 'searchable' => 'false', 'orderable' => 'false', 'search' => ['value' => '', 'regex' => 'false']],
        ],
        ...$overrides,
    ];
}

/**
 * @return array<string, mixed>
 */
function projectStructureSetupModelDataTableQuery(array $overrides = []): array
{
    return [
        'draw' => 1,
        'start' => 0,
        'length' => 10,
        'search' => ['value' => '', 'regex' => 'false'],
        'order' => [['column' => 1, 'dir' => 'desc']],
        'columns' => [
            ['data' => 'checkbox', 'name' => 'checkbox', 'searchable' => 'false', 'orderable' => 'false', 'search' => ['value' => '', 'regex' => 'false']],
            ['data' => 'doc_num', 'name' => 'project_structure_models.doc_number', 'searchable' => 'true', 'orderable' => 'true', 'search' => ['value' => '', 'regex' => 'false']],
            ['data' => 'name', 'name' => 'name', 'searchable' => 'true', 'orderable' => 'true', 'search' => ['value' => '', 'regex' => 'false']],
            ['data' => 'code', 'name' => 'code', 'searchable' => 'true', 'orderable' => 'true', 'search' => ['value' => '', 'regex' => 'false']],
            ['data' => 'short_name', 'name' => 'short_name', 'searchable' => 'true', 'orderable' => 'true', 'search' => ['value' => '', 'regex' => 'false']],
            ['data' => 'status', 'name' => 'status', 'searchable' => 'true', 'orderable' => 'true', 'search' => ['value' => '', 'regex' => 'false']],
            ['data' => 'created_by', 'name' => 'created_by', 'searchable' => 'true', 'orderable' => 'true', 'search' => ['value' => '', 'regex' => 'false']],
            ['data' => 'created_at', 'name' => 'created_at', 'searchable' => 'true', 'orderable' => 'true', 'search' => ['value' => '', 'regex' => 'false']],
            ['data' => 'updated_by', 'name' => 'updated_by', 'searchable' => 'true', 'orderable' => 'true', 'search' => ['value' => '', 'regex' => 'false']],
            ['data' => 'updated_at', 'name' => 'updated_at', 'searchable' => 'true', 'orderable' => 'true', 'search' => ['value' => '', 'regex' => 'false']],
            ['data' => 'deleted_by', 'name' => 'deleted_by', 'searchable' => 'true', 'orderable' => 'true', 'search' => ['value' => '', 'regex' => 'false']],
            ['data' => 'deleted_at', 'name' => 'deleted_at', 'searchable' => 'true', 'orderable' => 'true', 'search' => ['value' => '', 'regex' => 'false']],
            ['data' => 'actions', 'name' => 'actions', 'searchable' => 'false', 'orderable' => 'false', 'search' => ['value' => '', 'regex' => 'false']],
        ],
        ...$overrides,
    ];
}

function projectStructureSetupCloneToken(string $html): string
{
    preg_match('/name="clone_source_token" value="([^"]+)"/', $html, $matches);

    expect($matches[1] ?? null)->not->toBeNull();

    return $matches[1];
}

test('PermissionSeeder discovers ProjectStructure setup permissions and Sales menu entries', function (): void {
    $this->seed(PermissionSeeder::class);

    $permissions = app(PermissionRegistryService::class)->all();
    $adminRole = Role::query()
        ->where('name', 'admin')
        ->where('guard_name', 'web')
        ->firstOrFail();
    $expected = [
        'project_structures.view',
        'project_structures.create',
        'project_structures.clone',
        'project_structures.edit',
        'project_structures.delete',
        'project_structures.view_trashed',
        'project_structures.restore',
        'project_structures.document_number.control',
        'project_structures.document_number_settings.update',
        'project_structures.tree.view',
        'project_structures.tree.manage',
        'project_structure_models.view',
        'project_structure_models.create',
        'project_structure_models.clone',
        'project_structure_models.edit',
        'project_structure_models.delete',
        'project_structure_models.view_trashed',
        'project_structure_models.restore',
        'project_structure_models.document_number.control',
        'project_structure_models.document_number_settings.update',
    ];

    foreach ($expected as $permission) {
        expect($permissions)->toContain($permission)
            ->and(Permission::query()->where('name', $permission)->exists())->toBeTrue()
            ->and($adminRole->hasPermissionTo($permission))->toBeTrue();
    }

    expect(config('document_numbers.project_structures'))->toMatchArray(['prefix' => 'PST-', 'padding' => 5])
        ->and(config('document_numbers.project_structure_models'))->toMatchArray(['prefix' => 'PSM-', 'padding' => 5])
        ->and(is_file(lang_path('en/project_structures.php')))->toBeTrue()
        ->and(is_file(lang_path('ar/project_structures.php')))->toBeTrue()
        ->and(is_file(lang_path('en/project_structure_models.php')))->toBeTrue()
        ->and(is_file(lang_path('ar/project_structure_models.php')))->toBeTrue()
        ->and(__('menu.project_structures', locale: 'en'))->toBe('Project Structures')
        ->and(__('menu.project_structures', locale: 'ar'))->toBe('هياكل المشاريع')
        ->and(__('menu.project_structure_models', locale: 'en'))->toBe('Project Structure Models')
        ->and(__('menu.project_structure_models', locale: 'ar'))->toBe('نماذج الهيكل');

    $actor = projectStructureSetupActor([
        'customers.view',
        'quotations.view',
        'project_structures.view',
        'project_structure_models.view',
    ]);
    $sales = collect(app(MenuService::class)->getMenu($actor))->firstWhere('label', 'sales');

    expect(collect($sales['children'] ?? [])->pluck('label')->all())->toBe([
        'customers',
        'quotations',
        'project_structures',
        'project_structure_models',
    ]);
});

test('ProjectStructure creates root and child nodes without manual level and exposes tree and table views', function (): void {
    projectStructureSetupContext($this);
    $actor = projectStructureSetupActor([
        'project_structures.view',
        'project_structures.create',
        'project_structures.delete',
        'project_structures.view_trashed',
        'project_structures.restore',
        'project_structures.tree.view',
    ]);

    $this->actingAs($actor)
        ->get(route('admin.sales.project-structures.index'))
        ->assertOk()
        ->assertSee(__('project_structures.title'))
        ->assertSee('js-project-structures-table', false)
        ->assertSee('data-project-structures-toggle-tree', false)
        ->assertSee('assets/js/modules/Sales/project-structures.js', false)
        ->assertDontSee('data-id=', false);

    $this->actingAs($actor)
        ->get(route('admin.sales.project-structures.create'))
        ->assertOk()
        ->assertSee('name="parent_doc_num"', false)
        ->assertDontSee('name="level"', false);

    expect(Schema::hasColumn('project_structures', 'level'))->toBeFalse();

    $root = projectStructureSetupCreateStructure($this, $actor, [
        'name' => 'Building',
        'code' => 'BLDG',
    ]);
    $child = projectStructureSetupCreateStructure($this, $actor, [
        'name' => 'Floor',
        'code' => 'FLR',
        'parent_doc_num' => $root->doc_num,
    ]);
    $inactive = projectStructureSetupCreateStructure($this, $actor, [
        'name' => 'Inactive Wing',
        'code' => 'INACTIVE-WING',
        'status' => 'inactive',
    ]);
    $deleted = projectStructureSetupCreateStructure($this, $actor, [
        'name' => 'Deleted Wing',
        'code' => 'DELETED-WING',
    ]);

    $this->actingAs($actor)
        ->deleteJson(route('admin.sales.project-structures.destroy', $deleted->doc_num))
        ->assertOk();

    expect($root->doc_num)->toBe('PST-00001')
        ->and($child->parent_id)->toBe($root->getKey());

    $tree = $this->actingAs($actor)
        ->getJson(route('admin.sales.project-structures.tree'))
        ->assertOk()
        ->assertJsonPath('success', true)
        ->json('data');
    $treeIds = function (array $nodes) use (&$treeIds): array {
        return collect($nodes)
            ->flatMap(fn (array $node): array => [$node['id'], ...$treeIds($node['children'] ?? [])])
            ->all();
    };

    expect($tree[0]['id'])->toBe($root->doc_num)
        ->and($tree[0]['children'][0]['id'])->toBe($child->doc_num)
        ->and($tree[0])->not->toHaveKeys(['internal_id', 'parent_id', 'company_id'])
        ->and($treeIds($tree))->toContain($inactive->doc_num)
        ->and($treeIds($tree))->not->toContain($deleted->doc_num);

    $row = collect($this->actingAs($actor)
        ->getJson(route('admin.sales.project-structures.data', projectStructureSetupStructureDataTableQuery()))
        ->assertOk()
        ->json('data'))
        ->first(fn (array $row): bool => str_contains((string) $row['code'], 'BLDG'));

    expect($row)->toHaveKeys(['checkbox', 'doc_num', 'name', 'code', 'parent', 'status', 'created_by', 'updated_by', 'deleted_by', 'actions'])
        ->and($row)->not->toHaveKeys(['id', 'company_id', 'parent_id', 'doc_number', 'parent_code', 'parent_name']);
});

test('ProjectStructure blocks self parent and circular parent relationships', function (): void {
    projectStructureSetupContext($this);
    $actor = projectStructureSetupActor([
        'project_structures.create',
        'project_structures.edit',
    ]);
    $root = projectStructureSetupCreateStructure($this, $actor, [
        'name' => 'Villa Floor',
        'code' => 'VFLR',
    ]);
    $child = projectStructureSetupCreateStructure($this, $actor, [
        'name' => 'Villa Room',
        'code' => 'VROOM',
        'parent_doc_num' => $root->doc_num,
    ]);

    $this->actingAs($actor)
        ->putJson(route('admin.sales.project-structures.update', $root->doc_num), projectStructureSetupStructurePayload([
            'name' => $root->name,
            'code' => $root->code,
            'parent_doc_num' => $root->doc_num,
            'status' => 'active',
            'submit_action' => 'save',
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['parent_doc_num']);

    $this->actingAs($actor)
        ->putJson(route('admin.sales.project-structures.update', $root->doc_num), projectStructureSetupStructurePayload([
            'name' => $root->name,
            'code' => $root->code,
            'parent_doc_num' => $child->doc_num,
            'status' => 'active',
            'submit_action' => 'save',
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['parent_doc_num']);
});

test('ProjectStructure edit clone bulk delete soft delete and restore use public doc nums', function (): void {
    projectStructureSetupContext($this);
    $actor = projectStructureSetupActor([
        'project_structures.view',
        'project_structures.create',
        'project_structures.clone',
        'project_structures.edit',
        'project_structures.delete',
        'project_structures.view_trashed',
        'project_structures.restore',
    ]);
    $record = projectStructureSetupCreateStructure($this, $actor, [
        'name' => 'Zone',
        'code' => 'ZONE',
    ]);

    $this->actingAs($actor)
        ->putJson(route('admin.sales.project-structures.update', $record->doc_num), projectStructureSetupStructurePayload([
            'name' => 'Updated Zone',
            'code' => 'ZONE',
            'status' => 'inactive',
            'submit_action' => 'save',
        ]))
        ->assertOk()
        ->assertJsonPath('success', true);

    expect($record->refresh()->status)->toBe('inactive')
        ->and($record->name)->toBe('Updated Zone');

    $cloneToken = projectStructureSetupCloneToken($this->actingAs($actor)
        ->get(route('admin.sales.project-structures.clone', $record->doc_num))
        ->assertOk()
        ->getContent());

    $clone = $this->actingAs($actor)
        ->postJson(route('admin.sales.project-structures.store'), projectStructureSetupStructurePayload([
            'name' => 'Updated Zone Copy',
            'code' => 'ZONE-COPY',
            'status' => 'active',
            'clone_source_token' => $cloneToken,
        ]))
        ->assertOk()
        ->assertJsonPath('success', true)
        ->json('data.doc_num');

    expect(ProjectStructure::query()->where('doc_num', $clone)->exists())->toBeTrue();

    $bulkRecord = projectStructureSetupCreateStructure($this, $actor, [
        'name' => 'Bulk Delete Node',
        'code' => 'BULK-NODE',
    ]);

    $this->actingAs($actor)
        ->deleteJson(route('admin.sales.project-structures.bulk-delete'), ['doc_nums' => [$bulkRecord->doc_num]])
        ->assertOk()
        ->assertJsonPath('success', true);

    expect(ProjectStructure::withTrashed()->where('doc_num', $bulkRecord->doc_num)->first()?->trashed())->toBeTrue();

    $this->actingAs($actor)
        ->getJson(route('admin.sales.project-structures.data', projectStructureSetupStructureDataTableQuery(['trash_filter' => 'trashed'])))
        ->assertOk()
        ->assertSee($bulkRecord->doc_num, false);

    $this->actingAs($actor)
        ->patchJson(route('admin.sales.project-structures.restore', $bulkRecord->doc_num))
        ->assertOk()
        ->assertJsonPath('success', true);

    $this->actingAs($actor)
        ->deleteJson(route('admin.sales.project-structures.destroy', $record->doc_num))
        ->assertOk()
        ->assertJsonPath('success', true);

    expect(ProjectStructure::withTrashed()->where('doc_num', $record->doc_num)->first()?->trashed())->toBeTrue();
});

test('ProjectStructureModel validates required fields optional notes and active global uniqueness', function (): void {
    projectStructureSetupContext($this);
    $actor = projectStructureSetupActor([
        'project_structure_models.view',
        'project_structure_models.create',
        'project_structure_models.delete',
    ]);

    expect(Schema::hasColumn('project_structure_models', 'short_name'))->toBeTrue()
        ->and(Schema::hasColumn('project_structure_models', 'project_structure_id'))->toBeFalse();

    $this->actingAs($actor)
        ->get(route('admin.sales.project-structure-models.create'))
        ->assertOk()
        ->assertDontSee('name="project_structure_doc_num"', false)
        ->assertDontSee('admin/sales/select2/project-structures', false);

    $this->actingAs($actor)
        ->postJson(route('admin.sales.project-structure-models.store'), [
            'status' => 'active',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'code', 'short_name']);

    $standard = projectStructureSetupCreateModel($this, $actor);

    expect($standard->notes)->toBeNull();

    $this->actingAs($actor)
        ->postJson(route('admin.sales.project-structure-models.store'), projectStructureSetupModelPayload([
            'name' => 'Another Standard',
            'code' => 'STD-ROOM',
            'short_name' => 'STD2',
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['code']);

    $this->actingAs($actor)
        ->postJson(route('admin.sales.project-structure-models.store'), projectStructureSetupModelPayload([
            'name' => 'Another Short Name',
            'code' => 'ALT-ROOM',
            'short_name' => 'STD',
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['short_name']);

    $this->actingAs($actor)
        ->postJson(route('admin.sales.project-structure-models.store'), projectStructureSetupModelPayload([
            'name' => 'Main Building',
            'code' => 'STD-ROOM',
            'short_name' => 'MAIN',
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['code']);

    $this->actingAs($actor)
        ->postJson(route('admin.sales.project-structure-models.store'), projectStructureSetupModelPayload([
            'name' => 'Service Building',
            'code' => 'SVC-BLDG',
            'short_name' => 'STD',
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['short_name']);

    projectStructureSetupCreateModel($this, $actor, [
        'name' => 'Main Building',
        'code' => 'MAIN-BLDG',
        'short_name' => 'MAIN',
    ]);

    $this->actingAs($actor)
        ->deleteJson(route('admin.sales.project-structure-models.destroy', $standard->doc_num))
        ->assertOk()
        ->assertJsonPath('success', true);

    projectStructureSetupCreateModel($this, $actor, [
        'name' => 'Reused Standard',
        'code' => 'STD-ROOM',
        'short_name' => 'STD',
    ]);
});

test('ProjectStructureModel datatable edit clone bulk delete soft delete and restore use public doc nums', function (): void {
    projectStructureSetupContext($this);
    $actor = projectStructureSetupActor([
        'project_structure_models.view',
        'project_structure_models.create',
        'project_structure_models.clone',
        'project_structure_models.edit',
        'project_structure_models.delete',
        'project_structure_models.view_trashed',
        'project_structure_models.restore',
    ]);
    $record = projectStructureSetupCreateModel($this, $actor, [
        'name' => 'Deluxe Room',
        'code' => 'DLX-ROOM',
        'short_name' => 'DLX',
        'notes' => 'Optional notes',
    ]);

    $this->actingAs($actor)
        ->get(route('admin.sales.project-structure-models.index'))
        ->assertOk()
        ->assertSee(__('project_structure_models.title'))
        ->assertSee('js-project-structure-models-table', false)
        ->assertSee('assets/js/modules/Sales/project-structure-models.js', false)
        ->assertDontSee('project_structure_doc_num', false)
        ->assertDontSee('data-id=', false);

    $row = collect($this->actingAs($actor)
        ->getJson(route('admin.sales.project-structure-models.data', projectStructureSetupModelDataTableQuery()))
        ->assertOk()
        ->json('data'))
        ->first(fn (array $row): bool => str_contains((string) $row['code'], 'DLX-ROOM'));

    expect($row)->toHaveKeys(['checkbox', 'doc_num', 'name', 'code', 'short_name', 'status', 'created_by', 'updated_by', 'actions'])
        ->and($row)->not->toHaveKeys(['id', 'company_id', 'project_structure_id', 'doc_number', 'project_structure_doc_num']);

    $this->actingAs($actor)
        ->putJson(route('admin.sales.project-structure-models.update', $record->doc_num), projectStructureSetupModelPayload([
            'name' => 'Updated Deluxe Room',
            'code' => 'DLX-ROOM',
            'short_name' => 'DLX',
            'status' => 'inactive',
            'notes' => null,
            'submit_action' => 'save',
        ]))
        ->assertOk()
        ->assertJsonPath('success', true);

    expect($record->refresh()->name)->toBe('Updated Deluxe Room')
        ->and($record->status)->toBe('inactive');

    $cloneToken = projectStructureSetupCloneToken($this->actingAs($actor)
        ->get(route('admin.sales.project-structure-models.clone', $record->doc_num))
        ->assertOk()
        ->getContent());

    $clone = $this->actingAs($actor)
        ->postJson(route('admin.sales.project-structure-models.store'), projectStructureSetupModelPayload([
            'name' => 'Updated Deluxe Room Copy',
            'code' => 'DLX-ROOM-COPY',
            'short_name' => 'DLX2',
            'clone_source_token' => $cloneToken,
        ]))
        ->assertOk()
        ->assertJsonPath('success', true)
        ->json('data.doc_num');

    expect(ProjectStructureModel::query()->where('doc_num', $clone)->exists())->toBeTrue();

    $bulkRecord = projectStructureSetupCreateModel($this, $actor, [
        'name' => 'Bulk Delete Model',
        'code' => 'BULK-MODEL',
        'short_name' => 'BULK',
    ]);

    $this->actingAs($actor)
        ->deleteJson(route('admin.sales.project-structure-models.bulk-delete'), ['doc_nums' => [$bulkRecord->doc_num]])
        ->assertOk()
        ->assertJsonPath('success', true);

    expect(ProjectStructureModel::withTrashed()->where('doc_num', $bulkRecord->doc_num)->first()?->trashed())->toBeTrue();

    $this->actingAs($actor)
        ->getJson(route('admin.sales.project-structure-models.data', projectStructureSetupModelDataTableQuery(['trash_filter' => 'trashed'])))
        ->assertOk()
        ->assertSee($bulkRecord->doc_num, false);

    $this->actingAs($actor)
        ->patchJson(route('admin.sales.project-structure-models.restore', $bulkRecord->doc_num))
        ->assertOk()
        ->assertJsonPath('success', true);

    $this->actingAs($actor)
        ->deleteJson(route('admin.sales.project-structure-models.destroy', $record->doc_num))
        ->assertOk()
        ->assertJsonPath('success', true);

    expect(ProjectStructureModel::withTrashed()->where('doc_num', $record->doc_num)->first()?->trashed())->toBeTrue();
});
