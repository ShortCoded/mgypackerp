<?php

use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;
use Modules\Auth\Database\Seeders\PermissionSeeder;
use Modules\Auth\Models\Role;
use Modules\Auth\Services\PermissionRegistryService;
use Modules\Core\Models\Setting;
use Modules\Core\Services\SettingService;
use Modules\HR\Models\HrAllowance;
use Modules\HR\Models\HrArea;
use Modules\HR\Models\HrCity;
use Modules\HR\Models\HrCountry;
use Modules\HR\Models\HrFaculty;
use Modules\HR\Models\HrGovernorate;
use Modules\HR\Models\HrHiringStatus;
use Modules\HR\Models\HrIdentification;
use Modules\HR\Models\HrMilitaryService;
use Modules\HR\Models\HrNationality;
use Modules\HR\Models\HrQualification;
use Modules\HR\Models\HrReligion;
use Modules\HR\Models\HrSpecialization;
use Modules\HR\Models\HrUniversity;
use Modules\HR\Services\HrLookupRegistry;
use Modules\HR\Services\HrLookupService;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    if (! Route::has('admin.hr.countries.index')) {
        $this->markTestSkipped('HR admin routes are disabled from the active app surface.');
    }
});

function hrLookupActor(array $permissions): User
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $user = User::factory()->create();
    $user->givePermissionTo($permissions);

    return $user;
}

function hrLookupPermissions(string $prefix): array
{
    return [
        "{$prefix}.view",
        "{$prefix}.create",
        "{$prefix}.edit",
        "{$prefix}.delete",
        "{$prefix}.clone",
        "{$prefix}.view_trashed",
        "{$prefix}.restore",
        "{$prefix}.document_number.control",
        "{$prefix}.document_number_settings.update",
    ];
}

function trashedHrCountry(array $attributes = []): HrCountry
{
    $country = HrCountry::query()->create([
        'name' => $attributes['name'] ?? 'Deleted Country',
        'notes' => $attributes['notes'] ?? null,
        'doc_number' => $attributes['doc_number'] ?? 501,
        'doc_num' => $attributes['doc_num'] ?? 'Country-00501',
    ]);

    $country->delete();

    return HrCountry::withTrashed()->whereKey($country->getKey())->firstOrFail();
}

