<?php

use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\RequestMemo;
use Modules\Core\Services\ScreenDataVisibilityRegistry;
use Modules\Core\Services\ScreenDataVisibilityService;

test('visibility rule lookup does not introspect the database schema at runtime', function (): void {
    $user = User::factory()->create();
    $registry = Mockery::mock(ScreenDataVisibilityRegistry::class);
    $operatingContext = Mockery::mock(OperatingContextService::class);
    $queries = [];

    $registry->shouldReceive('isSupported')
        ->once()
        ->with('customers')
        ->andReturnTrue();
    $operatingContext->shouldReceive('selectedCompanyId')
        ->once()
        ->andReturn(1);

    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = strtolower($query->sql);
    });

    $service = new ScreenDataVisibilityService(
        $registry,
        $operatingContext,
        new RequestMemo,
    );

    expect($service->ruleFor($user, 'customers'))->toBeNull()
        ->and(collect($queries)->contains(
            fn (string $sql): bool => str_contains($sql, 'screen_data_visibility_rules'),
        ))->toBeTrue()
        ->and(collect($queries)->filter(
            fn (string $sql): bool => str_contains($sql, 'pragma_table')
                || str_contains($sql, 'sqlite_master')
                || str_contains($sql, 'information_schema')
                || str_contains($sql, 'pg_catalog'),
        ))->toBeEmpty();
});
