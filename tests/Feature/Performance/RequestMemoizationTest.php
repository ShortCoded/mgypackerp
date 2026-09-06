<?php

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Models\MailConfiguration;
use Modules\Auth\Services\Reports\ActivityLogReport;
use Modules\Auth\Services\Reports\AuthLogReport;
use Modules\Auth\Services\Reports\AuthSessionReport;
use Modules\Auth\Services\RoleDocumentNumberSettingsService;
use Modules\Core\Models\Company;
use Modules\Core\Models\Setting;
use Modules\Core\Services\ActivityLogger;
use Modules\Core\Services\BrandingService;
use Modules\Core\Services\RequestMemo;
use Modules\Core\Services\SettingService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * @return list<array{query: string, bindings: array<int, mixed>, time: float}>
 */
function memoizationQueriesDuring(callable $callback): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    try {
        $callback();

        return DB::getQueryLog();
    } finally {
        DB::disableQueryLog();
    }
}

/**
 * @param  list<array{query: string, bindings: array<int, mixed>, time: float}>  $queries
 */
function memoizationQueryCount(array $queries, string $needle): int
{
    return collect($queries)
        ->filter(fn (array $query): bool => str_contains($query['query'], $needle))
        ->count();
}

function memoizationUserWithPermissions(array $permissions): User
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $user = User::factory()->create();
    $user->givePermissionTo($permissions);

    return $user;
}

function memoizationWithRoutedRequest(callable $callback): mixed
{
    $originalRequest = app('request');
    $request = Request::create('/memoized-request', 'GET');
    $route = new Route(['GET'], '/memoized-request', []);
    $request->setRouteResolver(fn (): Route => $route);
    app()->instance('request', $request);

    try {
        return $callback($request);
    } finally {
        app()->instance('request', $originalRequest);
    }
}

test('request memo is shared by every service resolution in the request scope', function (): void {
    memoizationWithRoutedRequest(function (): void {
        $first = app(RequestMemo::class);
        $second = app(RequestMemo::class);
        $first->put('shared-resolution-proof', 'shared');

        expect($second)->toBe($first)
            ->and($second->get('shared-resolution-proof'))->toBe('shared');
    });
});

test('BrandingService resolves main company once per request', function () {
    Company::factory()->main()->create(['name' => 'Memo Company']);

    $queries = memoizationQueriesDuring(function (): void {
        memoizationWithRoutedRequest(function (): void {
            app(BrandingService::class)->current();
            app(BrandingService::class)->current();
            app(BrandingService::class)->current();
        });
    });

    expect(memoizationQueryCount($queries, 'from "companies"'))->toBe(1)
        ->and(memoizationQueryCount($queries, "c.relname = 'companies'"))->toBeLessThanOrEqual(1);
});

test('SettingService repeated get for same key queries once', function () {
    Setting::query()->create([
        'key' => 'memoized.setting',
        'value' => 'memoized',
    ]);

    $queries = memoizationQueriesDuring(function (): void {
        memoizationWithRoutedRequest(function (): void {
            expect(app(SettingService::class)->get('memoized.setting'))->toBe('memoized')
                ->and(app(SettingService::class)->get('memoized.setting'))->toBe('memoized')
                ->and(app(SettingService::class)->get('memoized.setting'))->toBe('memoized');
        });
    });

    expect(memoizationQueryCount($queries, 'from "settings" where "key" = ?'))->toBe(1);
});

test('SettingService many loads multiple keys in one query and reuses them', function () {
    Setting::query()->create(['key' => 'memoized.first', 'value' => 'First']);
    Setting::query()->create(['key' => 'memoized.second', 'value' => 'Second']);

    $queries = memoizationQueriesDuring(function (): void {
        memoizationWithRoutedRequest(function (): void {
            $first = app(SettingService::class)->many([
                'memoized.first',
                'memoized.second',
            ]);
            $second = app(SettingService::class)->many([
                'memoized.first',
                'memoized.second',
            ]);

            expect($first)->toMatchArray([
                'memoized.first' => 'First',
                'memoized.second' => 'Second',
            ])->and($second)->toMatchArray($first);
        });
    });

    expect(memoizationQueryCount($queries, 'from "settings" where "key" in'))->toBe(1);
});