dataset('hrLookups', [
    'countries' => [[
        'route' => 'admin.hr.countries',
        'permission' => 'hr.countries',
        'model' => HrCountry::class,
        'title' => 'hr.countries.title',
        'menu' => 'menu.hr_countries',
        'prefix' => 'Country-',
        'setting' => 'hr_countries',
    ]],
    'governorates' => [[
        'route' => 'admin.hr.governorates',
        'permission' => 'hr.governorates',
        'model' => HrGovernorate::class,
        'title' => 'hr.governorates.title',
        'menu' => 'menu.hr_governorates',
        'prefix' => 'Governorate-',
        'setting' => 'hr_governorates',
    ]],
    'cities' => [[
        'route' => 'admin.hr.cities',
        'permission' => 'hr.cities',
        'model' => HrCity::class,
        'title' => 'hr.cities.title',
        'menu' => 'menu.hr_cities',
        'prefix' => 'City-',
        'setting' => 'hr_cities',
    ]],
    'areas' => [[
        'route' => 'admin.hr.areas',
        'permission' => 'hr.areas',
        'model' => HrArea::class,
        'title' => 'hr.areas.title',
        'menu' => 'menu.hr_areas',
        'prefix' => 'Area-',
        'setting' => 'hr_areas',
    ]],
    'nationalities' => [[
        'route' => 'admin.hr.nationalities',
        'permission' => 'hr.nationalities',
        'model' => HrNationality::class,
        'title' => 'hr.nationalities.title',
        'menu' => 'menu.hr_nationalities',
        'prefix' => 'Nationality-',
        'setting' => 'hr_nationalities',
    ]],
    'religions' => [[
        'route' => 'admin.hr.religions',
        'permission' => 'hr.religions',
        'model' => HrReligion::class,
        'title' => 'hr.religions.title',
        'menu' => 'menu.hr_religions',
        'prefix' => 'Religion-',
        'setting' => 'hr_religions',
    ]],
    'qualifications' => [[
        'route' => 'admin.hr.qualifications',
        'permission' => 'hr.qualifications',
        'model' => HrQualification::class,
        'title' => 'hr.qualifications.title',
        'menu' => 'menu.hr_qualifications',
        'prefix' => 'Qualification-',
        'setting' => 'hr_qualifications',
    ]],
    'universities' => [[
        'route' => 'admin.hr.universities',
        'permission' => 'hr.universities',
        'model' => HrUniversity::class,
        'title' => 'hr.universities.title',
        'menu' => 'menu.hr_universities',
        'prefix' => 'University-',
        'setting' => 'hr_universities',
    ]],
    'faculties' => [[
        'route' => 'admin.hr.faculties',
        'permission' => 'hr.faculties',
        'model' => HrFaculty::class,
        'title' => 'hr.faculties.title',
        'menu' => 'menu.hr_faculties',
        'prefix' => 'Faculty-',
        'setting' => 'hr_faculties',
    ]],
    'specializations' => [[
        'route' => 'admin.hr.specializations',
        'permission' => 'hr.specializations',
        'model' => HrSpecialization::class,
        'title' => 'hr.specializations.title',
        'menu' => 'menu.hr_specializations',
        'prefix' => 'Specialization-',
        'setting' => 'hr_specializations',
    ]],
    'military services' => [[
        'route' => 'admin.hr.military-services',
        'permission' => 'hr.military_services',
        'model' => HrMilitaryService::class,
        'title' => 'hr.military_services.title',
        'menu' => 'menu.hr_military_services',
        'prefix' => 'MilitaryService-',
        'setting' => 'hr_military_services',
    ]],
    'allowances' => [[
        'route' => 'admin.hr.allowances',
        'permission' => 'hr.allowances',
        'model' => HrAllowance::class,
        'title' => 'hr.allowances.title',
        'menu' => 'menu.hr_allowances',
        'prefix' => 'Allowance-',
        'setting' => 'hr_allowances',
    ]],
    'hiring statuses' => [[
        'route' => 'admin.hr.hiring-statuses',
        'permission' => 'hr.hiring_statuses',
        'model' => HrHiringStatus::class,
        'title' => 'hr.hiring_statuses.title',
        'menu' => 'menu.hr_hiring_statuses',
        'prefix' => 'HiringStatus-',
        'setting' => 'hr_hiring_statuses',
    ]],
    'identifications' => [[
        'route' => 'admin.hr.identifications',
        'permission' => 'hr.identifications',
        'model' => HrIdentification::class,
        'title' => 'hr.identifications.title',
        'menu' => 'menu.hr_identifications',
        'prefix' => 'Identification-',
        'setting' => 'hr_identifications',
    ]],
]);

test('legacy review HR lookup permissions are discovered and assigned to admin role', function () {
    $this->seed(PermissionSeeder::class);

    $adminRole = Role::query()
        ->where('name', 'admin')
        ->where('guard_name', 'web')
        ->firstOrFail();
    $registryPermissions = app(PermissionRegistryService::class)->all();
    $adminPermissionNames = $adminRole->permissions()->pluck('name')->all();

    foreach ([
        'hr.countries',
        'hr.governorates',
        'hr.cities',
        'hr.areas',
        'hr.nationalities',
        'hr.religions',
        'hr.qualifications',
        'hr.universities',
        'hr.faculties',
        'hr.specializations',
        'hr.military_services',
        'hr.allowances',
        'hr.identifications',
        'hr.hiring_statuses',
    ] as $prefix) {
        foreach (hrLookupPermissions($prefix) as $permission) {
            expect($registryPermissions)->toContain($permission)
                ->and($adminPermissionNames)->toContain($permission)
                ->and(Permission::query()->where('name', $permission)->where('guard_name', 'web')->exists())->toBeTrue();
        }
    }
});

