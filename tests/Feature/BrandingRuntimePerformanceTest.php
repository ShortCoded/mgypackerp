<?php

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Company;
use Modules\Core\Services\BrandingService;

test('branding cache misses do not introspect the database schema at runtime', function (): void {
    Company::factory()->create([
        'is_main' => true,
        'status' => 'active',
    ]);
    Cache::forget(BrandingService::MainCompanyCacheKey);
    $queries = [];

    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = strtolower($query->sql);
    });

    expect(app(BrandingService::class)->current())
        ->has_company->toBeTrue()
        ->and(collect($queries)->contains(
            fn (string $sql): bool => str_contains($sql, 'from "companies"'),
        ))->toBeTrue()
        ->and(collect($queries)->filter(
            fn (string $sql): bool => str_contains($sql, 'pragma_table')
                || str_contains($sql, 'sqlite_master')
                || str_contains($sql, 'information_schema')
                || str_contains($sql, 'pg_catalog'),
        ))->toBeEmpty();
});
