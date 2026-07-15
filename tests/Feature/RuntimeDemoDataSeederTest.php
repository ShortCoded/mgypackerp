<?php

use App\Models\User;
use Database\Seeders\RuntimeDemoDataSeeder;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Models\Role;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\Product;

function runtimeDemoCount(string $table): int
{
    return DB::table($table)
        ->where('notes', 'like', 'RuntimeDemoDataSeeder:%')
        ->count();
}

function runtimeDemoPrepareSqliteIndexes(): void
{
    if (DB::getDriverName() === 'sqlite') {
        DB::statement('DROP INDEX IF EXISTS companies_one_active_main_unique');
    }
}

function runtimeDemoSetEnv(string $key, ?string $value): void
{
    if ($value === null) {
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);

        return;
    }

    putenv("{$key}={$value}");
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}

/**
 * @return array<string, int>
 */
function runtimeDemoCounts(): array
{
    return [
        'companies' => runtimeDemoCount('companies'),
        'branches' => runtimeDemoCount('branches'),
        'financial_periods' => runtimeDemoCount('financial_periods'),
        'item_units' => runtimeDemoCount('item_units'),
        'item_sizes' => runtimeDemoCount('item_sizes'),
        'item_colors' => runtimeDemoCount('item_colors'),
        'item_models' => runtimeDemoCount('item_models'),
        'item_categories' => runtimeDemoCount('item_categories'),
        'item_groups' => runtimeDemoCount('item_groups'),
        'products' => runtimeDemoCount('products'),
        'roles' => runtimeDemoCount('roles'),
        'users' => runtimeDemoCount('users'),
    ];
}

test('runtime demo data seeder is opt in only', function () {
    expect(file_get_contents(database_path('seeders/DatabaseSeeder.php')))
        ->not->toContain('RuntimeDemoDataSeeder');
});

test('runtime demo data seeder skips production unless explicitly allowed', function () {
    runtimeDemoPrepareSqliteIndexes();

    $originalEnvironment = app()->environment();
    $originalAllowValue = env('ALLOW_RUNTIME_DEMO_DATA');

    try {
        app()->detectEnvironment(fn (): string => 'production');
        runtimeDemoSetEnv('ALLOW_RUNTIME_DEMO_DATA', 'false');

        $this->artisan('db:seed', [
            '--class' => RuntimeDemoDataSeeder::class,
            '--force' => true,
        ])->assertExitCode(0);

        expect(runtimeDemoCount('companies'))->toBe(0);

        runtimeDemoSetEnv('ALLOW_RUNTIME_DEMO_DATA', 'true');

        $this->artisan('db:seed', [
            '--class' => RuntimeDemoDataSeeder::class,
            '--force' => true,
        ])->assertExitCode(0);

        expect(runtimeDemoCount('companies'))->toBeGreaterThanOrEqual(2);
    } finally {
        app()->detectEnvironment(fn (): string => $originalEnvironment);
        runtimeDemoSetEnv(
            'ALLOW_RUNTIME_DEMO_DATA',
            $originalAllowValue === null ? null : (string) $originalAllowValue,
        );
    }
});