test('hr lookup store falls back to redirect when the request is not an ajax json save', function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);

    $actor = hrLookupActor(hrLookupPermissions('hr.religions'));

    $this->actingAs($actor)
        ->from(route('admin.hr.religions.create'))
        ->post(route('admin.hr.religions.store'), [
            'name' => 'Classic Browser Religion',
            'submit_action' => 'save_edit',
        ])
        ->assertRedirect(route('admin.hr.religions.edit', 'Religion-00001'))
        ->assertSessionHas('success', __('hr.messages.created'));
});

test('hr lookup index renders document number settings and review menu entry', function (array $definition) {
    $actor = hrLookupActor([
        "{$definition['permission']}.view",
        "{$definition['permission']}.create",
        "{$definition['permission']}.document_number_settings.update",
    ]);

    $response = $this->actingAs($actor)
        ->get(route("{$definition['route']}.index"))
        ->assertOk()
        ->assertDontSee(__('menu.basic_data'))
        ->assertSee(__($definition['title']))
        ->assertSee(route("{$definition['route']}.data"), false)
        ->assertSee(route("{$definition['route']}.document-number-settings.update"), false)
        ->assertSee('js-hr-document-number-settings-form', false)
        ->assertDontSee('id="hr_lookup_trash_filter"', false)
        ->assertDontSee('HR Countries')
        ->assertDontSee('HR Governorates')
        ->assertDontSee('HR Cities')
        ->assertDontSee('HR Areas')
        ->assertDontSee('دول الموارد البشرية')
        ->assertDontSee('محافظات الموارد البشرية')
        ->assertDontSee('مدن الموارد البشرية')
        ->assertDontSee('مناطق الموارد البشرية')
        ->assertDontSee('data-id=', false);

    $response
        ->assertSee(__('menu.human_resources'))
        ->assertSee(__($definition['menu']))
        ->assertDontSee(__('menu.hr_basic_data'));
})->with('hrLookups');

test('hr lookup trash filter is visible only with deleted record permission', function () {
    $viewer = hrLookupActor(['hr.countries.view']);

    $this->actingAs($viewer)
        ->get(route('admin.hr.countries.index'))
        ->assertOk()
        ->assertDontSee('id="hr_lookup_trash_filter"', false);

    $trashViewer = hrLookupActor(['hr.countries.view', 'hr.countries.view_trashed']);

    $this->actingAs($trashViewer)
        ->get(route('admin.hr.countries.index'))
        ->assertOk()
        ->assertSee('id="hr_lookup_trash_filter"', false)
        ->assertSee(__('hr.trash.active'))
        ->assertSee(__('hr.trash.trashed'))
        ->assertSee(__('hr.trash.all'))
        ->assertSee('restoreConfirmTitle', false);

    $script = file_get_contents(public_path('assets/js/modules/HR/hr-lookups.js'));

    expect($script)
        ->toContain('trash_filter')
        ->toContain('hr_lookup_trash_filter')
        ->toContain('data-hr-restore-url')
        ->toContain("confirmButtonColor: '#00a65a'");
});

test('hr lookup create form uses main save data without Save and New option', function (array $definition) {
    $actor = hrLookupActor([
        "{$definition['permission']}.create",
        "{$definition['permission']}.view",
        "{$definition['permission']}.edit",
        "{$definition['permission']}.clone",
    ]);

    $this->actingAs($actor)
        ->get(route("{$definition['route']}.create"))
        ->assertOk()
        ->assertSee('data-mode="create"', false)
        ->assertSee('name="submit_action" value="save"', false)
        ->assertDontSee('data-submit-action="save_new"', false)
        ->assertSee('<span class="text-danger" aria-hidden="true">*</span>', false)
        ->assertSee(__('common.shortcuts.save'), false)
        ->assertDontSee('data-shortcut-action="form.save_new"', false)
        ->assertDontSee(__('common.fields.deleted_by'))
        ->assertDontSee(__('common.fields.deleted_at'))
        ->assertDontSee(__('common.fields.restored_by'))
        ->assertDontSee(__('common.fields.restored_at'));
})->with('hrLookups');

