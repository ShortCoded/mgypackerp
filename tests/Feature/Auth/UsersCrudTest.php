<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Modules\Auth\Database\Seeders\PermissionSeeder;
use Modules\Auth\Models\Role;
use Modules\Core\Services\SettingService;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

function userCrudActor(array $permissions): User
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $user = User::factory()->create();
    $user->givePermissionTo($permissions);

    return $user;
}

function trashedUserForRestore(array $attributes = []): User
{
    $user = User::factory()->create([
        'name' => $attributes['name'] ?? 'Deleted User',
        'username' => $attributes['username'] ?? 'deleteduser',
        'email' => $attributes['email'] ?? 'deleted@example.com',
        'phone' => $attributes['phone'] ?? '+201011111111',
        'status' => $attributes['status'] ?? 'active',
        'doc_number' => $attributes['doc_number'] ?? 101,
        'doc_num' => $attributes['doc_num'] ?? 'User-00101',
    ]);

    $user->delete();

    return User::withTrashed()->whereKey($user->getKey())->firstOrFail();
}

test('users routes are protected by public doc numbers and render index', function () {
    $actor = userCrudActor(['users.view', 'users.create']);

    $this->actingAs($actor)
        ->get(route('admin.users.index'))
        ->assertOk()
        ->assertSee(__('users.title'))
        ->assertSee(route('admin.users.data'), false)
        ->assertSee('assets/js/modules/Auth/users.js', false)
        ->assertSee('assets/js/modules/Core/contact-actions.js', false)
        ->assertDontSee('id="users_trash_filter"', false)
        ->assertDontSee('data-id=', false);

    expect(Activity::query()->where('action', 'users.index')->exists())->toBeFalse();
    expect(route('admin.users.create'))->toBe(url('/admin/users/create'));
});

test('users index exposes trash filter only to deleted record viewers', function () {
    $viewer = userCrudActor(['users.view']);

    $this->actingAs($viewer)
        ->get(route('admin.users.index'))
        ->assertOk()
        ->assertDontSee('id="users_trash_filter"', false);

    $trashedViewer = userCrudActor(['users.view', 'users.view_trashed']);

    $this->actingAs($trashedViewer)
        ->get(route('admin.users.index'))
        ->assertOk()
        ->assertSee('id="users_trash_filter"', false)
        ->assertSee(__('common.trash.active'))
        ->assertSee(__('common.trash.trashed'))
        ->assertSee(__('common.trash.all'))
        ->assertSee('restoreConfirmTitle', false);

    $script = file_get_contents(public_path('assets/js/modules/Auth/users.js'));

    expect($script)
        ->toContain('trash_filter')
        ->toContain('users_trash_filter')
        ->toContain('data-user-restore-url')
        ->toContain("confirmButtonColor: '#00a65a'");
});

test('users create form uses main save data without Save and New option', function () {
    $actor = userCrudActor(['users.create', 'users.view', 'users.edit', 'users.clone']);

    $response = $this->actingAs($actor)
        ->get(route('admin.users.create'))
        ->assertOk()
        ->assertSee('data-mode="create"', false)
        ->assertSee('name="submit_action" value="save"', false)
        ->assertDontSee('data-submit-action="save_new"', false)
        ->assertSee('<span class="text-danger" aria-hidden="true">*</span>', false)
        ->assertSee(__('common.shortcuts.save'), false)
        ->assertDontSee('data-shortcut-action="form.save_new"', false)
        ->assertDontSee('id="user-locale"', false)
        ->assertDontSee('data-error-for="locale"', false)
        ->assertDontSee(__('common.fields.deleted_by'))
        ->assertDontSee(__('common.fields.deleted_at'))
        ->assertDontSee(__('common.fields.restored_by'))
        ->assertDontSee(__('common.fields.restored_at'));

    preg_match('/<label[^>]*for="user-email"[^>]*>.*?<\/label>/s', $response->getContent(), $emailLabel);
    preg_match('/<input[^>]*id="user-email"[^>]*>/s', $response->getContent(), $emailInput);

    expect($emailLabel[0] ?? '')
        ->not->toContain('text-danger')
        ->and($emailInput[0] ?? '')
        ->not->toContain('required');
});

test('users edit view and clone forms do not render locale control', function () {
    $actor = userCrudActor(['users.view', 'users.edit', 'users.clone']);
    $user = User::factory()->create([
        'locale' => 'en',
    ]);

    foreach ([
        route('admin.users.edit', $user->doc_num),
        route('admin.users.show', $user->doc_num),
        route('admin.users.clone', $user->doc_num),
    ] as $url) {
        $this->actingAs($actor)
            ->get($url)
            ->assertOk()
            ->assertDontSee('id="user-locale"', false)
            ->assertDontSee('data-error-for="locale"', false)
            ->assertDontSee(__('common.fields.deleted_by'))
            ->assertDontSee(__('common.fields.deleted_at'))
            ->assertDontSee(__('common.fields.restored_by'))
            ->assertDontSee(__('common.fields.restored_at'));
    }
});

