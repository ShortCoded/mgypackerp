<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Modules\Auth\Models\Role;
use Modules\Core\Services\DocumentNumberService;
use Spatie\Permission\PermissionRegistrar;

class DefaultAdminSeeder extends Seeder
{
    private const AdminName = 'Admin';

    private const AdminUsername = 'admin';

    private const AdminEmail = 'info@shortcoded.com';

    private const AdminPhone = '01200525888';

    private const AdminPassword = 'admin';

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        try {
            DB::transaction(fn () => $this->seedDefaultAdmin());
        } finally {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    private function seedDefaultAdmin(): void
    {
        $admin = User::query()
            ->withTrashed()
            ->where('email', self::AdminEmail)
            ->orWhere('username', self::AdminUsername)
            ->orderByRaw('CASE WHEN email = ? THEN 0 ELSE 1 END', [self::AdminEmail])
            ->first();

        if ($admin instanceof User || User::withTrashed()->exists() || ! app()->environment(['local', 'testing'])) {
            return;
        }

        $role = Role::withTrashed()->where('name', 'admin')->where('guard_name', 'web')->first();
        if ($role?->trashed()) {
            return;
        }
        $role ??= Role::query()->create(['name' => 'admin', 'guard_name' => 'web']);

        $admin = new User;
        $admin->forceFill([
            'name' => self::AdminName,
            'username' => self::AdminUsername,
            'email' => self::AdminEmail,
            'phone' => self::AdminPhone,
            'password' => Hash::make(self::AdminPassword),
            'status' => 'active',
            'locale' => config('languages.default', config('app.locale')),
            'email_verified_at' => $admin->email_verified_at ?: now(),
        ])->save();

        if ($admin->doc_number === null || $admin->doc_num === null) {
            $admin->forceFill(app(DocumentNumberService::class)->next('users', User::class))->save();
        }

        $admin->assignRole($role);
    }
}