test('hr lookup crud follows roles style behavior', function (array $definition) {
    $permissions = hrLookupPermissions($definition['permission']);
    $actor = hrLookupActor($permissions);

    $createResponse = $this->actingAs($actor)
        ->postJson(route("{$definition['route']}.store"), [
            'name' => 'Alpha',
            'notes' => 'Initial notes',
            'submit_action' => 'save_edit',
        ])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.doc_number', 1);

    $model = $definition['model'];
    $record = $model::query()->firstOrFail();

    expect($createResponse->json('data.doc_num'))->toBe($definition['prefix'].'00001')
        ->and($record->created_by)->toBe($actor->id)
        ->and($record->updated_by)->toBeNull()
        ->and($record->updated_at)->toBeNull()
        ->and($record->deleted_by)->toBeNull()
        ->and($record->deleted_at)->toBeNull()
        ->and($record->restored_by)->toBeNull()
        ->and($record->restored_at)->toBeNull();

    $lookupDefinition = app(HrLookupRegistry::class)->fromRouteName("{$definition['route']}.update");
    $noChangeResult = app(HrLookupService::class)->update($lookupDefinition, $record, [
        'name' => 'Alpha',
        'notes' => 'Initial notes',
        'doc_number' => 1,
    ]);

    $record->refresh();

    expect($noChangeResult['changed'])->toBeFalse()
        ->and($record->updated_by)->toBeNull()
        ->and($record->updated_at)->toBeNull();

    $this->postJson(route("{$definition['route']}.store"), [
        'name' => 'Manual',
        'doc_number' => 44,
    ])
        ->assertOk()
        ->assertJsonPath('data.doc_num', $definition['prefix'].'00044')
        ->assertJsonPath('submit_action', 'save_new')
        ->assertJsonPath('reset_form', true)
        ->assertJsonPath('next_doc_number', 45);

    $this->putJson(route("{$definition['route']}.document-number-settings.update"), [
        'prefix' => 'HRX-',
        'padding' => 3,
    ])
        ->assertOk()
        ->assertJsonPath('message', __('common.document_number_settings.updated_successfully'));

    expect(Setting::query()->where('key', "document_numbers.{$definition['setting']}.prefix")->value('value'))->toBe('HRX-')
        ->and(Setting::query()->where('key', "document_numbers.{$definition['setting']}.padding")->value('value'))->toBe('3');

    $updateResponse = $this->putJson(route("{$definition['route']}.update", $record->doc_num), [
        'name' => 'Alpha Updated',
        'notes' => 'Updated notes',
        'doc_number' => 99,
        'submit_action' => 'save_view',
    ])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.old_doc_num', $definition['prefix'].'00001')
        ->assertJsonPath('data.doc_num', 'HRX-099');

    $record->refresh();
    $realUpdatedAt = $record->updated_at?->copy();

    expect($updateResponse->json('data.urls.show'))->toBe(route("{$definition['route']}.show", 'HRX-099'))
        ->and($record->updated_by)->toBe($actor->id)
        ->and($realUpdatedAt)->not->toBeNull();

    $dataActor = hrLookupActor($permissions);
    $dataResponse = $this->actingAs($dataActor)
        ->getJson(route("{$definition['route']}.data", [
            'draw' => 1,
            'start' => 0,
            'length' => 10,
        ]))
        ->assertOk()
        ->assertJsonMissingPath('data.0.id');

    expect(collect($dataResponse->json('data'))->pluck('doc_num')->implode(' '))->toContain('HRX-099');

    $clonePage = $this->get(route("{$definition['route']}.clone", $record->doc_num))
        ->assertOk()
        ->assertSee(__('hr.defaults.clone_name', ['name' => 'Alpha Updated']))
        ->assertDontSee(__('common.fields.deleted_by'))
        ->assertDontSee(__('common.fields.deleted_at'))
        ->assertDontSee(__('common.fields.restored_by'))
        ->assertDontSee(__('common.fields.restored_at'));

    preg_match('/name="clone_source_token" value="([^"]+)"/', $clonePage->getContent(), $cloneTokenMatches);

    $cloneResponse = $this->postJson(route("{$definition['route']}.store"), [
        'name' => 'Alpha Clone',
        'notes' => 'Updated notes',
        'clone_source_token' => $cloneTokenMatches[1] ?? '',
    ])
        ->assertOk()
        ->assertJsonPath('success', true);

    $second = $model::query()->where('name', 'Manual')->firstOrFail();
    $clone = $model::query()->where('name', 'Alpha Clone')->firstOrFail();

    $deleteActor = hrLookupActor($permissions);

    $this->actingAs($deleteActor)
        ->deleteJson(route("{$definition['route']}.bulk-delete"), ['doc_nums' => [$second->doc_num, $clone->doc_num]])
        ->assertOk()
        ->assertJsonPath('data.deleted', 2);

    $this->deleteJson(route("{$definition['route']}.destroy", $record->doc_num))
        ->assertOk()
        ->assertJsonPath('success', true);

    $deletedRecord = $model::withTrashed()->whereKey($record->getKey())->firstOrFail();

    expect($model::query()->count())->toBe(0)
        ->and($model::withTrashed()->count())->toBe(3)
        ->and($deletedRecord->updated_at?->toDateTimeString())->toBe($realUpdatedAt?->toDateTimeString())
        ->and((int) $deletedRecord->updated_by)->toBe($actor->id)
        ->and($deletedRecord->restored_by)->toBeNull()
        ->and($deletedRecord->restored_at)->toBeNull()
        ->and($cloneResponse->json('data.doc_num'))->toBe('HRX-100')
        ->and(Activity::query()->where('action', "{$definition['permission']}.create")->exists())->toBeTrue()
        ->and(Activity::query()->where('action', "{$definition['permission']}.clone")->exists())->toBeTrue()
        ->and(Activity::query()->where('action', "{$definition['permission']}.update")->exists())->toBeTrue()
        ->and(Activity::query()->where('action', "{$definition['permission']}.doc_number.changed")->exists())->toBeTrue()
        ->and(Activity::query()->where('action', "{$definition['permission']}.bulk_delete")->exists())->toBeTrue()
        ->and(Activity::query()->where('action', "{$definition['permission']}.delete")->exists())->toBeTrue();
})->with('hrLookups');