test('SettingService date formats are bulk loaded in one query', function () {
    Setting::query()->create(['key' => SettingService::DateFormatKey, 'value' => 'Y-m-d']);
    Setting::query()->create(['key' => SettingService::DateTimeFormatKey, 'value' => 'Y-m-d H:i']);

    $queries = memoizationQueriesDuring(function (): void {
        memoizationWithRoutedRequest(function (): void {
            expect(app(SettingService::class)->dateFormat())->toBe('Y-m-d')
                ->and(app(SettingService::class)->dateTimeFormat())->toBe('Y-m-d H:i')
                ->and(app(SettingService::class)->formatDateTime(now()))->toContain('-');
        });
    });

    expect(memoizationQueryCount($queries, 'from "settings" where "key" in'))->toBe(1)
        ->and(memoizationQueryCount($queries, 'from "settings" where "key" = ?'))->toBe(0);
});

test('document number settings preload date formats in one query', function () {
    Setting::query()->create(['key' => RoleDocumentNumberSettingsService::PrefixKey, 'value' => 'ROLE']);
    Setting::query()->create(['key' => RoleDocumentNumberSettingsService::PaddingKey, 'value' => '5']);
    Setting::query()->create(['key' => SettingService::DateFormatKey, 'value' => 'Y-m-d']);
    Setting::query()->create(['key' => SettingService::DateTimeFormatKey, 'value' => 'Y-m-d H:i']);

    $queries = memoizationQueriesDuring(function (): void {
        memoizationWithRoutedRequest(function (): void {
            expect(app(RoleDocumentNumberSettingsService::class)->current())->toMatchArray([
                'prefix' => 'ROLE',
                'padding' => 5,
            ])->and(app(SettingService::class)->dateFormat())->toBe('Y-m-d')
                ->and(app(SettingService::class)->dateTimeFormat())->toBe('Y-m-d H:i');
        });
    });

    expect(memoizationQueryCount($queries, 'from "settings" where "key" in'))->toBe(1);
});

test('report listing queries avoid unneeded heavy technical columns', function () {
    $activitySql = app(ActivityLogReport::class)->listingQuery()->toSql();
    $authLogSql = app(AuthLogReport::class)->listingQuery()->toSql();
    $authSessionSql = app(AuthSessionReport::class)->listingQuery()->toSql();

    expect($activitySql)
        ->not->toContain('"activity_log".*')
        ->and($authLogSql)
        ->not->toContain('"auth_logs".*')
        ->not->toContain('"auth_logs"."payload_summary"')
        ->not->toContain('"auth_logs"."device_context"')
        ->not->toContain('"auth_logs"."network_context"')
        ->and($authSessionSql)
        ->not->toContain('"user_presence_sessions".*')
        ->not->toContain('"user_presence_sessions"."context"')
        ->not->toContain('"user_presence_sessions"."session_fingerprint"')
        ->not->toContain('"user_presence_sessions"."user_agent"');
});

test('ActivityLogger memoizes activity log schema checks across logger instances', function () {
    $user = User::factory()->create();
    $queries = memoizationQueriesDuring(function () use ($user): void {
        memoizationWithRoutedRequest(function (Request $request) use ($user): void {
            $request->setUserResolver(fn (): User => $user);

            app(ActivityLogger::class)->log($request, 'core', 'memoized.first', 'success', [
                'properties_only' => true,
                'properties' => ['doc_num' => 'Memo-00001'],
            ]);
            app(ActivityLogger::class)->log($request, 'core', 'memoized.second', 'success', [
                'properties_only' => true,
                'properties' => ['doc_num' => 'Memo-00002'],
            ]);
        });
    });

    $schemaQueries = collect($queries)
        ->filter(fn (array $query): bool => str_contains($query['query'], 'pg_attribute')
            || str_contains($query['query'], 'pg_class')
            || str_contains($query['query'], 'pragma_table')
            || str_contains($query['query'], 'sqlite_master'));

    expect($schemaQueries->count())->toBeLessThanOrEqual(3);
});

test('MailConfigurationService is lazy on normal page render', function () {
    MailConfiguration::query()->create([
        'name' => 'Lazy SMTP',
        'mailer' => 'smtp',
        'host' => '127.0.0.1',
        'port' => 2525,
        'from_address' => 'noreply@example.com',
        'from_name' => 'ERP',
        'is_active' => true,
    ]);

    $user = memoizationUserWithPermissions(['roles.view']);

    $queries = memoizationQueriesDuring(function () use ($user): void {
        $this->actingAs($user)
            ->get(route('admin.roles.index'))
            ->assertOk();
    });

    expect(memoizationQueryCount($queries, 'mail_configurations'))->toBe(0);
});