test('users datatable returns server side rows without internal ids', function () {
    $actor = userCrudActor(['users.view', 'users.edit', 'users.clone', 'users.delete']);
    $role = Role::query()->create([
        'name' => 'Data Role',
        'guard_name' => 'web',
        'doc_number' => 401,
        'doc_num' => 'Role-00401',
    ]);
    $target = User::factory()->create([
        'name' => 'Data Table User',
        'username' => 'datatableuser',
        'email' => 'datatable@example.com',
        'phone' => '+20 100 123 4567',
    ]);
    $target->assignRole($role);

    $response = $this->actingAs($actor)
        ->getJson(route('admin.users.data', [
            'draw' => 1,
            'start' => 0,
            'length' => 10,
            'search' => ['value' => 'datatable&&User-'],
        ]))
        ->assertOk()
        ->json();

    expect($response['data'][0])
        ->toHaveKeys(['checkbox', 'doc_num', 'name', 'username', 'email', 'phone', 'roles', 'status', 'actions'])
        ->not->toHaveKey('id')
        ->and($response['data'][0]['doc_num'])->toContain($target->doc_num)
        ->and($response['data'][0]['email'])->toContain('href="mailto:datatable@example.com"')
        ->and($response['data'][0]['phone'])->toContain('js-user-phone-contact')
        ->and($response['data'][0]['phone'])->toContain('data-whatsapp-url="https://wa.me/201001234567"')
        ->and($response['data'][0]['phone'])->toContain('data-phone-url="tel:+201001234567"')
        ->and($response['data'][0]['roles'])->toContain('Data Role')
        ->and($response['data'][0]['roles'])->toContain('Role-00401')
        ->and($response['data'][0]['actions'])->toContain(route('admin.users.edit', $target->doc_num))
        ->and($response['data'][0]['actions'])->not->toContain('data-id=');

    $roleSearch = $this->actingAs($actor)
        ->getJson(route('admin.users.data', [
            'draw' => 2,
            'start' => 0,
            'length' => 10,
            'search' => ['value' => 'Data Role'],
        ]))
        ->assertOk()
        ->json();

    expect($roleSearch['data'][0]['doc_num'])->toContain($target->doc_num);
});

test('users datatable leaves null contact fields empty', function () {
    $actor = userCrudActor(['users.view', 'users.view']);
    $target = User::factory()->create([
        'name' => 'Null Email Table User',
        'username' => 'nullemailtableuser',
        'email' => null,
        'phone' => null,
    ]);

    $response = $this->actingAs($actor)
        ->getJson(route('admin.users.data', [
            'draw' => 1,
            'start' => 0,
            'length' => 10,
            'search' => ['value' => 'nullemailtableuser'],
        ]))
        ->assertOk()
        ->json();

    expect($response['data'][0]['doc_num'])->toContain($target->doc_num)
        ->and(trim($response['data'][0]['email']))->toBe('')
        ->and(trim($response['data'][0]['phone']))->toBe('')
        ->and($response['data'][0]['email'])->not->toContain('mailto:')
        ->and($response['data'][0]['phone'])->not->toContain('js-user-phone-contact')
        ->and($response['data'][0]['email'])->not->toContain(__('common.messages.not_available'))
        ->and($response['data'][0]['phone'])->not->toContain(__('common.messages.not_available'));
});