test('hr lookup datatable supports active trashed and all filters with permission safety', function () {
    $active = HrCountry::query()->create([
        'name' => 'Active Filtered Country',
        'doc_number' => 601,
        'doc_num' => 'Country-00601',
    ]);
    $trashed = trashedHrCountry([
        'name' => 'Trashed Filtered Country',
        'doc_number' => 602,
        'doc_num' => 'Country-00602',
    ]);

    $plainViewer = hrLookupActor([
        'hr.countries.view',
        'hr.countries.edit',
        'hr.countries.clone',
        'hr.countries.delete',
    ]);
    $plainResponse = $this->actingAs($plainViewer)
        ->getJson(route('admin.hr.countries.data', [
            'draw' => 1,
            'start' => 0,
            'length' => 10,
            'trash_filter' => 'trashed',
            'search' => ['value' => 'Filtered Country'],
        ]))
        ->assertOk()
        ->json();
    $plainDocNums = collect($plainResponse['data'])->pluck('doc_num')->implode(' ');

    expect($plainDocNums)->toContain($active->doc_num)
        ->not->toContain($trashed->doc_num);

    $trashViewer = hrLookupActor([
        'hr.countries.view',
        'hr.countries.view_trashed',
        'hr.countries.restore',
    ]);
    $trashedResponse = $this->actingAs($trashViewer)
        ->getJson(route('admin.hr.countries.data', [
            'draw' => 2,
            'start' => 0,
            'length' => 10,
            'trash_filter' => 'trashed',
            'search' => ['value' => 'Filtered Country'],
        ]))
        ->assertOk()
        ->json();

    expect($trashedResponse['data'][0]['doc_num'])->toContain($trashed->doc_num)
        ->and($trashedResponse['data'][0]['doc_num'])->toContain(route('admin.hr.countries.show', $trashed->doc_num))
        ->and($trashedResponse['data'][0]['checkbox'])->not->toContain('js-hr-lookup-row-checkbox')
        ->and($trashedResponse['data'][0]['actions'])->toContain(route('admin.hr.countries.show', $trashed->doc_num))
        ->and($trashedResponse['data'][0]['actions'])->toContain('data-hr-restore-url')
        ->and($trashedResponse['data'][0]['actions'])->toContain('data-restore-url')
        ->and($trashedResponse['data'][0]['actions'])->toContain('js-restore-record')
        ->and($trashedResponse['data'][0]['actions'])->toContain(route('admin.hr.countries.restore', $trashed->doc_num))
        ->and($trashedResponse['data'][0]['actions'])->not->toContain(route('admin.hr.countries.edit', $trashed->doc_num))
        ->and($trashedResponse['data'][0]['actions'])->not->toContain(route('admin.hr.countries.clone', $trashed->doc_num))
        ->and($trashedResponse['data'][0]['actions'])->not->toContain('data-hr-delete-url');

    $allResponse = $this->actingAs($trashViewer)
        ->getJson(route('admin.hr.countries.data', [
            'draw' => 3,
            'start' => 0,
            'length' => 10,
            'trash_filter' => 'all',
            'search' => ['value' => 'Filtered Country'],
        ]))
        ->assertOk()
        ->json();
    $allDocNums = collect($allResponse['data'])->pluck('doc_num')->implode(' ');

    expect($allDocNums)->toContain($active->doc_num)
        ->and($allDocNums)->toContain($trashed->doc_num);
});

