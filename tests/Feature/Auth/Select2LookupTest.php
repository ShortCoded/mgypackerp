<?php

use App\Models\User;
use Modules\Auth\Models\Role;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

function select2Actor(array $permissions): User
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $user = User::factory()->create();
    $user->givePermissionTo($permissions);

    return $user;
}

test('role select2 endpoint requires permission and returns public paginated results', function () {
    config(['select2.pagination.per_page' => 3]);

    $this->actingAs(User::factory()->create())
        ->getJson(route('admin.select2.roles'))
        ->assertForbidden();

    $actor = select2Actor(['roles.view']);

    foreach (range(1, 5) as $number) {
        Role::query()->create([
            'name' => "Lookup Role {$number}",
            'notes' => "Lookup note {$number}",
            'guard_name' => 'web',
            'doc_number' => $number,
            'doc_num' => 'Role-'.str_pad((string) $number, 5, '0', STR_PAD_LEFT),
        ]);
    }

    $response = $this->actingAs($actor)
        ->getJson(route('admin.select2.roles', ['q' => 'Lookup', 'page' => 1]))
        ->assertOk()
        ->assertJsonPath('pagination.more', true)
        ->json();

    expect($response['results'])->toHaveCount(3)
        ->and($response['results'][0])->toHaveKeys(['id', 'text'])
        ->and($response['results'][0]['id'])->toStartWith('Role-')
        ->and($response['results'][0])->not->toHaveKey('internal_id')
        ->and($response['results'][0])->not->toHaveKey('id_db');

    $secondPage = $this->actingAs($actor)
        ->getJson(route('admin.select2.roles', ['term' => 'Lookup', 'page' => 2]))
        ->assertOk()
        ->assertJsonPath('pagination.more', false)
        ->json();

    expect($secondPage['results'])->toHaveCount(2);
});

test('role select2 endpoint searches by public document number and does not return soft deleted roles', function () {
    config(['select2.pagination.per_page' => 25]);

    $actor = select2Actor(['roles.view']);
    $visible = Role::query()->create([
        'name' => 'Visible Role',
        'guard_name' => 'web',
        'doc_number' => 100,
        'doc_num' => 'Role-00100',
    ]);
    $deleted = Role::query()->create([
        'name' => 'Deleted Role',
        'guard_name' => 'web',
        'doc_number' => 101,
        'doc_num' => 'Role-00101',
    ]);

    $deleted->delete();

    $response = $this->actingAs($actor)
        ->getJson(route('admin.select2.roles', ['q' => 'Role-001']))
        ->assertOk()
        ->assertJsonPath('pagination.more', false)
        ->json();

    expect(collect($response['results'])->pluck('id')->all())
        ->toBe([$visible->doc_num]);
});

test('user selected roles endpoint returns assigned public role doc nums only', function () {
    $role = Role::query()->create([
        'name' => 'Selected Role',
        'guard_name' => 'web',
        'doc_number' => 200,
        'doc_num' => 'Role-00200',
    ]);
    $user = User::factory()->create();
    $user->assignRole($role);

    $this->actingAs(select2Actor(['users.edit']))
        ->getJson(route('admin.select2.users.roles.selected', $user->doc_num))
        ->assertForbidden();

    $actor = select2Actor(['users.edit', 'users.roles.manage']);

    $response = $this->actingAs($actor)
        ->getJson(route('admin.select2.users.roles.selected', $user->doc_num))
        ->assertOk()
        ->json();

    expect($response['results'])->toBe([
        [
            'id' => 'Role-00200',
            'text' => 'Selected Role',
        ],
    ]);
});

test('role select2 endpoint is available to user role managers without exposing ids', function () {
    $actor = select2Actor(['users.roles.manage']);
    $role = Role::query()->create([
        'name' => 'Assignable Role',
        'guard_name' => 'web',
        'doc_number' => 201,
        'doc_num' => 'Role-00201',
    ]);

    $response = $this->actingAs($actor)
        ->getJson(route('admin.select2.roles', ['q' => 'Assignable']))
        ->assertOk()
        ->json();

    expect($response['results'])->toContain([
        'id' => $role->doc_num,
        'text' => $role->name,
    ]);
});