test('users datatable supports active trashed and all filters with permission safety', function () {
    $active = User::factory()->create([
        'name' => 'Active Filtered User',
        'username' => 'activefiltereduser',
        'email' => 'active-filtered@example.com',
        'phone' => '+201022222222',
        'doc_number' => 201,
        'doc_num' => 'User-00201',
    ]);
    $trashed = trashedUserForRestore([
        'name' => 'Trashed Filtered User',
        'username' => 'trashedfiltereduser',
        'email' => 'trashed-filtered@example.com',
        'phone' => '+201033333333',
        'doc_number' => 202,
        'doc_num' => 'User-00202',
    ]);

    $plainViewer = userCrudActor(['users.view', 'users.view', 'users.edit', 'users.clone', 'users.delete']);
    $plainResponse = $this->actingAs($plainViewer)
        ->getJson(route('admin.users.data', [
            'draw' => 1,
            'start' => 0,
            'length' => 10,
            'trash_filter' => 'trashed',
            'search' => ['value' => 'Filtered User'],
        ]))
        ->assertOk()
        ->json();

    $plainDocNums = collect($plainResponse['data'])->pluck('doc_num')->implode(' ');

    expect($plainDocNums)->toContain($active->doc_num)
        ->not->toContain($trashed->doc_num);

    $trashViewer = userCrudActor(['users.view', 'users.view', 'users.view_trashed', 'users.restore']);
    $trashedResponse = $this->actingAs($trashViewer)
        ->getJson(route('admin.users.data', [
            'draw' => 2,
            'start' => 0,
            'length' => 10,
            'trash_filter' => 'trashed',
            'search' => ['value' => 'Filtered User'],
        ]))
        ->assertOk()
        ->json();

    expect($trashedResponse['data'][0]['doc_num'])->toContain($trashed->doc_num)
        ->and($trashedResponse['data'][0]['doc_num'])->toContain(route('admin.users.show', $trashed->doc_num))
        ->and($trashedResponse['data'][0]['checkbox'])->not->toContain('js-user-row-checkbox')
        ->and($trashedResponse['data'][0]['actions'])->toContain(route('admin.users.show', $trashed->doc_num))
        ->and($trashedResponse['data'][0]['actions'])->toContain('data-user-restore-url')
        ->and($trashedResponse['data'][0]['actions'])->toContain('data-restore-url')
        ->and($trashedResponse['data'][0]['actions'])->toContain('js-restore-record')
        ->and($trashedResponse['data'][0]['actions'])->toContain(route('admin.users.restore', $trashed->doc_num))
        ->and($trashedResponse['data'][0]['actions'])->not->toContain(route('admin.users.edit', $trashed->doc_num))
        ->and($trashedResponse['data'][0]['actions'])->not->toContain(route('admin.users.clone', $trashed->doc_num))
        ->and($trashedResponse['data'][0]['actions'])->not->toContain('data-user-delete-url');

    $allResponse = $this->actingAs($trashViewer)
        ->getJson(route('admin.users.data', [
            'draw' => 3,
            'start' => 0,
            'length' => 10,
            'trash_filter' => 'all',
            'search' => ['value' => 'Filtered User'],
        ]))
        ->assertOk()
        ->json();
    $allDocNums = collect($allResponse['data'])->pluck('doc_num')->implode(' ');

    expect($allDocNums)->toContain($active->doc_num)
        ->and($allDocNums)->toContain($trashed->doc_num);
});

test('users can be created with auto document number and no fake update tracking', function () {
    $actor = userCrudActor(['users.create', 'users.edit', 'users.view', 'users.document_number.control']);

    $this->actingAs($actor)
        ->postJson(route('admin.users.store'), [
            'name' => 'Created User',
            'username' => 'createduser',
            'email' => 'created@example.com',
            'phone' => '01000000001',
            'status' => 'active',
            'locale' => 'en',
            'password' => 'password',
            'password_confirmation' => 'password',
            'doc_number' => '',
            'submit_action' => 'save_edit',
        ])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonMissingValidationErrors(['locale'])
        ->assertJsonStructure(['data' => ['doc_num', 'doc_number', 'urls']]);

    $user = User::query()->where('username', 'createduser')->firstOrFail();

    expect($user->doc_num)->toStartWith('User-')
        ->and($user->email)->toBe('created@example.com')
        ->and($user->locale)->toBeNull()
        ->and($user->created_by)->toBe($actor->id)
        ->and($user->updated_by)->toBeNull()
        ->and($user->updated_at)->toBeNull()
        ->and($user->deleted_by)->toBeNull()
        ->and($user->deleted_at)->toBeNull()
        ->and($user->restored_by)->toBeNull()
        ->and($user->restored_at)->toBeNull()
        ->and(Hash::check('password', $user->password))->toBeTrue();

    $activity = Activity::query()->where('action', 'users.create')->firstOrFail();

    expect($activity->properties->toArray())
        ->not->toHaveKeys(['password', 'password_confirmation', 'remember_token'])
        ->and(data_get($activity->properties->toArray(), 'record.doc_num'))->toBe($user->doc_num)
        ->and(data_get($activity->properties->toArray(), 'meta.submit_action'))->toBe('save_edit');
});