test('hr lookup datatable leaves empty optional notes blank', function () {
    $actor = hrLookupActor(['hr.countries.view']);

    HrCountry::query()->create([
        'name' => 'Empty Notes Country',
        'notes' => null,
        'doc_number' => 710,
        'doc_num' => 'Country-00710',
    ]);

    $row = $this->actingAs($actor)
        ->getJson(route('admin.hr.countries.data', [
            'draw' => 1,
            'start' => 0,
            'length' => 10,
            'search' => ['value' => 'Country-00710'],
        ]))
        ->assertOk()
        ->json('data.0');

    expect(trim($row['notes']))->toBe('')
        ->and($row['notes'])->not->toContain(__('common.messages.not_available'))
        ->and($row['notes'])->not->toContain('-');
});

test('hr lookup view shows placeholders for empty notes and audit fields', function () {
    $actor = hrLookupActor(['hr.countries.view']);
    $country = HrCountry::query()->create([
        'name' => 'Blank View Country',
        'notes' => null,
        'doc_number' => 711,
        'doc_num' => 'Country-00711',
        'updated_by' => null,
        'updated_at' => null,
    ]);

    $this->actingAs($actor)
        ->get(route('admin.hr.countries.show', $country->doc_num))
        ->assertOk()
        ->assertSee('id="hr-lookup-notes"', false)
        ->assertSee('>'.__('common.empty_value').'</textarea>', false)
        ->assertSee('value="'.__('common.empty_value').'"', false)
        ->assertDontSee('value="'.__('common.messages.not_available').'"', false)
        ->assertDontSee('>'.__('common.messages.not_available').'<', false);
});

