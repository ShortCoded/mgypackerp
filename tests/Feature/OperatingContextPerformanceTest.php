<?php

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Modules\Auth\Models\Role;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\OperatingContextService;

beforeEach(function (): void {
    Route::middleware('web')->get('/__tests/operating-context-performance', function (Request $request): JsonResponse {
        $service = app(OperatingContextService::class);
        $current = $service->current($request);
        $service->snapshot($request);
        $service->snapshot($request);

        return response()->json(['current' => $current]);
    });
});

test('selected operating context has a bounded query budget', function (bool $restricted, int $maximumQueries): void {
    $suffix = $restricted ? 98001 : 98002;
    $company = Company::factory()->create(['name' => $restricted ? 'Restricted Context' : 'Unrestricted Context']);
    $branch = Branch::query()->create([
        'doc_number' => $suffix,
        'doc_num' => 'Branch-'.$suffix,
        'company_id' => $company->getKey(),
        'name' => 'Performance Branch',
        'type' => Branch::TypeFactory,
        'status' => 'active',
    ]);
    $period = FinancialPeriod::query()->create([
        'doc_number' => $suffix,
        'doc_num' => 'Period-'.$suffix,
        'company_id' => $company->getKey(),
        'name' => 'Performance Period',
        'from_date' => CarbonImmutable::create(2026, 1, 1)->toDateString(),
        'to_date' => CarbonImmutable::create(2026, 12, 31)->toDateString(),
        'is_closed' => false,
    ]);
    $user = User::factory()->create();

    if ($restricted) {
        $role = Role::query()->create([
            'name' => 'performance-context-restricted',
            'guard_name' => 'web',
            'doc_number' => $suffix,
            'doc_num' => 'Role-'.$suffix,
            'company_access_restricted' => true,
            'branch_access_restricted' => true,
            'financial_period_access_restricted' => true,
        ]);
        $user->assignRole($role);
        DB::table('role_company_access')->insert(['role_id' => $role->getKey(), 'company_id' => $company->getKey()]);
        DB::table('role_branch_access')->insert(['role_id' => $role->getKey(), 'branch_id' => $branch->getKey()]);
        DB::table('role_financial_period_access')->insert(['role_id' => $role->getKey(), 'financial_period_id' => $period->getKey()]);
    }

    $contextQueries = [];

    DB::listen(function (QueryExecuted $query) use (&$contextQueries): void {
        $sql = strtolower($query->sql);
        $needles = [
            'role_company_access',
            'role_branch_access',
            'role_financial_period_access',
            'model_has_roles',
            'from "companies"',
            'from "branches"',
            'from "financial_periods"',
        ];

        if (collect($needles)->contains(fn (string $needle): bool => str_contains($sql, $needle))) {
            $contextQueries[] = $sql;
        }
    });

    $this->withSession([
        OperatingContextService::CompanyIdKey => $company->getKey(),
        OperatingContextService::CompanyDocNumKey => $company->doc_num,
        OperatingContextService::BranchIdKey => $branch->getKey(),
        OperatingContextService::BranchDocNumKey => $branch->doc_num,
        OperatingContextService::FinancialPeriodIdKey => $period->getKey(),
        OperatingContextService::FinancialPeriodDocNumKey => $period->doc_num,
    ])
        ->actingAs($user)
        ->getJson('/__tests/operating-context-performance')
        ->assertOk()
        ->assertJsonPath('current.requires_selection', false);

    expect($contextQueries)->toHaveCount($maximumQueries);
})->with([
    'unrestricted user' => [false, 4],
    'fully restricted role' => [true, 7],
]);

test('missing context attempts automatic selection only once per request', function (): void {
    DB::statement('DROP INDEX IF EXISTS companies_one_active_main_unique');
    Company::factory()->count(2)->create();
    $user = User::factory()->create();
    $companySelectionQueries = [];

    DB::listen(function (QueryExecuted $query) use (&$companySelectionQueries): void {
        $sql = strtolower($query->sql);

        if (str_contains($sql, 'from "companies"') && str_contains($sql, 'limit 2')) {
            $companySelectionQueries[] = $sql;
        }
    });

    $this->actingAs($user)
        ->getJson('/__tests/operating-context-performance')
        ->assertOk()
        ->assertJsonPath('current.requires_selection', true);

    expect($companySelectionQueries)->toHaveCount(1);
});