test('users can be created without email and validation does not require it', function () {
    $actor = userCrudActor(['users.create', 'users.view']);

    $this->actingAs($actor)
        ->postJson(route('admin.users.store'), [
            'name' => 'No Email Created User',
            'username' => 'noemailcreateduser',
            'phone' => '01000000011',
            'status' => 'active',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonMissingValidationErrors(['email', 'locale']);

    $user = User::query()->where('username', 'noemailcreateduser')->firstOrFail();

    expect($user->email)->toBeNull();
});

test('users reject duplicate active email when email is provided', function () {
    $actor = userCrudActor(['users.create']);

    User::factory()->create([
        'email' => 'duplicate-user@example.com',
    ]);

    $this->actingAs($actor)
        ->postJson(route('admin.users.store'), [
            'name' => 'Duplicate Email User',
            'username' => 'duplicateemailuser',
            'email' => 'duplicate-user@example.com',
            'phone' => '01000000012',
            'status' => 'active',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email'])
        ->assertJsonPath('errors.email.0', __('users.validation.email_unique'));
});

test('users can clear email and multiple users may have null emails', function () {
    $actor = userCrudActor(['users.create', 'users.edit']);
    $user = User::factory()->create([
        'name' => 'Clear Email User',
        'username' => 'clearemailuser',
        'email' => 'clear-email@example.com',
        'phone' => '01000000013',
        'status' => 'active',
        'locale' => 'en',
        'notes' => 'Original note',
    ]);

    $this->actingAs($actor)
        ->putJson(route('admin.users.update', $user->doc_num), [
            'name' => $user->name,
            'username' => $user->username,
            'phone' => $user->phone,
            'status' => $user->status,
            'locale' => 'ar',
            'notes' => 'Email omitted update',
        ])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonMissingValidationErrors(['locale']);

    expect($user->refresh()->email)->toBe('clear-email@example.com')
        ->and($user->locale)->toBe('en');

    $this->actingAs($actor)
        ->putJson(route('admin.users.update', $user->doc_num), [
            'name' => $user->name,
            'username' => $user->username,
            'email' => '',
            'phone' => $user->phone,
            'status' => $user->status,
            'notes' => $user->notes,
        ])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonMissingValidationErrors(['email']);

    $this->actingAs($actor)
        ->postJson(route('admin.users.store'), [
            'name' => 'First Null Email User',
            'username' => 'firstnullemailuser',
            'email' => '',
            'phone' => '01000000014',
            'status' => 'active',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])
        ->assertOk();

    $this->actingAs($actor)
        ->postJson(route('admin.users.store'), [
            'name' => 'Second Null Email User',
            'username' => 'secondnullemailuser',
            'phone' => '01000000015',
            'status' => 'active',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])
        ->assertOk();

    expect(User::query()
        ->whereIn('username', ['clearemailuser', 'firstnullemailuser', 'secondnullemailuser'])
        ->whereNull('email')
        ->count())->toBe(3);
});

test('user without email can login with username and phone', function (string $field) {
    $user = User::factory()->create([
        'email' => null,
        'username' => 'loginwithoutemail'.$field,
        'phone' => $field === 'phone' ? '+201088888888' : '+201099999999',
    ]);

    $this->postJson('/login', [
        'login' => $user->{$field},
        'password' => 'password',
    ])
        ->assertOk()
        ->assertJsonPath('success', true);

    $this->assertAuthenticatedAs($user);
})->with(['username', 'phone']);

test('users form renders ajax role select only for role managers', function () {
    $user = User::factory()->create();
    $manager = userCrudActor(['users.edit', 'users.roles.manage']);

    $response = $this->actingAs($manager)
        ->get(route('admin.users.edit', $user->doc_num))
        ->assertOk()
        ->assertSee('name="roles[]"', false)
        ->assertSee('class="form-select js-select2-ajax"', false)
        ->assertSee(route('admin.select2.roles'), false)
        ->assertSee(route('admin.select2.users.roles.selected', $user->doc_num), false)
        ->assertDontSee('js-select2-clear-all', false);

    preg_match('/<select[^>]+id="user-roles"[^>]*>(.*?)<\/select>/s', $response->getContent(), $matches);
    expect($matches[1] ?? null)->toBe('');

    $viewer = userCrudActor(['users.edit']);

    $this->actingAs($viewer)
        ->get(route('admin.users.edit', $user->doc_num))
        ->assertOk()
        ->assertDontSee('name="roles[]"', false);
});

test('users create and update sync roles by public role doc nums', function () {
    $manager = userCrudActor(['users.create', 'users.edit', 'users.roles.manage']);
    $firstRole = Role::query()->create([
        'name' => 'Assignable First',
        'guard_name' => 'web',
        'doc_number' => 301,
        'doc_num' => 'Role-00301',
    ]);
    $secondRole = Role::query()->create([
        'name' => 'Assignable Second',
        'guard_name' => 'web',
        'doc_number' => 302,
        'doc_num' => 'Role-00302',
    ]);

    $this->actingAs($manager)
        ->postJson(route('admin.users.store'), [
            'name' => 'Role Managed User',
            'username' => 'rolemanaged',
            'email' => 'rolemanaged@example.com',
            'status' => 'active',
            'password' => 'password',
            'password_confirmation' => 'password',
            '_roles_present' => '1',
            'roles' => [$firstRole->doc_num],
        ])
        ->assertOk()
        ->assertJsonPath('success', true);

    $user = User::query()->where('username', 'rolemanaged')->firstOrFail();

    expect($user->hasRole($firstRole))->toBeTrue();

    $this->actingAs($manager)
        ->putJson(route('admin.users.update', $user->doc_num), [
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'phone' => $user->phone,
            'status' => $user->status,
            'notes' => $user->notes,
            '_roles_present' => '1',
            'roles' => [$secondRole->doc_num],
        ])
        ->assertOk()
        ->assertJsonPath('success', true);

    $user->refresh();

    expect($user->hasRole($firstRole))->toBeFalse()
        ->and($user->hasRole($secondRole))->toBeTrue()
        ->and($user->updated_by)->toBe($manager->id);

    $this->actingAs($manager)
        ->putJson(route('admin.users.update', $user->doc_num), [
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'phone' => $user->phone,
            'status' => $user->status,
            'notes' => $user->notes,
            '_roles_present' => '1',
        ])
        ->assertOk()
        ->assertJsonPath('success', true);

    expect($user->refresh()->roles)->toHaveCount(0);
});

test('submitted user roles are ignored without role management permission', function () {
    $actor = userCrudActor(['users.create']);
    $role = Role::query()->create([
        'name' => 'Ignored Assignment',
        'guard_name' => 'web',
        'doc_number' => 303,
        'doc_num' => 'Role-00303',
    ]);

    $this->actingAs($actor)
        ->postJson(route('admin.users.store'), [
            'name' => 'No Role Permission',
            'username' => 'norolepermission',
            'email' => 'norolepermission@example.com',
            'status' => 'active',
            'password' => 'password',
            'password_confirmation' => 'password',
            '_roles_present' => '1',
            'roles' => [$role->doc_num],
        ])
        ->assertOk();

    $user = User::query()->where('username', 'norolepermission')->firstOrFail();

    expect($user->hasRole($role))->toBeFalse();
});

test('users update supports manual document number and no change response', function () {
    $actor = userCrudActor(['users.edit', 'users.view', 'users.document_number.control']);
    $previousUpdatedAt = now()->subHours(4)->startOfSecond();
    $user = User::factory()->create([
        'name' => 'Editable User',
        'username' => 'editableuser',
        'email' => 'editable@example.com',
        'phone' => '01000000002',
        'status' => 'active',
        'locale' => 'en',
        'notes' => 'Old note',
        'updated_by' => $actor->id,
        'updated_at' => $previousUpdatedAt,
    ]);

    $payload = [
        'name' => $user->name,
        'username' => $user->username,
        'email' => $user->email,
        'phone' => $user->phone,
        'status' => $user->status,
        'notes' => $user->notes,
        'doc_number' => $user->doc_number,
    ];

    $this->actingAs($actor)
        ->putJson(route('admin.users.update', $user->doc_num), $payload)
        ->assertOk()
        ->assertJsonPath('type', 'no_changes');

    $user->refresh();

    expect($user->updated_by)->toBe($actor->id)
        ->and($user->updated_at?->toDateTimeString())->toBe($previousUpdatedAt->toDateTimeString());

    $this->actingAs($actor)
        ->putJson(route('admin.users.update', $user->doc_num), [
            ...$payload,
            'doc_number' => '0001',
            'notes' => 'New note',
        ])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.doc_number', 1)
        ->assertJsonPath('data.doc_num', 'User-00001');

    $user->refresh();

    expect($user->doc_number)->toBe(1)
        ->and($user->doc_num)->toBe('User-00001')
        ->and($user->updated_by)->toBe($actor->id);

    expect(Activity::query()->where('action', 'users.doc_number.changed')->exists())->toBeTrue();
});

test('users clone does not copy password or document number', function () {
    $actor = userCrudActor(['users.clone', 'users.create', 'users.edit']);
    $source = User::factory()->create([
        'name' => 'Source User',
        'username' => 'sourceuser',
        'email' => 'source@example.com',
        'phone' => '01000000003',
        'notes' => 'Source notes',
    ]);

    $clonePage = $this->actingAs($actor)
        ->get(route('admin.users.clone', $source->doc_num))
        ->assertOk()
        ->assertSee(__('users.defaults.clone_name', ['name' => $source->name]))
        ->assertDontSee('value="'.$source->doc_number.'"', false)
        ->assertDontSee('value="'.$source->email.'"', false);

    preg_match('/name="clone_source_token" value="([^"]+)"/', $clonePage->getContent(), $matches);

    $this->actingAs($actor)
        ->withSession(['users.clone_sources.'.$matches[1] => $source->doc_num])
        ->postJson(route('admin.users.store'), [
            'clone_source_token' => $matches[1],
            'name' => 'Copy of Source User',
            'username' => 'sourcecopy',
            'email' => 'sourcecopy@example.com',
            'phone' => '01000000004',
            'status' => 'active',
            'password' => 'password',
            'password_confirmation' => 'password',
            'notes' => 'Source notes',
        ])
        ->assertOk()
        ->assertJsonPath('message', __('users.messages.cloned'))
        ->assertJsonPath('submit_action', 'save_new')
        ->assertJsonPath('reset_form', true);

    $clone = User::query()->where('username', 'sourcecopy')->firstOrFail();

    expect($clone->doc_num)->not->toBe($source->doc_num)
        ->and(Hash::check('password', $clone->password))->toBeTrue()
        ->and(Activity::query()->where('action', 'users.clone')->exists())->toBeTrue();
});

test('users view renders email mailto and phone contact popover with normalized links', function () {
    $actor = userCrudActor(['users.view', 'users.delete']);
    $user = User::factory()->create([
        'email' => 'contact-user@example.com',
        'phone' => '+20 100 123 4567',
    ]);

    $this->actingAs($actor)
        ->get(route('admin.users.show', $user->doc_num))
        ->assertOk()
        ->assertSee('href="mailto:contact-user@example.com"', false)
        ->assertSee('class="btn btn-link p-0 align-baseline js-user-phone-contact"', false)
        ->assertSee('data-whatsapp-url="https://wa.me/201001234567"', false)
        ->assertSee('data-phone-url="tel:+201001234567"', false)
        ->assertSee('fab fa-whatsapp', false)
        ->assertSee('fas fa-phone', false)
        ->assertSee('data-shortcut-action="form.delete"', false)
        ->assertSee('data-user-delete-url="'.route('admin.users.destroy', $user->doc_num).'"', false)
        ->assertSee('data-delete-url="'.route('admin.users.destroy', $user->doc_num).'"', false)
        ->assertSee('js-delete-record', false);
});

test('users view shows placeholders for null email phone and notes', function () {
    $actor = userCrudActor(['users.view']);
    $user = User::factory()->create([
        'email' => null,
        'phone' => null,
        'notes' => null,
    ]);

    $this->actingAs($actor)
        ->get(route('admin.users.show', $user->doc_num))
        ->assertOk()
        ->assertSee('id="user-email"', false)
        ->assertSee('id="user-phone"', false)
        ->assertSee('id="user-notes"', false)
        ->assertSee('value="'.__('common.empty_value').'"', false)
        ->assertSee('>'.__('common.empty_value').'</textarea>', false)
        ->assertDontSee('mailto:', false)
        ->assertDontSee('js-user-phone-contact', false)
        ->assertDontSee('value="'.__('common.messages.not_available').'"', false)
        ->assertDontSee('>'.__('common.messages.not_available').'<', false);
});

test('users delete blocks current user and last admin user', function () {
    $this->seed(PermissionSeeder::class);

    $admin = User::factory()->create(['email' => 'admin-delete@example.com']);
    $admin->assignRole(Role::query()->where('name', 'admin')->firstOrFail());
    $admin->givePermissionTo('users.delete');

    $this->actingAs($admin)
        ->deleteJson(route('admin.users.destroy', $admin->doc_num))
        ->assertStatus(409)
        ->assertJsonPath('message', __('users.messages.cannot_delete_self'));

    $actor = userCrudActor(['users.delete']);

    $admin->refresh();

    $this->actingAs($actor)
        ->deleteJson(route('admin.users.destroy', $admin->doc_num))
        ->assertStatus(409)
        ->assertJsonPath('message', __('users.messages.cannot_delete_last_admin'));

    expect(Activity::query()->where('action', 'users.delete_blocked')->where('status', 'blocked')->exists())->toBeTrue();
});

test('user soft delete preserves real update audit fields', function () {
    $actor = userCrudActor(['users.delete']);
    $previousUpdatedAt = now()->subHours(3)->startOfSecond();
    $target = User::factory()->create([
        'name' => 'Deletable User',
        'username' => 'deletableuser',
        'email' => 'deletable-user@example.com',
        'updated_by' => $actor->id,
        'updated_at' => $previousUpdatedAt,
    ]);

    $this->actingAs($actor)
        ->deleteJson(route('admin.users.destroy', $target->doc_num))
        ->assertOk()
        ->assertJsonPath('success', true);

    $deleted = User::withTrashed()->whereKey($target->getKey())->firstOrFail();

    expect($deleted->trashed())->toBeTrue()
        ->and($deleted->deleted_by)->toBe($actor->id)
        ->and($deleted->deleted_at)->not->toBeNull()
        ->and($deleted->updated_by)->toBe($actor->id)
        ->and($deleted->updated_at?->toDateTimeString())->toBe($previousUpdatedAt->toDateTimeString())
        ->and($deleted->restored_by)->toBeNull()
        ->and($deleted->restored_at)->toBeNull();
});

test('trashed user can be restored by public document number and keeps status and roles', function () {
    $restorer = userCrudActor(['users.restore']);
    $updater = User::factory()->create();
    $previousUpdatedAt = now()->subHours(5)->startOfSecond();
    $role = Role::query()->create([
        'name' => 'Restored User Role',
        'guard_name' => 'web',
        'doc_number' => 901,
        'doc_num' => 'Role-00901',
    ]);
    $user = User::factory()->create([
        'name' => 'Restorable User',
        'username' => 'restorableuser',
        'email' => 'restorable@example.com',
        'phone' => '+201044444444',
        'status' => 'inactive',
        'doc_number' => 301,
        'doc_num' => 'User-00301',
        'updated_by' => $updater->id,
        'updated_at' => $previousUpdatedAt,
    ]);
    $user->assignRole($role);
    $user->forceFill(['deleted_by' => $restorer->id])->saveQuietly();
    $user->delete();
    $user->getConnection()
        ->table($user->getTable())
        ->where($user->getKeyName(), $user->getKey())
        ->update([
            'updated_by' => $updater->id,
            'updated_at' => $previousUpdatedAt,
            'deleted_by' => $restorer->id,
        ]);

    $this->actingAs($restorer)
        ->patchJson(route('admin.users.restore', $user->doc_num))
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', __('users.messages.restored_successfully'));

    $restored = User::withTrashed()->whereKey($user->getKey())->firstOrFail();

    expect($restored->trashed())->toBeFalse()
        ->and($restored->deleted_by)->toBeNull()
        ->and($restored->deleted_at)->toBeNull()
        ->and($restored->restored_by)->toBe($restorer->id)
        ->and($restored->restored_at)->not->toBeNull()
        ->and($restored->status)->toBe('inactive')
        ->and((int) $restored->updated_by)->toBe($updater->id)
        ->and($restored->updated_at?->toDateTimeString())->toBe($previousUpdatedAt->toDateTimeString())
        ->and($restored->hasRole($role))->toBeTrue();

    $activity = Activity::query()->where('action', 'users.restore')->firstOrFail();

    expect($activity->properties->toArray())
        ->not->toHaveKeys(['id', 'user_id', 'password', 'remember_token'])
        ->and(data_get($activity->properties->toArray(), 'record.doc_num'))->toBe('User-00301')
        ->and(data_get($activity->properties->toArray(), 'record.label'))->toBe('Restorable User')
        ->and(data_get($activity->properties->toArray(), 'meta.restored_by_user_doc_num'))->toBe($restorer->doc_num)
        ->and(data_get($activity->properties->toArray(), 'meta.restored_at'))->not->toBeNull();

    $this->actingAs(userCrudActor(['users.view']))
        ->get(route('admin.users.show', $restored->doc_num))
        ->assertOk()
        ->assertSee(__('common.fields.restored_by'))
        ->assertSee(__('common.fields.restored_at'))
        ->assertSee($restorer->name.' / '.$restorer->doc_num)
        ->assertDontSee(__('common.fields.deleted_by'))
        ->assertDontSee(__('common.fields.deleted_at'))
        ->assertDontSee('value="'.$restorer->id.'"', false);

    $this->actingAs($restorer)
        ->patchJson(route('admin.users.restore', $user->doc_num))
        ->assertUnprocessable()
        ->assertJsonPath('message', __('users.messages.restore_not_allowed'))
        ->assertJsonPath('errors.restore.0', __('users.messages.restore_not_allowed'));
});

test('trashed user restore is blocked when an active user reuses protected data', function (array $trashedAttributes, array $activeAttributes, array $expectedFields, string $expectedType) {
    $restorer = userCrudActor(['users.restore']);
    $trashed = trashedUserForRestore([
        'name' => 'Conflict Deleted User',
        'username' => 'conflictdeleted',
        'email' => 'conflict-deleted@example.com',
        'phone' => '+201055555555',
        'doc_number' => 401,
        'doc_num' => 'User-00401',
        ...$trashedAttributes,
    ]);

    User::factory()->create([
        'name' => 'Conflict Active User',
        'username' => 'conflictactive',
        'email' => 'conflict-active@example.com',
        'phone' => '+201066666666',
        'doc_number' => 402,
        'doc_num' => 'User-00402',
        ...$activeAttributes,
    ]);

    $response = $this->actingAs($restorer)
        ->patchJson(route('admin.users.restore', $trashed->doc_num))
        ->assertStatus(409)
        ->assertJsonPath('message', __('users.messages.restore_conflict'))
        ->assertJsonPath('errors.restore.0', __('users.messages.restore_conflict'))
        ->assertJsonPath('data.conflict_type', $expectedType)
        ->assertJsonPath('data.conflict_fields', $expectedFields);

    expect($response->json())
        ->not->toHaveKeys(['id', 'user_id'])
        ->and($response->json('data'))->not->toHaveKeys(['id', 'user_id']);

    $blockedActivity = Activity::query()->where('action', 'users.restore_blocked')->firstOrFail();

    expect(User::withTrashed()->whereKey($trashed->getKey())->firstOrFail()->trashed())->toBeTrue()
        ->and($blockedActivity->status)->toBe('blocked')
        ->and($blockedActivity->properties->get('doc_num'))->toBe($trashed->doc_num)
        ->and($blockedActivity->properties->get('name'))->toBe('Conflict Deleted User')
        ->and($blockedActivity->properties->get('conflict_type'))->toBe($expectedType)
        ->and($blockedActivity->properties->get('conflict_fields'))->toBe($expectedFields)
        ->and($blockedActivity->properties->has('id'))->toBeFalse()
        ->and($blockedActivity->properties->has('user_id'))->toBeFalse();
})->with([
    'username conflict' => [
        [],
        ['username' => 'conflictdeleted'],
        ['username'],
        'username_conflict',
    ],
    'email conflict' => [
        [],
        ['email' => 'conflict-deleted@example.com'],
        ['email'],
        'email_conflict',
    ],
    'phone conflict' => [
        [],
        ['phone' => '+201055555555'],
        ['phone'],
        'phone_conflict',
    ],
    'document number conflict' => [
        [],
        ['doc_number' => 401, 'doc_num' => 'User-00998'],
        ['doc_number'],
        'document_number_conflict',
    ],
    'document code conflict' => [
        [],
        ['doc_number' => 998, 'doc_num' => 'User-00401'],
        ['doc_num'],
        'document_code_conflict',
    ],
]);

test('trashed user can be viewed with deleted record permission but not edited cloned updated or normal deleted', function () {
    $actor = userCrudActor(['users.view', 'users.edit', 'users.clone', 'users.delete', 'users.restore']);
    $trashed = trashedUserForRestore([
        'username' => 'blockedrouteuser',
        'email' => 'blocked-route@example.com',
        'phone' => '+201077777777',
        'doc_number' => 501,
        'doc_num' => 'User-00501',
    ]);
    $deleter = User::factory()->create([
        'name' => 'User Deleter',
        'doc_num' => 'User-00912',
    ]);
    $trashed->forceFill(['deleted_by' => $deleter->id])->saveQuietly();
    $trashed = User::withTrashed()->whereKey($trashed->getKey())->firstOrFail();

    $this->actingAs($actor)
        ->get(route('admin.users.show', $trashed->doc_num))
        ->assertNotFound();

    $trashedViewer = userCrudActor(['users.view', 'users.view_trashed', 'users.edit', 'users.clone', 'users.delete', 'users.restore']);

    $this->actingAs($trashedViewer)
        ->get(route('admin.users.show', $trashed->doc_num))
        ->assertOk()
        ->assertSee(__('common.fields.deleted_by'))
        ->assertSee(__('common.fields.deleted_at'))
        ->assertDontSee(__('common.fields.restored_by'))
        ->assertDontSee(__('common.fields.restored_at'))
        ->assertSee('User Deleter / User-00912')
        ->assertSee(app(SettingService::class)->formatDateTime($trashed->deleted_at))
        ->assertDontSee('value="'.$deleter->id.'"', false)
        ->assertDontSee('data-id=', false)
        ->assertSee('data-user-restore-url', false)
        ->assertSee('data-restore-url', false)
        ->assertSee(__('users.trash.restore'))
        ->assertDontSee('data-shortcut-action="form.edit"', false)
        ->assertDontSee('data-shortcut-action="form.clone"', false)
        ->assertDontSee('data-user-delete-url', false)
        ->assertDontSee('data-shortcut-action="form.delete"', false);

    $this->actingAs($trashedViewer)
        ->get(route('admin.users.edit', $trashed->doc_num))
        ->assertNotFound();

    $this->actingAs($trashedViewer)
        ->putJson(route('admin.users.update', $trashed->doc_num), [
            'name' => 'Blocked Route User',
            'username' => 'blockedrouteuser',
            'email' => 'blocked-route@example.com',
            'phone' => '+201077777777',
            'status' => 'active',
        ])
        ->assertNotFound();

    $this->actingAs($trashedViewer)
        ->get(route('admin.users.clone', $trashed->doc_num))
        ->assertNotFound();

    $this->actingAs($trashedViewer)
        ->deleteJson(route('admin.users.destroy', $trashed->doc_num))
        ->assertNotFound();
});