test('trashed hr lookup record can be restored by public document number', function () {
    $restorer = hrLookupActor(['hr.countries.restore']);
    $updater = User::factory()->create();
    $previousUpdatedAt = now()->subHours(5)->startOfSecond();
    $country = HrCountry::query()->create([
        'name' => 'Restorable Country',
        'doc_number' => 701,
        'doc_num' => 'Country-00701',
        'updated_by' => $updater->id,
        'updated_at' => $previousUpdatedAt,
        'deleted_by' => $restorer->id,
    ]);
    $country->delete();
    $country->getConnection()
        ->table($country->getTable())
        ->where($country->getKeyName(), $country->getKey())
        ->update([
            'updated_by' => $updater->id,
            'updated_at' => $previousUpdatedAt,
            'deleted_by' => $restorer->id,
        ]);

    $this->actingAs($restorer)
        ->patchJson(route('admin.hr.countries.restore', $country->doc_num))
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', __('hr.messages.restored_successfully'));

    $restored = HrCountry::withTrashed()->whereKey($country->getKey())->firstOrFail();

    expect($restored->trashed())->toBeFalse()
        ->and($restored->deleted_by)->toBeNull()
        ->and($restored->deleted_at)->toBeNull()
        ->and($restored->restored_by)->toBe($restorer->id)
        ->and($restored->restored_at)->not->toBeNull()
        ->and((int) $restored->updated_by)->toBe($updater->id)
        ->and($restored->updated_at?->toDateTimeString())->toBe($previousUpdatedAt->toDateTimeString());

    $activity = Activity::query()->where('action', 'hr.countries.restore')->firstOrFail();

    expect($activity->properties->toArray())
        ->not->toHaveKeys(['id', 'record_id'])
        ->and(data_get($activity->properties->toArray(), 'record.doc_num'))->toBe('Country-00701')
        ->and(data_get($activity->properties->toArray(), 'record.label'))->toBe('Restorable Country')
        ->and(data_get($activity->properties->toArray(), 'meta.restored_by_user_doc_num'))->toBe($restorer->doc_num)
        ->and(data_get($activity->properties->toArray(), 'meta.restored_at'))->not->toBeNull();

    $this->actingAs(hrLookupActor(['hr.countries.view']))
        ->get(route('admin.hr.countries.show', $restored->doc_num))
        ->assertOk()
        ->assertSee(__('common.fields.restored_by'))
        ->assertSee(__('common.fields.restored_at'))
        ->assertSee($restorer->name.' / '.$restorer->doc_num)
        ->assertDontSee(__('common.fields.deleted_by'))
        ->assertDontSee(__('common.fields.deleted_at'))
        ->assertDontSee('value="'.$restorer->id.'"', false);

    $this->actingAs($restorer)
        ->patchJson(route('admin.hr.countries.restore', $country->doc_num))
        ->assertUnprocessable()
        ->assertJsonPath('message', __('hr.messages.restore_not_allowed'))
        ->assertJsonPath('errors.restore.0', __('hr.messages.restore_not_allowed'));
});

test('trashed hr lookup restore is blocked when an active record reuses protected data', function (array $trashedAttributes, array $activeAttributes, array $expectedFields, string $expectedType) {
    $restorer = hrLookupActor(['hr.countries.restore']);
    $trashed = trashedHrCountry([
        'name' => 'Conflict Deleted Country',
        'doc_number' => 801,
        'doc_num' => 'Country-00801',
        ...$trashedAttributes,
    ]);

    HrCountry::query()->create([
        'name' => 'Conflict Active Country',
        'doc_number' => 802,
        'doc_num' => 'Country-00802',
        ...$activeAttributes,
    ]);

    $response = $this->actingAs($restorer)
        ->patchJson(route('admin.hr.countries.restore', $trashed->doc_num))
        ->assertUnprocessable()
        ->assertJsonPath('message', __('hr.messages.restore_conflict'))
        ->assertJsonPath('errors.restore.0', __('hr.messages.restore_conflict'))
        ->assertJsonPath('data.conflict_type', $expectedType)
        ->assertJsonPath('data.conflict_fields', $expectedFields);

    expect($response->json())
        ->not->toHaveKeys(['id', 'record_id'])
        ->and($response->json('data'))->not->toHaveKeys(['id', 'record_id']);

    $blockedActivity = Activity::query()->where('action', 'hr.countries.restore_blocked')->firstOrFail();

    expect(HrCountry::withTrashed()->whereKey($trashed->getKey())->firstOrFail()->trashed())->toBeTrue()
        ->and($blockedActivity->status)->toBe('blocked')
        ->and($blockedActivity->properties->get('doc_num'))->toBe($trashed->doc_num)
        ->and($blockedActivity->properties->get('name'))->toBe('Conflict Deleted Country')
        ->and($blockedActivity->properties->get('conflict_type'))->toBe($expectedType)
        ->and($blockedActivity->properties->get('conflict_fields'))->toBe($expectedFields)
        ->and($blockedActivity->properties->has('id'))->toBeFalse()
        ->and($blockedActivity->properties->has('record_id'))->toBeFalse();
})->with([
    'name conflict' => [
        [],
        ['name' => 'Conflict Deleted Country'],
        ['name'],
        'name_conflict',
    ],
    'document number conflict' => [
        [],
        ['doc_number' => 801, 'doc_num' => 'Country-00998'],
        ['doc_number'],
        'document_number_conflict',
    ],
    'document code conflict' => [
        [],
        ['doc_number' => 998, 'doc_num' => 'Country-00801'],
        ['doc_num'],
        'document_code_conflict',
    ],
]);

