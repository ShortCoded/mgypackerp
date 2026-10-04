<?php

use App\Models\User;
use Database\Seeders\DefaultAdminSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Modules\Auth\Database\Seeders\PermissionSeeder;
use Modules\Auth\Models\Role;

test('default admin seeder creates admin role and user', function () {
    $this->seed(DefaultAdminSeeder::class);

    $admin = User::query()->where('email', 'info@shortcoded.com')->firstOrFail();

    expect(Role::query()->where('name', 'admin')->where('guard_name', 'web')->exists())->toBeTrue();
    expect($admin->name)->toBe('Admin');
    expect($admin->username)->toBe('admin');
    expect($admin->phone)->toBe('01200525888');
    expect($admin->status)->toBe('active');
    expect($admin->email_verified_at)->not->toBeNull();
    expect(Hash::check('admin', $admin->password))->toBeTrue();
    expect($admin->hasRole('admin'))->toBeTrue();
});

test('default admin seeder is idempotent', function () {
    $this->seed(DefaultAdminSeeder::class);
    $this->seed(DefaultAdminSeeder::class);

    expect(User::query()->where('email', 'info@shortcoded.com')->count())->toBe(1);
    expect(Role::query()->where('name', 'admin')->where('guard_name', 'web')->count())->toBe(1);
});

test('default admin seeding rolls back role and identity if numbering fails', function (): void {
    DB::unprepared("CREATE TRIGGER fail_admin_number BEFORE UPDATE OF doc_number ON users BEGIN SELECT RAISE(ABORT, 'synthetic numbering failure'); END");

    try {
        expect(fn () => $this->seed(DefaultAdminSeeder::class))->toThrow(QueryException::class);
        expect(User::withTrashed()->count())->toBe(0)
            ->and(Role::withTrashed()->count())->toBe(0);
    } finally {
        DB::unprepared('DROP TRIGGER IF EXISTS fail_admin_number');
    }

    $this->seed(DefaultAdminSeeder::class);

    expect(User::query()->where('username', 'admin')->count())->toBe(1)
        ->and(Role::query()->where('name', 'admin')->count())->toBe(1);
});

test('repeated admin seeding preserves existing credentials account state and role scope', function (): void {
    $this->seed(DefaultAdminSeeder::class);
    $admin = User::query()->where('email', 'info@shortcoded.com')->firstOrFail();
    $role = Role::query()->where('name', 'admin')->where('guard_name', 'web')->firstOrFail();
    $admin->forceFill(['password' => Hash::make('Synthetic strong password'), 'status' => 'inactive'])->save();
    $role->forceFill(['branch_access_restricted' => true])->save();
    $originalHash = $admin->password;

    $this->seed(DefaultAdminSeeder::class);

    expect($admin->fresh()->password)->toBe($originalHash)
        ->and($admin->fresh()->status)->toBe('inactive')
        ->and($role->fresh()->branch_access_restricted)->toBeTrue();
});

test('baseline seeders do not restore deliberately deleted admin identities', function (): void {
    $this->seed([PermissionSeeder::class, DefaultAdminSeeder::class]);
    $admin = User::query()->where('email', 'info@shortcoded.com')->firstOrFail();
    $role = Role::query()->where('name', 'admin')->where('guard_name', 'web')->firstOrFail();
    $admin->delete();
    $role->delete();

    $this->seed([PermissionSeeder::class, DefaultAdminSeeder::class]);

    expect(User::withTrashed()->findOrFail($admin->getKey())->trashed())->toBeTrue()
        ->and(Role::withTrashed()->findOrFail($role->getKey())->trashed())->toBeTrue()
        ->and(User::query()->where('username', 'admin')->exists())->toBeFalse()
        ->and(Role::query()->where('name', 'admin')->where('guard_name', 'web')->exists())->toBeFalse();
});