test('runtime demo data seeder creates useful scoped runtime master data idempotently', function () {
    runtimeDemoPrepareSqliteIndexes();

    $this->seed(RuntimeDemoDataSeeder::class);

    $firstCounts = runtimeDemoCounts();

    $this->seed(RuntimeDemoDataSeeder::class);

    expect(runtimeDemoCounts())->toBe($firstCounts);

    $companies = Company::query()
        ->where('notes', 'like', 'RuntimeDemoDataSeeder:%')
        ->orderBy('name')
        ->get();

    expect($companies->count())->toBeGreaterThanOrEqual(2)
        ->and($firstCounts['branches'])->toBeGreaterThanOrEqual($companies->count())
        ->and($firstCounts['financial_periods'])->toBeGreaterThanOrEqual($companies->count())
        ->and($firstCounts['products'])->toBeGreaterThanOrEqual($companies->count())
        ->and($firstCounts['roles'])->toBeGreaterThanOrEqual(3)
        ->and($firstCounts['users'])->toBeGreaterThanOrEqual(3);

    $validBranchTypes = Branch::types();

    $companies->each(function (Company $company) use ($validBranchTypes): void {
        $branches = DB::table('branches')
            ->where('company_id', $company->getKey())
            ->where('notes', 'like', 'RuntimeDemoDataSeeder:%')
            ->get();

        $periods = DB::table('financial_periods')
            ->where('company_id', $company->getKey())
            ->where('notes', 'like', 'RuntimeDemoDataSeeder:%')
            ->get();

        expect($branches->count())->toBeGreaterThanOrEqual(1)
            ->and($periods->count())->toBeGreaterThanOrEqual(1)
            ->and($branches->pluck('type')->diff($validBranchTypes)->values()->all())->toBe([]);

        foreach (['item_units', 'item_sizes', 'item_colors', 'item_models', 'item_categories', 'item_groups'] as $table) {
            expect(DB::table($table)
                ->where('company_id', $company->getKey())
                ->where('notes', 'like', 'RuntimeDemoDataSeeder:%')
                ->count())->toBeGreaterThan(0);
        }

        expect(Product::query()
            ->where('company_id', $company->getKey())
            ->where('notes', 'like', 'RuntimeDemoDataSeeder:%')
            ->count())->toBeGreaterThan(0);
    });

    $fullRole = Role::query()
        ->where('name', 'runtime-demo-full-admin')
        ->with(['companyAccessCompanies', 'branchAccessBranches', 'financialPeriodAccessPeriods'])
        ->firstOrFail();

    expect($fullRole->company_access_restricted)->toBeFalse()
        ->and($fullRole->branch_access_restricted)->toBeFalse()
        ->and($fullRole->financial_period_access_restricted)->toBeFalse()
        ->and($fullRole->companyAccessCompanies)->toHaveCount(0)
        ->and($fullRole->branchAccessBranches)->toHaveCount(0)
        ->and($fullRole->financialPeriodAccessPeriods)->toHaveCount(0);

    $nileRole = Role::query()
        ->where('name', 'runtime-demo-nile-scope-admin')
        ->with(['companyAccessCompanies', 'branchAccessBranches', 'financialPeriodAccessPeriods'])
        ->firstOrFail();

    expect($nileRole->company_access_restricted)->toBeTrue()
        ->and($nileRole->branch_access_restricted)->toBeTrue()
        ->and($nileRole->financial_period_access_restricted)->toBeTrue()
        ->and($nileRole->companyAccessCompanies->pluck('name')->all())
        ->toBe(['Nile Wood Industries - Runtime Demo'])
        ->and($nileRole->branchAccessBranches->pluck('name')->sort()->values()->all())
        ->toBe([
            '10th of Ramadan Warehouse - Runtime Demo',
            'Cairo Factory - Runtime Demo',
        ])
        ->and($nileRole->financialPeriodAccessPeriods->pluck('name')->sort()->values()->all())
        ->toBe([
            'FY 2026 - Runtime Demo',
            'FY 2027 - Runtime Demo',
        ]);

    $deltaRole = Role::query()
        ->where('name', 'runtime-demo-delta-operator')
        ->with(['companyAccessCompanies', 'branchAccessBranches', 'financialPeriodAccessPeriods'])
        ->firstOrFail();

    expect($deltaRole->companyAccessCompanies->pluck('name')->all())
        ->toBe(['Delta Home Furniture - Runtime Demo'])
        ->and($deltaRole->branchAccessBranches->pluck('name')->all())
        ->toBe(['Alexandria Showroom - Runtime Demo'])
        ->and($deltaRole->financialPeriodAccessPeriods->pluck('name')->all())
        ->toBe(['FY 2026 - Runtime Demo']);

    expect(User::query()->where('email', 'demo.full@shortcoded.test')->firstOrFail()->hasRole('runtime-demo-full-admin'))->toBeTrue()
        ->and(User::query()->where('email', 'demo.nile@shortcoded.test')->firstOrFail()->hasRole('runtime-demo-nile-scope-admin'))->toBeTrue()
        ->and(User::query()->where('email', 'demo.delta@shortcoded.test')->firstOrFail()->hasRole('runtime-demo-delta-operator'))->toBeTrue();

    $products = Product::query()
        ->where('notes', 'like', 'RuntimeDemoDataSeeder:%')
        ->with(['unit', 'size', 'color', 'itemModel', 'category', 'group'])
        ->get();

    expect($products->count())->toBeGreaterThanOrEqual($companies->count())
        ->and($products->whereNotNull('item_unit_id')->count())->toBe($products->count())
        ->and($products->whereNotNull('item_category_id')->count())->toBe($products->count())
        ->and($products->whereNotNull('item_group_id')->count())->toBe($products->count());

    $products->groupBy('company_id')
        ->each(function ($companyProducts): void {
            expect($companyProducts->whereNotNull('item_color_id')->count())->toBeGreaterThan(0)
                ->and($companyProducts->whereNotNull('item_size_id')->count())->toBeGreaterThan(0)
                ->and($companyProducts->whereNotNull('item_model_id')->count())->toBeGreaterThan(0);
        });

    $products->each(function (Product $product): void {
        foreach (['unit', 'size', 'color', 'itemModel', 'category', 'group'] as $relation) {
            if ($product->{$relation} === null) {
                continue;
            }

            expect($product->{$relation}->company_id)->toBe($product->company_id);
        }
    });
});