test('trashed hr lookup record can be viewed with deleted record permission but not edited cloned updated or normal deleted', function () {
    $actor = hrLookupActor([
        'hr.countries.view',
        'hr.countries.edit',
        'hr.countries.clone',
        'hr.countries.delete',
        'hr.countries.restore',
    ]);
    $trashed = trashedHrCountry([
        'name' => 'Blocked Route Country',
        'doc_number' => 901,
        'doc_num' => 'Country-00901',
    ]);
    $deleter = User::factory()->create([
        'name' => 'Country Deleter',
        'doc_num' => 'User-00914',
    ]);
    $trashed->forceFill(['deleted_by' => $deleter->id])->saveQuietly();
    $trashed = HrCountry::withTrashed()->whereKey($trashed->getKey())->firstOrFail();

    $this->actingAs($actor)
        ->get(route('admin.hr.countries.show', $trashed->doc_num))
        ->assertNotFound();

    $trashedViewer = hrLookupActor([
        'hr.countries.view',
        'hr.countries.view_trashed',
        'hr.countries.edit',
        'hr.countries.clone',
        'hr.countries.delete',
        'hr.countries.restore',
    ]);

    $this->actingAs($trashedViewer)
        ->get(route('admin.hr.countries.show', $trashed->doc_num))
        ->assertOk()
        ->assertSee(__('common.fields.deleted_by'))
        ->assertSee(__('common.fields.deleted_at'))
        ->assertDontSee(__('common.fields.restored_by'))
        ->assertDontSee(__('common.fields.restored_at'))
        ->assertSee('Country Deleter / User-00914')
        ->assertSee(app(SettingService::class)->formatDateTime($trashed->deleted_at))
        ->assertDontSee('value="'.$deleter->id.'"', false)
        ->assertDontSee('data-id=', false)
        ->assertSee('data-hr-restore-url', false)
        ->assertSee('data-restore-url', false)
        ->assertSee(__('hr.trash.restore'))
        ->assertDontSee('data-shortcut-action="form.edit"', false)
        ->assertDontSee('data-shortcut-action="form.clone"', false)
        ->assertDontSee('data-hr-delete-url', false)
        ->assertDontSee('data-shortcut-action="form.delete"', false);

    $this->actingAs($trashedViewer)
        ->get(route('admin.hr.countries.edit', $trashed->doc_num))
        ->assertNotFound();

    $this->actingAs($trashedViewer)
        ->putJson(route('admin.hr.countries.update', $trashed->doc_num), [
            'name' => 'Blocked Route Country',
        ])
        ->assertNotFound();

    $this->actingAs($trashedViewer)
        ->get(route('admin.hr.countries.clone', $trashed->doc_num))
        ->assertNotFound();

    $this->actingAs($trashedViewer)
        ->deleteJson(route('admin.hr.countries.destroy', $trashed->doc_num))
        ->assertNotFound();
});
