<?php

use App\Models\User;
use Database\Seeders\DefaultAdminSeeder;
use Illuminate\Support\Facades\Hash;
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
