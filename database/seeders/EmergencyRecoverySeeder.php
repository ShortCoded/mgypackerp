<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Modules\Accounting\Database\Seeders\AccountClassificationsSeeder;
use Modules\Accounting\Database\Seeders\DefaultChartOfAccountsSeeder;
use Modules\Auth\Database\Seeders\PermissionSeeder;
use Modules\Auth\Models\Role;
use Modules\Core\Database\Seeders\CurrencySeeder;
use Modules\Core\Database\Seeders\SettingSeeder;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\DocumentNumberService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class EmergencyRecoverySeeder extends Seeder
{
    use WithoutModelEvents;

    private const AdminEmail = 'admin@erp.local';

    private const AdminPassword = 'password';

    private const AdminRole = 'admin';

    private const DefaultCompanyName = 'Short Coded';

    private const DefaultBranchName = 'Main Branch';

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->call(SettingSeeder::class);
        $this->call(PermissionSeeder::class);
        $this->call(DefaultOperatingContextSeeder::class);
        $this->call(AccountClassificationsSeeder::class);
        $this->call(DefaultChartOfAccountsSeeder::class);
        $this->call(CurrencySeeder::class);

        DB::transaction(function (): void {
            $role = $this->ensureAdminRole();
            $user = $this->ensureAdminUser($role);
            $context = $this->defaultOperatingContext();

            $this->ensureRoleOperatingAccess($role, $context['company'], $context['branch'], $context['period']);
            $user->assignRole($role);
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->command?->warn('Emergency recovery admin credentials were ensured. Change this password immediately after login.');
        $this->command?->line('Login: '.self::AdminEmail);
        $this->command?->line('Password: '.self::AdminPassword);
    }

    private function ensureAdminRole(): Role
    {
        $role = Role::withTrashed()
            ->where('name', self::AdminRole)
            ->where('guard_name', 'web')
            ->first();

        if (! $role instanceof Role) {
            $role = new Role([
                'name' => self::AdminRole,
                'guard_name' => 'web',
            ]);
        }

        if ($role->trashed()) {
            $role->restore();
        }

        $role->forceFill([
            'name' => self::AdminRole,
            'guard_name' => 'web',
            'company_access_restricted' => false,
            'branch_access_restricted' => false,
            'financial_period_access_restricted' => false,
        ])->save();

        $this->ensureDocumentNumber($role, 'roles');

        $role->syncPermissions(
            Permission::query()
                ->where('guard_name', 'web')
                ->pluck('name')
                ->all()
        );

        return $role->refresh();
    }

    private function ensureAdminUser(Role $role): User
    {
        $user = User::withTrashed()
            ->where('email', self::AdminEmail)
            ->first();

        if (! $user instanceof User) {
            $user = new User([
                'email' => self::AdminEmail,
            ]);
        }

        if ($user->trashed()) {
            $user->restore();
        }

        $user->forceFill([
            'name' => 'Super Admin',
            'username' => $user->username ?: $this->availableUsername('superadmin', $user->exists ? $user->getKey() : null),
            'email' => self::AdminEmail,
            'password' => Hash::make(self::AdminPassword),
            'status' => 'active',
            'locale' => config('languages.default', config('app.locale')),
            'email_verified_at' => $user->email_verified_at ?: now(),
        ])->save();

        $this->ensureDocumentNumber($user, 'users');
        $user->assignRole($role);

        return $user->refresh();
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

    /**
     * @return array{company: Company, branch: Branch, period: FinancialPeriod}
     */
    private function defaultOperatingContext(): array
    {
        $year = Carbon::now()->year;
        $company = Company::query()
            ->where('name', self::DefaultCompanyName)
            ->where('status', 'active')
            ->firstOrFail();
        $branch = Branch::query()
            ->where('company_id', $company->getKey())
            ->where('name', self::DefaultBranchName)
            ->where('status', 'active')
            ->firstOrFail();
        $period = FinancialPeriod::query()
            ->where('company_id', $company->getKey())
            ->where('name', (string) $year)
            ->where('is_closed', false)
            ->firstOrFail();

        return [
            'company' => $company,
            'branch' => $branch,
            'period' => $period,
        ];
    }

    private function ensureRoleOperatingAccess(Role $role, Company $company, Branch $branch, FinancialPeriod $period): void
    {
        $now = now();

        foreach ([
            'role_company_access' => ['company_id' => $company->getKey()],
            'role_branch_access' => ['branch_id' => $branch->getKey()],
            'role_financial_period_access' => ['financial_period_id' => $period->getKey()],
        ] as $table => $target) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            DB::table($table)->updateOrInsert(
                ['role_id' => $role->getKey(), ...$target],
                ['updated_at' => $now, 'created_at' => $now],
            );
        }
    }

    private function ensureDocumentNumber(Model $record, string $key, ?int $companyId = null): void
    {
        if ($record->getAttribute('doc_number') !== null && $record->getAttribute('doc_num') !== null) {
            return;
        }

        $documentNumber = $companyId === null
            ? app(DocumentNumberService::class)->next($key, $record::class)
            : app(DocumentNumberService::class)->nextForCompany($key, $record::class, $companyId);

        $record->forceFill($documentNumber)->save();
    }
}
