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

        $role = Role::withTrashed()->updateOrCreate(
            [
                'name' => 'admin',
                'guard_name' => 'web',
            ],
            [
                'name' => 'admin',
                'guard_name' => 'web',
            ],
        );

        if ($role->trashed()) {
            $role->restore();
        }

        $role->forceFill([
            'company_access_restricted' => false,
            'branch_access_restricted' => false,
            'financial_period_access_restricted' => false,
        ])->save();

        $admin = User::query()
            ->withTrashed()
            ->where('email', self::AdminEmail)
            ->orWhere('username', self::AdminUsername)
            ->orderByRaw('CASE WHEN email = ? THEN 0 ELSE 1 END', [self::AdminEmail])
            ->first() ?? new User;

        if ($admin->trashed()) {
            $admin->restore();
        }

        $admin->forceFill([
            'name' => self::AdminName,
            'username' => $this->availableUsername(self::AdminUsername, $admin->exists ? $admin->getKey() : null),
            'email' => self::AdminEmail,
            'phone' => self::AdminPhone,
            'password' => Hash::make(self::AdminPassword),
            'status' => 'active',
            'locale' => config('languages.default', config('app.locale')),
            'email_verified_at' => $admin->email_verified_at ?: now(),
        ])->save();

        if ($admin->doc_number === null || $admin->doc_num === null) {
            DB::transaction(function () use ($admin): void {
                $admin->forceFill(app(DocumentNumberService::class)->next('users', User::class))->save();
            });
        }

        $admin->assignRole($role);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function availableUsername(string $preferred, mixed $exceptUserId = null): string
    {
        $candidate = $preferred;
        $suffix = 2;

        while (User::withTrashed()
            ->where('username', $candidate)
            ->when($exceptUserId !== null, fn ($query) => $query->whereKeyNot($exceptUserId))
            ->exists()) {
            $candidate = "{$preferred}_{$suffix}";
            $suffix++;
        }

        return $candidate;
    }
}
