<?php

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Auth\Database\Seeders\PermissionSeeder;
use Modules\Auth\Exports\ActivityLogsExport;
use Modules\Auth\Exports\AuthLogsExport;
use Modules\Auth\Http\Middleware\EnsureUserAccountIsActive;
use Modules\Auth\Models\AuthLog;
use Modules\Auth\Models\UserPresenceSession;
use Modules\Auth\Services\AuthLogService;
use Modules\Auth\Services\Reports\ActivityLogReport;
use Modules\Auth\Services\Reports\AuthLogReport;
use Modules\Auth\Services\Reports\AuthSessionReport;
use Modules\Auth\Services\UserPresenceService;
use Modules\Core\Http\Middleware\TrackUserActivity;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\ActivityLogProperties;
use Modules\Core\Services\BrandingService;
use Modules\Core\Services\DateFormatService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\SettingService;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;

beforeEach(function (): void {
    $this->withoutMiddleware([
        EnsureUserAccountIsActive::class,
        TrackUserActivity::class,
    ]);
});

function reportUserWithPermissions(array $permissions): User
{
    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $user = User::factory()->create(['locale' => 'en']);
    $user->givePermissionTo($permissions);

    return $user;
}

function adminReportContextBranch(array $overrides = []): Branch
{
    static $documentNumber = 9600;

    $documentNumber++;
    $company = $overrides['company'] ?? Company::factory()->main()->create([
        'name' => 'Report Context Company',
        'status' => 'active',
    ]);
    unset($overrides['company']);

    return Branch::query()->create([
        'doc_number' => $documentNumber,
        'doc_num' => 'Branch-'.str_pad((string) $documentNumber, 5, '0', STR_PAD_LEFT),
        'company_id' => $company->getKey(),
        'name' => 'Report Branch '.$documentNumber,
        'type' => 'main',
        'status' => 'active',
        ...$overrides,
    ]);
}

function adminReportContextPeriod(array $overrides = []): FinancialPeriod
{
    static $documentNumber = 9700;

    $documentNumber++;

    return FinancialPeriod::query()->create([
        'doc_number' => $documentNumber,
        'doc_num' => 'Period-'.str_pad((string) $documentNumber, 5, '0', STR_PAD_LEFT),
        'name' => 'Report Period '.$documentNumber,
        'from_date' => now()->startOfYear()->toDateString(),
        'to_date' => now()->endOfYear()->toDateString(),
        'is_closed' => false,
        ...$overrides,
    ]);
}

/**
 * @return list<array{data: string, orderable: bool, searchable: bool}>
 */
function adminReportDataTableColumns(string $report): array
{
    return match ($report) {
        'auth_sessions' => [
            ['data' => 'user_label', 'orderable' => true, 'searchable' => true],
            ['data' => 'branch_label', 'orderable' => true, 'searchable' => true],
            ['data' => 'financial_period_label', 'orderable' => true, 'searchable' => true],
            ['data' => 'account_status_label', 'orderable' => true, 'searchable' => true],
            ['data' => 'presence_status_label', 'orderable' => true, 'searchable' => true],
            ['data' => 'device_label', 'orderable' => true, 'searchable' => true],
            ['data' => 'ip_address', 'orderable' => true, 'searchable' => true],
            ['data' => 'login_at', 'orderable' => true, 'searchable' => true],
            ['data' => 'last_seen_at', 'orderable' => true, 'searchable' => true],
            ['data' => 'duration_label', 'orderable' => true, 'searchable' => false],
            ['data' => 'actions', 'orderable' => false, 'searchable' => false],
        ],
        'auth_logs' => [
            ['data' => 'created_at', 'orderable' => true, 'searchable' => true],
            ['data' => 'user_label', 'orderable' => true, 'searchable' => true],
            ['data' => 'event_label', 'orderable' => true, 'searchable' => true],
            ['data' => 'status_label', 'orderable' => true, 'searchable' => true],
            ['data' => 'ip_label', 'orderable' => true, 'searchable' => true],
            ['data' => 'location_label', 'orderable' => true, 'searchable' => true],
            ['data' => 'device_label', 'orderable' => true, 'searchable' => true],
            ['data' => 'actions', 'orderable' => false, 'searchable' => false],
        ],
        'activity_logs' => [
            ['data' => 'created_at', 'orderable' => true, 'searchable' => true],
            ['data' => 'causer_label', 'orderable' => true, 'searchable' => true],
            ['data' => 'area_label', 'orderable' => true, 'searchable' => true],
            ['data' => 'activity_label', 'orderable' => true, 'searchable' => true],
            ['data' => 'result_label', 'orderable' => true, 'searchable' => true],
            ['data' => 'record_label', 'orderable' => true, 'searchable' => true],
            ['data' => 'summary_label', 'orderable' => false, 'searchable' => true],
            ['data' => 'actions', 'orderable' => false, 'searchable' => false],
        ],
    };
}

/**
 * @return array<string, mixed>
 */
function adminReportDataTablePayload(string $report, int $orderColumn, string $direction = 'asc', ?string $search = null): array
{
    return [
        'draw' => 1,
        'start' => 0,
        'length' => 25,
        'search' => ['value' => $search ?? '', 'regex' => 'false'],
        'order' => [['column' => $orderColumn, 'dir' => $direction]],
        'columns' => collect(adminReportDataTableColumns($report))
            ->map(fn (array $column): array => [
                'data' => $column['data'],
                'name' => $column['data'],
                'searchable' => $column['searchable'] ? 'true' : 'false',
                'orderable' => $column['orderable'] ? 'true' : 'false',
                'search' => ['value' => '', 'regex' => 'false'],
            ])
            ->all(),
    ];
}

/**
 * @return list<int>
 */
function adminReportOrderableColumnIndexes(string $report): array
{
    return collect(adminReportDataTableColumns($report))
        ->filter(fn (array $column): bool => $column['orderable'])
        ->keys()
        ->values()
        ->all();
}

function adminReportCellText(mixed $value): string
{
    return trim(html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}

test('administration report permissions are seeded and assigned to admin role', function () {
    $this->seed(PermissionSeeder::class);

    foreach ([
        'activity.logs.view',
        'activity.logs.details',
        'activity.logs.export',
        'activity.logs.pdf',
        'auth.logs.view',
        'auth.logs.details',
        'auth.logs.export',
        'auth.logs.pdf',
        'auth.sessions.view',
        'auth.sessions.details',
        'auth.sessions.export',
        'auth.sessions.pdf',
        'auth.sessions.force_logout',
    ] as $permission) {
        expect(Permission::query()->where('name', $permission)->where('guard_name', 'web')->exists())->toBeTrue();
    }
});

test('report date filters use the project datepicker wiring', function () {
    $dateFormat = app(DateFormatService::class)->jsDateFormat();

    foreach ([
        ['activity.logs.view', 'admin.activity-logs.index', 'activity-date-from', 'activity-date-to'],
        ['auth.logs.view', 'admin.auth-logs.index', 'auth-date-from', 'auth-date-to'],
        ['auth.sessions.view', 'admin.auth-sessions.index', 'session-date-from', 'session-date-to'],
    ] as [$permission, $route, $fromId, $toId]) {
        $response = $this->actingAs(reportUserWithPermissions([$permission]))
            ->get(route($route));

        $response->assertOk()
            ->assertSee('assets/js/modules/Core/flatpickr-locales.js', false)
            ->assertSee('js-date-picker js-report-filter-control', false)
            ->assertSee('id="'.$fromId.'" name="date_from"', false)
            ->assertSee('id="'.$toId.'" name="date_to"', false)
            ->assertSee('data-date-format="'.$dateFormat.'"', false)
            ->assertSee('data-locale="en"', false);
    }
});

test('activity logs report renders compact ERP filters without active filter badges', function () {
    app()->setLocale('en');

    $actor = reportUserWithPermissions([
        'activity.logs.view',
        'activity.logs.export',
        'activity.logs.pdf',
    ]);

    $response = $this->actingAs($actor)
        ->get(route('admin.activity-logs.index'));

    $content = $response->getContent();

    $response->assertOk()
        ->assertSee('activity-logs-report', false)
        ->assertSee('mb-0 text-600 fs-10', false)
        ->assertSee('report-filter-actions', false)
        ->assertSee('id="activity-logs-filter-panel"', false)
        ->assertSee('data-bs-target="#activity-logs-filter-panel"', false)
        ->assertSee('aria-expanded="false"', false)
        ->assertSee('report-filter-toggle-indicator', false)
        ->assertSee('collapse report-filter-collapse', false)
        ->assertSee('col-12 col-md-6 col-xl-3 report-filter-field', false)
        ->assertSee('input-group input-group-sm w-100 report-date-input-group', false)
        ->assertSee('form-control form-control-sm js-date-picker js-report-filter-control', false)
        ->assertSee('form-select form-select-sm w-100 js-select2-ajax js-report-filter-control', false)
        ->assertSee(__('activity_logs.report_title'))
        ->assertSee(__('activity_logs.table_title'))
        ->assertSee(__('reports.refresh'))
        ->assertSee(__('reports.export'))
        ->assertSee(__('reports.apply_filter'))
        ->assertSee(__('reports.reset'))
        ->assertSee('falcon-data-table', false)
        ->assertDontSee('erp-datatable-wrapper', false)
        ->assertSee('<th class="dt-activity-log-summary">', false)
        ->assertDontSee('js-activity-log-summary', false)
        ->assertDontSee('id="activity-company" name="company"', false)
        ->assertDontSee('id="activity-ip" name="ip"', false)
        ->assertDontSee('<th class="dt-activity-log-company">', false)
        ->assertDontSee('<th class="dt-activity-log-ip">', false)
        ->assertDontSee('<th class="dt-activity-log-changes">', false)
        ->assertDontSee('report-page-header', false)
        ->assertDontSee('report-header-actions', false)
        ->assertDontSee('js-active-filter-badges', false)
        ->assertDontSee('active-filters', false)
        ->assertDontSee('js-report-clear', false)
        ->assertDontSee('vendors/sweetalert2/sweetalert2.all.min.js', false);

    $orderedFilterIds = [
        'activity-date-from',
        'activity-date-to',
        'activity-causer',
        'activity-area',
        'activity-action',
        'activity-status',
    ];
    $positions = collect($orderedFilterIds)
        ->map(fn (string $id): int|false => strpos($content, 'id="'.$id.'"'))
        ->all();

    expect(substr_count($content, 'js-report-reset'))->toBe(1)
        ->and($positions)->not->toContain(false)
        ->and($positions)->toBe(collect($positions)->sort()->values()->all())
        ->and(__('activity_logs.table_title'))->toBe('Activity Logs')
        ->and($content)->not->toContain('updateActiveFilterBadges')
        ->and($content)->not->toContain('activeFilters')
        ->and($content)->not->toContain('dateRangeLabel')
        ->and($content)->not->toContain('dateRangeSeparator');

    app()->setLocale('ar');

    expect(__('activity_logs.report_title'))
        ->toBe('تقرير النشاط')
        ->and(__('activity_logs.table_title'))->toBe('سجلات النشاط')
        ->and(__('reports.apply_filter'))->toBe('تطبيق الفلتر')
        ->and(__('reports.reset'))->toBe('إعادة تعيين')
        ->and(__('activity_logs.actions.details'))->toBe('عرض التفاصيل')
        ->and(__('activity_logs.actions.toggle_filters'))->toBe('إظهار أو إخفاء الفلاتر')
        ->and(__('activity_logs.messages.details_available'))->toBe('توجد تفاصيل');
});

test('auth logs report renders compact filters without active filter badges', function () {
    app()->setLocale('en');

    $actor = reportUserWithPermissions([
        'auth.logs.view',
        'auth.logs.export',
        'auth.logs.pdf',
    ]);

    $response = $this->actingAs($actor)
        ->get(route('admin.auth-logs.index'));

    $content = $response->getContent();

    $response->assertOk()
        ->assertSee('auth-logs-report', false)
        ->assertSee('id="auth-logs-filter-panel"', false)
        ->assertSee('data-bs-target="#auth-logs-filter-panel"', false)
        ->assertSee('aria-expanded="false"', false)
        ->assertSee('report-filter-toggle-indicator', false)
        ->assertSee('collapse report-filter-collapse', false)
        ->assertSee('col-12 col-md-6 col-xl-3 report-filter-field', false)
        ->assertSee('input-group input-group-sm w-100 report-date-input-group', false)
        ->assertSee('form-control form-control-sm js-date-picker js-report-filter-control', false)
        ->assertSee('form-select form-select-sm w-100 js-select2-ajax js-report-filter-control', false)
        ->assertSee('id="auth-ip" name="ip"', false)
        ->assertSee('id="auth-failure-reason" name="failure_reason"', false)
        ->assertDontSee('js-auth-log-summary', false)
        ->assertSee(__('reports.apply_filter'))
        ->assertSee(__('reports.reset'))
        ->assertSee('falcon-data-table', false)
        ->assertDontSee('erp-datatable-wrapper', false)
        ->assertSee('<th class="dt-auth-log-user">', false)
        ->assertSee(__('reports.refresh'))
        ->assertSee(__('reports.export'))
        ->assertSee(__('auth_logs.table_title'))
        ->assertDontSee('id="auth-device-type"', false)
        ->assertDontSee('id="auth-browser-name"', false)
        ->assertDontSee('id="auth-os-name"', false)
        ->assertDontSee('id="auth-country"', false)
        ->assertDontSee('id="auth-city"', false)
        ->assertDontSee('id="auth-guard"', false)
        ->assertDontSee('id="auth-remember-me" name="remember_me"', false)
        ->assertDontSee('<th>'.__('auth_logs.fields.identifier').'</th>', false)
        ->assertDontSee('<th>'.__('auth_logs.fields.branch').'</th>', false)
        ->assertDontSee('<th>'.__('auth_logs.fields.financial_period').'</th>', false)
        ->assertDontSee('<option value="">Clear</option>', false)
        ->assertDontSee('js-active-filter-badges', false)
        ->assertDontSee('active-filters', false)
        ->assertDontSee(__('auth_logs.fields.summary'));

    expect(substr_count($content, 'js-report-reset'))->toBe(1)
        ->and($content)->not->toContain('js-report-clear')
        ->and($content)->not->toContain('updateActiveFilterBadges')
        ->and($content)->not->toContain('activeFilters')
        ->and($content)->not->toContain('dateRangeLabel')
        ->and($content)->not->toContain('dateRangeSeparator');

    app()->setLocale('ar');

    expect(__('auth_logs.filters.all'))
        ->toBe('الكل')
        ->and(__('reports.apply_filter'))->toBe('تطبيق الفلتر')
        ->and(__('reports.reset'))->toBe('إعادة تعيين');
});

test('auth logs and active sessions capture and display operating context', function () {
    app()->setLocale('en');

    $actor = reportUserWithPermissions([
        'auth.logs.view',
        'auth.logs.details',
        'auth.sessions.view',
        'auth.sessions.details',
    ]);
    $branch = adminReportContextBranch(['name' => 'Downtown Branch']);
    $period = adminReportContextPeriod(['name' => 'FY 2026']);

    Route::get('/__test-auth-log-operating-context', function (Request $request, AuthLogService $authLogs) {
        $authLogs->log($request, 'login_success', 'success', [
            'user' => $request->user(),
            'identifier' => 'report-user@example.test',
        ]);

        return response()->json(['ok' => true]);
    })->middleware('web');

    $this->withSession([
        OperatingContextService::BranchIdKey => $branch->getKey(),
        OperatingContextService::BranchDocNumKey => $branch->doc_num,
        OperatingContextService::FinancialPeriodIdKey => $period->getKey(),
        OperatingContextService::FinancialPeriodDocNumKey => $period->doc_num,
    ])
        ->actingAs($actor)
        ->getJson('/__test-auth-log-operating-context')
        ->assertOk();

    $authLog = AuthLog::query()
        ->where('event', 'login_success')
        ->where('identifier', 'report-user@example.test')
        ->firstOrFail();
    $authLog->forceFill([
        'branch_id' => $branch->getKey(),
        'branch_doc_num' => $branch->doc_num,
        'branch_name' => $branch->name,
        'financial_period_id' => $period->getKey(),
        'financial_period_doc_num' => $period->doc_num,
        'financial_period_name' => $period->name,
    ])->save();

    UserPresenceSession::query()->create([
        'user_id' => $actor->id,
        'status' => UserPresenceService::StatusOnline,
        'ip_address' => '203.0.113.88',
        'login_at' => now(),
        'last_seen_at' => now(),
        'last_activity_at' => now(),
        'expires_at' => now()->addHour(),
        'branch_id' => $branch->getKey(),
        'branch_doc_num' => $branch->doc_num,
        'branch_name' => $branch->name,
        'financial_period_id' => $period->getKey(),
        'financial_period_doc_num' => $period->doc_num,
        'financial_period_name' => $period->name,
    ]);

    expect($authLog->branch_id)
        ->toBe($branch->getKey())
        ->and($authLog->branch_doc_num)->toBe($branch->doc_num)
        ->and($authLog->financial_period_id)->toBe($period->getKey())
        ->and($authLog->financial_period_doc_num)->toBe($period->doc_num);

    $authTable = $this->actingAs($actor)
        ->getJson(route('admin.auth-logs.data', ['event' => 'login_success']));
    $sessionTable = $this->actingAs($actor)
        ->getJson(route('admin.auth-sessions.data'));

    $authTable->assertOk();
    $sessionTable->assertOk();

    $authRow = collect($authTable->json('data'))
        ->first(fn (array $row): bool => str_contains((string) ($row['event_label'] ?? ''), 'Login successful'));
    $sessionRow = collect($sessionTable->json('data'))
        ->first(fn (array $row): bool => str_contains((string) ($row['branch_label'] ?? ''), 'Downtown Branch'));

    expect($authRow)
        ->not->toBeNull()
        ->and(array_key_exists('branch_label', $authRow))->toBeFalse()
        ->and(array_key_exists('financial_period_label', $authRow))->toBeFalse()
        ->and($sessionRow)->not->toBeNull()
        ->and($sessionRow['financial_period_label'])->toContain('FY 2026')
        ->and(app(AuthLogReport::class)->headings())->not->toContain('Branch', 'Financial Period')
        ->and(app(AuthSessionReport::class)->headings())->toContain('Branch', 'Financial Period');

    $authDetails = app(AuthLogReport::class)->details($authLog);
    $session = UserPresenceSession::query()
        ->where('branch_doc_num', $branch->doc_num)
        ->where('financial_period_doc_num', $period->doc_num)
        ->firstOrFail();
    $sessionDetails = app(AuthSessionReport::class)->details($session);

    expect(json_encode($authDetails, JSON_UNESCAPED_UNICODE))
        ->toContain('Operating Context')
        ->toContain('Downtown Branch')
        ->toContain('FY 2026')
        ->and(json_encode($sessionDetails, JSON_UNESCAPED_UNICODE))
        ->toContain('Operating Context')
        ->toContain('Downtown Branch')
        ->toContain('FY 2026');
});

test('active sessions report renders compact filters without summary cards', function () {
    app()->setLocale('en');

    $actor = reportUserWithPermissions([
        'auth.sessions.view',
        'auth.sessions.export',
        'auth.sessions.pdf',
    ]);

    foreach ([
        UserPresenceService::StatusOnline,
        UserPresenceService::StatusIdle,
        UserPresenceService::StatusLocked,
        UserPresenceService::StatusOffline,
    ] as $status) {
        UserPresenceSession::query()->create([
            'user_id' => $actor->id,
            'status' => $status,
            'login_at' => now(),
            'last_seen_at' => now(),
            'last_activity_at' => now(),
        ]);
    }

    $response = $this->actingAs($actor)
        ->get(route('admin.auth-sessions.index'));

    $response->assertOk()
        ->assertSee('auth-sessions-report', false)
        ->assertDontSee('js-auth-session-summary', false)
        ->assertDontSee('report-summary-grid', false)
        ->assertDontSee('report-summary-card', false)
        ->assertDontSee('data-auth-session-summary-count', false)
        ->assertSee('id="auth-sessions-filter-panel"', false)
        ->assertSee('data-bs-target="#auth-sessions-filter-panel"', false)
        ->assertSee('aria-expanded="false"', false)
        ->assertSee('report-filter-toggle-indicator', false)
        ->assertSee('collapse report-filter-collapse', false)
        ->assertSee('col-12 col-md-6 col-xl-3 report-filter-field', false)
        ->assertSee('input-group input-group-sm w-100 report-date-input-group', false)
        ->assertSee('form-control form-control-sm js-date-picker js-report-filter-control', false)
        ->assertSee('form-select form-select-sm w-100 js-select2-ajax js-report-filter-control', false)
        ->assertSee('falcon-data-table', false)
        ->assertDontSee('erp-datatable-wrapper', false)
        ->assertSee('<th class="dt-user-cell">', false)
        ->assertDontSee('js-active-filter-badges', false)
        ->assertDontSee(__('auth_sessions.active_filters'))
        ->assertSee(__('reports.refresh'))
        ->assertSee(__('reports.export'))
        ->assertSee(__('auth_sessions.messages.summary_scope'));

    expect(substr_count($response->getContent(), 'js-report-reset'))->toBe(1)
        ->and($response->getContent())->not->toContain('js-report-clear')
        ->and($response->getContent())->not->toContain('session-offline-reason')
        ->and($response->getContent())->not->toContain('clearFilters')
        ->and($response->getContent())->not->toContain('activeFilters')
        ->and($response->getContent())->not->toContain('dateRangeLabel')
        ->and($response->getContent())->not->toContain('dateRangeSeparator')
        ->and($response->getContent())->not->toContain(__('auth_sessions.actions.clear_filters'));

    $presenceOptions = $this->actingAs($actor)
        ->getJson(route('admin.auth-sessions.filter-options.presence-statuses'));
    $offlineReasonOptions = $this->actingAs($actor)
        ->getJson(route('admin.auth-sessions.filter-options.offline-reasons'));

    $presenceOptions->assertOk();
    $offlineReasonOptions->assertOk();

    expect(collect($presenceOptions->json('results'))->pluck('id')->all())
        ->toBe([
            UserPresenceService::StatusOnline,
            UserPresenceService::StatusIdle,
            UserPresenceService::StatusLocked,
        ])
        ->and($offlineReasonOptions->json('results'))->toBe([]);
});

test('active sessions datatable returns active rows without stat payload or raw fingerprints', function () {
    app()->setLocale('en');

    $actor = reportUserWithPermissions(['auth.sessions.view', 'auth.sessions.details', 'auth.sessions.force_logout']);
    $target = User::factory()->create();

    UserPresenceSession::query()->create([
        'user_id' => $target->id,
        'session_fingerprint' => 'raw-online-fingerprint',
        'status' => UserPresenceService::StatusOnline,
        'ip_address' => '127.0.0.2',
        'browser_name' => 'Chrome',
        'os_name' => 'Linux',
        'device_type' => 'desktop',
        'login_at' => now(),
        'last_seen_at' => now(),
        'last_activity_at' => now(),
    ]);
    UserPresenceSession::query()->create([
        'user_id' => $target->id,
        'session_fingerprint' => 'raw-idle-fingerprint',
        'status' => UserPresenceService::StatusIdle,
        'ip_address' => '127.0.0.3',
        'login_at' => now(),
        'last_seen_at' => now(),
        'last_activity_at' => now(),
    ]);

    $response = $this->actingAs($actor)
        ->getJson(route('admin.auth-sessions.data', ['status' => UserPresenceService::StatusOnline]));

    $response->assertOk();

    $actionHtml = collect($response->json('data'))->pluck('actions')->implode(' ');

    expect($response->json('summary_cards'))
        ->toBeNull()
        ->and($response->json('recordsFiltered'))
        ->toBe(1)
        ->and($actionHtml)->toContain('js-force-logout-session')
        ->and($response->getContent())->not->toContain('raw-online-fingerprint')
        ->and($response->getContent())->not->toContain('raw-idle-fingerprint')
        ->and($response->getContent())->not->toContain('session_fingerprint');
});

test('active sessions report uses duplicate-login freshness for filters and end actions', function () {
    app()->setLocale('en');
    $now = Carbon::create(2026, 1, 1, 12, 0, 0, config('app.timezone'));
    Carbon::setTestNow($now);
    config(['presence.duplicate_login_active_threshold_seconds' => 120]);

    $actor = reportUserWithPermissions(['auth.sessions.view', 'auth.sessions.details', 'auth.sessions.force_logout']);
    $target = User::factory()->create();

    $freshOnline = UserPresenceSession::query()->create([
        'user_id' => $target->id,
        'session_fingerprint' => 'fresh-online-fingerprint',
        'status' => UserPresenceService::StatusOnline,
        'login_at' => $now->copy()->subMinutes(10),
        'last_seen_at' => $now->copy()->subSeconds(30),
        'last_activity_at' => $now->copy()->subSeconds(30),
        'expires_at' => $now->copy()->addHour(),
    ]);
    $staleOnline = UserPresenceSession::query()->create([
        'user_id' => $target->id,
        'session_fingerprint' => 'stale-online-fingerprint',
        'status' => UserPresenceService::StatusOnline,
        'login_at' => $now->copy()->subMinutes(9),
        'last_seen_at' => $now->copy()->subSeconds(121),
        'last_activity_at' => $now->copy()->subSeconds(121),
        'expires_at' => $now->copy()->addHour(),
    ]);
    $expiredIdle = UserPresenceSession::query()->create([
        'user_id' => $target->id,
        'session_fingerprint' => 'expired-idle-fingerprint',
        'status' => UserPresenceService::StatusIdle,
        'login_at' => $now->copy()->subMinutes(8),
        'last_seen_at' => $now->copy()->subSeconds(20),
        'last_activity_at' => $now->copy()->subMinute(),
        'expires_at' => $now->copy()->subSecond(),
    ]);
    $endedLocked = UserPresenceSession::query()->create([
        'user_id' => $target->id,
        'session_fingerprint' => 'ended-locked-fingerprint',
        'status' => UserPresenceService::StatusLocked,
        'login_at' => $now->copy()->subMinutes(7),
        'last_seen_at' => $now->copy()->subSeconds(20),
        'last_activity_at' => $now->copy()->subMinute(),
        'logout_at' => $now->copy()->subSecond(),
        'expires_at' => $now->copy()->addHour(),
    ]);
    $offline = UserPresenceSession::query()->create([
        'user_id' => $target->id,
        'session_fingerprint' => 'offline-fingerprint',
        'status' => UserPresenceService::StatusOffline,
        'login_at' => $now->copy()->subMinutes(6),
        'last_seen_at' => $now->copy()->subSeconds(20),
        'last_activity_at' => $now->copy()->subMinute(),
        'logout_at' => $now->copy()->subSecond(),
        'expires_at' => $now->copy()->addHour(),
        'offline_reason' => UserPresenceService::ReasonLogout,
    ]);
    $logoutLog = AuthLog::query()->create([
        'user_id' => $target->id,
        'event' => 'logout',
        'status' => 'success',
        'login' => $target->email,
        'identifier' => $target->email,
        'session_fingerprint' => 'offline-fingerprint',
        'created_at' => $now,
    ]);

    $report = app(AuthSessionReport::class);

    expect(app(UserPresenceService::class)->activeSessionCountForUser($target))
        ->toBe(1)
        ->and($report->query()->pluck('user_presence_sessions.public_id')->all())->toBe([$freshOnline->public_id])
        ->and($report->row($freshOnline)['presence'])->toBe(__('auth_sessions.presence_statuses.online'))
        ->and($report->row($staleOnline)['presence'])->toBe(__('auth_sessions.presence_statuses.offline'))
        ->and($report->row($expiredIdle)['presence'])->toBe(__('auth_sessions.presence_statuses.offline'))
        ->and($report->row($endedLocked)['presence'])->toBe(__('auth_sessions.presence_statuses.offline'))
        ->and($report->row($offline)['presence'])->toBe(__('auth_sessions.presence_statuses.offline'));

    $allRows = $this->actingAs($actor)->getJson(route('admin.auth-sessions.data'));
    $onlineRows = $this->actingAs($actor)->getJson(route('admin.auth-sessions.data', [
        'status' => UserPresenceService::StatusOnline,
    ]));
    $offlineRows = $this->actingAs($actor)->getJson(route('admin.auth-sessions.data', [
        'status' => UserPresenceService::StatusOffline,
    ]));

    $allRows->assertOk();
    $onlineRows->assertOk();
    $offlineRows->assertOk();

    $allActionHtml = collect($allRows->json('data'))->pluck('actions')->implode(' ');

    expect($allActionHtml)
        ->toContain(route('admin.auth-sessions.force-logout', $freshOnline->public_id))
        ->not->toContain(route('admin.auth-sessions.force-logout', $staleOnline->public_id))
        ->not->toContain(route('admin.auth-sessions.force-logout', $expiredIdle->public_id))
        ->not->toContain(route('admin.auth-sessions.force-logout', $endedLocked->public_id))
        ->not->toContain(route('admin.auth-sessions.force-logout', $offline->public_id))
        ->and($allRows->json('recordsFiltered'))->toBe(1)
        ->and($onlineRows->json('recordsFiltered'))->toBe(1)
        ->and($offlineRows->json('recordsFiltered'))->toBe(0)
        ->and(UserPresenceSession::query()->whereKey($staleOnline->id)->exists())->toBeTrue()
        ->and(UserPresenceSession::query()->whereKey($expiredIdle->id)->exists())->toBeTrue()
        ->and(UserPresenceSession::query()->whereKey($endedLocked->id)->exists())->toBeTrue()
        ->and(UserPresenceSession::query()->whereKey($offline->id)->exists())->toBeTrue()
        ->and(AuthLog::query()->whereKey($logoutLog->id)->exists())->toBeTrue();

    $this->actingAs($actor)
        ->getJson(route('admin.auth-sessions.details', $offline->public_id))
        ->assertNotFound();

    $staleForceLogout = $this->actingAs($actor)
        ->postJson(route('admin.auth-sessions.force-logout', $staleOnline->public_id));

    $staleForceLogout
        ->assertStatus(422)
        ->assertJsonPath('message', __('auth_sessions.messages.session_not_active'));

    $this->actingAs($actor)
        ->postJson(route('admin.auth-sessions.force-logout', $freshOnline->public_id))
        ->assertOk();

    expect($freshOnline->fresh()->status)->toBe(UserPresenceService::StatusOffline)
        ->and($freshOnline->fresh()->offline_reason)->toBe(UserPresenceService::ReasonForcedLogout);

    Carbon::setTestNow();
});

test('active sessions datatable orders effective presence status in both directions', function () {
    app()->setLocale('en');
    $now = Carbon::create(2026, 1, 1, 12, 0, 0, config('app.timezone'));
    Carbon::setTestNow($now);
    config(['presence.duplicate_login_active_threshold_seconds' => 120]);

    $actor = reportUserWithPermissions(['auth.sessions.view']);
    $target = User::factory()->create();

    foreach ([
        [UserPresenceService::StatusOffline, '10.30.0.4', 4, 40, null, $now->copy()->addHour()],
        [UserPresenceService::StatusLocked, '10.30.0.3', 3, 30, null, $now->copy()->addHour()],
        [UserPresenceService::StatusIdle, '10.30.0.2', 2, 20, null, $now->copy()->addHour()],
        [UserPresenceService::StatusOnline, '10.30.0.1', 1, 10, null, $now->copy()->addHour()],
        [UserPresenceService::StatusOnline, '10.30.0.5', 5, 121, null, $now->copy()->addHour()],
    ] as [$status, $ip, $loginMinutesAgo, $lastSeenSecondsAgo, $logoutAt, $expiresAt]) {
        UserPresenceSession::query()->create([
            'user_id' => $target->id,
            'status' => $status,
            'ip_address' => $ip,
            'login_at' => $now->copy()->subMinutes($loginMinutesAgo),
            'last_seen_at' => $now->copy()->subSeconds($lastSeenSecondsAgo),
            'last_activity_at' => $now->copy()->subSeconds($lastSeenSecondsAgo),
            'logout_at' => $logoutAt,
            'expires_at' => $expiresAt,
        ]);
    }

    $ascending = $this->actingAs($actor)
        ->getJson(route('admin.auth-sessions.data', adminReportDataTablePayload('auth_sessions', 4, 'asc')));
    $descending = $this->actingAs($actor)
        ->getJson(route('admin.auth-sessions.data', adminReportDataTablePayload('auth_sessions', 4, 'desc')));

    $ascending->assertOk();
    $descending->assertOk();

    expect(collect($ascending->json('data'))->pluck('presence_status_label')->map(fn (mixed $value): string => adminReportCellText($value))->all())
        ->toBe(['Online', 'Idle', 'Locked'])
        ->and(collect($descending->json('data'))->pluck('presence_status_label')->map(fn (mixed $value): string => adminReportCellText($value))->all())
        ->toBe(['Locked', 'Idle', 'Online']);

    Carbon::setTestNow();
});

test('active sessions datatable orders duration numerically', function () {
    app()->setLocale('en');
    $now = Carbon::create(2026, 1, 1, 12, 0, 0, config('app.timezone'));
    Carbon::setTestNow($now);

    $actor = reportUserWithPermissions(['auth.sessions.view']);
    $target = User::factory()->create();

    foreach ([
        ['10.40.0.2', $now->copy()->subHours(4)],
        ['10.40.0.3', $now->copy()->subDay()],
        ['10.40.0.1', $now->copy()->subMinutes(11)],
    ] as [$ip, $loginAt]) {
        UserPresenceSession::query()->create([
            'user_id' => $target->id,
            'status' => UserPresenceService::StatusOnline,
            'ip_address' => $ip,
            'login_at' => $loginAt,
            'last_seen_at' => $now,
            'last_activity_at' => $now,
            'expires_at' => $now->copy()->addHour(),
        ]);
    }

    $response = $this->actingAs($actor)
        ->getJson(route('admin.auth-sessions.data', adminReportDataTablePayload('auth_sessions', 9, 'asc')));

    $response->assertOk();

    expect(collect($response->json('data'))->pluck('ip_address')->all())
        ->toBe(['10.40.0.1', '10.40.0.2', '10.40.0.3']);

    Carbon::setTestNow();
});

test('admin report datatables order every orderable column without sql errors', function () {
    app()->setLocale('en');
    $actor = reportUserWithPermissions([
        'activity.logs.view',
        'auth.logs.view',
        'auth.sessions.view',
    ]);
    $target = User::factory()->create(['name' => 'Report Target']);
    $company = Company::factory()->create(['name' => 'Sortable Company']);

    UserPresenceSession::query()->create([
        'user_id' => $target->id,
        'status' => UserPresenceService::StatusOnline,
        'ip_address' => '203.0.113.10',
        'browser_name' => 'Firefox',
        'os_name' => 'Linux',
        'device_type' => 'desktop',
        'login_at' => now()->subMinutes(15),
        'last_seen_at' => now(),
        'last_activity_at' => now(),
        'expires_at' => now()->addHour(),
    ]);

    AuthLog::query()->create([
        'user_id' => $target->id,
        'event' => 'login_success',
        'status' => 'success',
        'identifier' => 'target@example.test',
        'ip_address' => '203.0.113.20',
        'client_ip' => '198.51.100.20',
        'browser_name' => 'Chrome',
        'browser_version' => '151.0',
        'os_name' => 'Linux',
        'os_version' => '6.8',
        'device_type' => 'desktop',
        'country' => 'Egypt',
        'region' => 'Cairo',
        'city' => 'Cairo',
        'created_at' => now(),
    ]);

    Activity::query()->create([
        'public_id' => (string) Str::uuid(),
        'log_name' => 'core',
        'description' => 'companies.create.success',
        'event' => 'companies.create',
        'causer_type' => User::class,
        'causer_id' => $target->id,
        'company_id' => $company->id,
        'properties' => [
            'record' => [
                'type' => 'companies',
                'label' => 'Sortable Company',
                'doc_num' => 'Company-00077',
            ],
        ],
        'module' => 'core',
        'action' => 'companies.create',
        'status' => 'success',
        'ip_address' => '203.0.113.30',
        'created_at' => now(),
    ]);

    foreach ([
        'auth_sessions' => 'admin.auth-sessions.data',
        'auth_logs' => 'admin.auth-logs.data',
        'activity_logs' => 'admin.activity-logs.data',
    ] as $report => $routeName) {
        foreach (adminReportOrderableColumnIndexes($report) as $columnIndex) {
            foreach (['asc', 'desc'] as $direction) {
                $this->actingAs($actor)
                    ->getJson(route($routeName, adminReportDataTablePayload($report, $columnIndex, $direction)))
                    ->assertOk();
            }
        }
    }
});

test('report datatable global search includes visible ip user company and record fields', function () {
    app()->setLocale('en');
    $actor = reportUserWithPermissions([
        'activity.logs.view',
        'auth.logs.view',
        'auth.sessions.view',
    ]);
    $target = User::factory()->create(['name' => 'Searchable Target']);
    $company = Company::factory()->create(['name' => 'Needle Company']);

    UserPresenceSession::query()->create([
        'user_id' => $target->id,
        'status' => UserPresenceService::StatusOnline,
        'ip_address' => '198.51.100.41',
        'browser_name' => 'Firefox',
        'os_name' => 'Linux',
        'device_type' => 'desktop',
        'login_at' => now(),
        'last_seen_at' => now(),
        'last_activity_at' => now(),
    ]);

    AuthLog::query()->create([
        'user_id' => $target->id,
        'event' => 'login_success',
        'status' => 'success',
        'identifier' => 'needle@example.test',
        'ip_address' => '198.51.100.42',
        'client_ip' => '198.51.100.43',
        'created_at' => now(),
    ]);

    Activity::query()->create([
        'public_id' => (string) Str::uuid(),
        'log_name' => 'core',
        'description' => 'companies.update.success',
        'event' => 'companies.update',
        'causer_type' => User::class,
        'causer_id' => $target->id,
        'company_id' => $company->id,
        'properties' => [
            'record' => [
                'type' => 'companies',
                'label' => 'Needle Record',
                'doc_num' => 'Needle-0001',
            ],
        ],
        'module' => 'core',
        'action' => 'companies.update',
        'status' => 'success',
        'ip_address' => '198.51.100.44',
        'created_at' => now(),
    ]);

    $sessionByIp = $this->actingAs($actor)
        ->getJson(route('admin.auth-sessions.data', adminReportDataTablePayload('auth_sessions', 6, 'asc', '198.51.100.41')));
    $authByClientIp = $this->actingAs($actor)
        ->getJson(route('admin.auth-logs.data', adminReportDataTablePayload('auth_logs', 6, 'asc', '198.51.100.43')));
    $activityByCompany = $this->actingAs($actor)
        ->getJson(route('admin.activity-logs.data', adminReportDataTablePayload('activity_logs', 6, 'asc', 'Needle Company')));
    $activityByRecord = $this->actingAs($actor)
        ->getJson(route('admin.activity-logs.data', adminReportDataTablePayload('activity_logs', 5, 'asc', 'Needle-0001')));

    $sessionByIp->assertOk();
    $authByClientIp->assertOk();
    $activityByCompany->assertOk();
    $activityByRecord->assertOk();

    expect($sessionByIp->json('recordsFiltered'))
        ->toBe(1)
        ->and($authByClientIp->json('recordsFiltered'))->toBe(1)
        ->and($activityByCompany->json('recordsFiltered'))->toBe(1)
        ->and($activityByRecord->json('recordsFiltered'))->toBe(1)
        ->and($authByClientIp->json('data.0'))->not->toHaveKey('identifier_label')
        ->and($activityByCompany->json('data.0'))->not->toHaveKey('company_label');
});

test('active sessions action menu respects eligibility without exposing fingerprints', function () {
    $session = UserPresenceSession::query()->create([
        'session_fingerprint' => 'current-session-fingerprint',
        'status' => UserPresenceService::StatusOnline,
        'login_at' => now(),
        'last_seen_at' => now(),
        'last_activity_at' => now(),
    ]);

    $currentSessionActions = view('modules.auth.auth-sessions.partials.actions', [
        'session' => $session,
        'canViewDetails' => true,
        'canForceLogout' => true,
        'isCurrentSession' => true,
    ])->render();
    $otherSessionActions = view('modules.auth.auth-sessions.partials.actions', [
        'session' => $session,
        'canViewDetails' => true,
        'canForceLogout' => true,
        'isCurrentSession' => false,
    ])->render();
    $offlineSessionActions = view('modules.auth.auth-sessions.partials.actions', [
        'session' => tap($session->replicate(), function (UserPresenceSession $replica): void {
            $replica->status = UserPresenceService::StatusOffline;
        }),
        'canViewDetails' => true,
        'canForceLogout' => true,
        'isCurrentSession' => false,
    ])->render();
    $staleSessionActions = view('modules.auth.auth-sessions.partials.actions', [
        'session' => tap($session->replicate(), function (UserPresenceSession $replica): void {
            $replica->status = UserPresenceService::StatusOnline;
            $replica->last_seen_at = now()->subSeconds(121);
        }),
        'canViewDetails' => true,
        'canForceLogout' => true,
        'isCurrentSession' => false,
    ])->render();
    $noPermissionActions = view('modules.auth.auth-sessions.partials.actions', [
        'session' => $session,
        'canViewDetails' => true,
        'canForceLogout' => false,
        'isCurrentSession' => false,
    ])->render();

    expect($currentSessionActions)
        ->toContain('js-report-details')
        ->toContain(__('auth_sessions.messages.current_session_forbidden'))
        ->not->toContain('js-force-logout-session')
        ->not->toContain('current-session-fingerprint')
        ->and($otherSessionActions)
        ->toContain('js-force-logout-session')
        ->not->toContain('current-session-fingerprint')
        ->and($offlineSessionActions)
        ->toContain('js-report-details')
        ->not->toContain('js-force-logout-session')
        ->not->toContain(__('auth_sessions.messages.current_session_forbidden'))
        ->not->toContain('current-session-fingerprint')
        ->and($staleSessionActions)
        ->toContain('js-report-details')
        ->not->toContain('js-force-logout-session')
        ->not->toContain(__('auth_sessions.messages.current_session_forbidden'))
        ->not->toContain('current-session-fingerprint')
        ->and($noPermissionActions)
        ->toContain('js-report-details')
        ->not->toContain('js-force-logout-session')
        ->not->toContain(__('auth_sessions.messages.current_session_forbidden'))
        ->not->toContain('current-session-fingerprint');

    foreach ([UserPresenceService::StatusOnline, UserPresenceService::StatusIdle, UserPresenceService::StatusLocked] as $status) {
        $actions = view('modules.auth.auth-sessions.partials.actions', [
            'session' => tap($session->replicate(), function (UserPresenceSession $replica) use ($status): void {
                $replica->status = $status;
            }),
            'canViewDetails' => false,
            'canForceLogout' => true,
            'isCurrentSession' => false,
        ])->render();

        expect($actions)
            ->toContain('js-force-logout-session')
            ->not->toContain('current-session-fingerprint');
    }
});

test('active sessions datatable script keeps responsive columns and saved ordering', function () {
    $script = file_get_contents(public_path('assets/js/modules/Auth/auth-sessions.js'));
    $sharedScript = file_get_contents(public_path('assets/js/modules/Core/report-ui.js'));

    expect($script)
        ->toContain('const ReportUI = window.AppReportUI')
        ->toContain('const responsiveControlTarget = 0')
        ->toContain('const protectedColumns = [0, 4, -1]')
        ->toContain('autoWidth: false')
        ->toContain('stateSave: true')
        ->toContain('stateLoadParams: function (settings, data) { ReportUI.protectStateColumns(data, protectedColumns); }')
        ->toContain('responsive: { details: { type: \'inline\', target: responsiveControlTarget } }')
        ->toContain('const reportTableDom =')
        ->toContain('<"erp-datatable-scroll"rt>')
        ->toContain("const defaultOrder = [[4, 'asc'], [8, 'desc'], [7, 'desc']]")
        ->toContain('order: defaultOrder')
        ->toContain("{ data: 'branch_label', name: 'branch_label', orderable: true, searchable: true")
        ->toContain("{ data: 'financial_period_label', name: 'financial_period_label', orderable: true, searchable: true")
        ->toContain("{ data: 'ip_address', name: 'ip_address', orderable: true, searchable: true")
        ->toContain("{ data: 'duration_label', name: 'duration_label', orderable: true, searchable: false")
        ->toContain("{ data: 'actions', name: 'actions', orderable: false, searchable: false")
        ->toContain('dt-user-cell')
        ->toContain('width: \'11rem\'')
        ->toContain('responsivePriority: 1')
        ->toContain('responsivePriority: 50')
        ->toContain('ReportUI.bindFilters(table, $table, { defaultOrder: defaultOrder })')
        ->not->toContain('js-active-filter-badges')
        ->not->toContain('updateActiveFilterBadges')
        ->not->toContain('activeFilters')
        ->not->toContain('selectedControlValue')
        ->not->toContain('dateRangeLabel')
        ->not->toContain('dateRangeSeparator')
        ->not->toContain('messages.clearFilters')
        ->not->toContain('stateSave: false')
        ->not->toContain('table.order(');

    expect($sharedScript)
        ->toContain('resetFilters')
        ->toContain('initFilterToggles')
        ->toContain('data-report-filter-toggle')
        ->toContain('table.ajax.reload()');
});

test('activity logs datatable script clears saved state and keeps readable columns', function () {
    $script = file_get_contents(public_path('assets/js/modules/Auth/activity-logs.js'));
    $sharedScript = file_get_contents(public_path('assets/js/modules/Core/report-ui.js'));
    $stylesheet = file_get_contents(public_path('assets/css/user.css'));

    expect($script)
        ->toContain('const ReportUI = window.AppReportUI')
        ->toContain('const responsiveControlTarget = 1')
        ->toContain('const protectedColumns = [0, 1, 4, -1]')
        ->toContain("const allowedFilterNames = ['date_from', 'date_to', 'causer', 'area', 'action', 'status']")
        ->toContain("const removedFilterNames = ['event', 'method', 'module', 'subject_type', 'record', 'changes', 'technical_details', 'company', 'ip']")
        ->toContain('autoWidth: false')
        ->toContain('stateSave: true')
        ->toContain('stateLoadParams: function (settings, data) { ReportUI.sanitizeSavedState(data, removedFilterNames); ReportUI.protectStateColumns(data, protectedColumns); }')
        ->toContain('responsive: { details: { type: \'inline\', target: responsiveControlTarget } }')
        ->toContain('const reportTableDom =')
        ->toContain('<"erp-datatable-scroll"rt>')
        ->toContain('order: defaultOrder')
        ->toContain("{ data: 'record_label', name: 'record_label', orderable: true, searchable: true")
        ->toContain("{ data: 'summary_label', name: 'summary_label', orderable: false, searchable: true")
        ->toContain("{ data: 'actions', name: 'actions', orderable: false, searchable: false")
        ->toContain('dt-activity-log-user')
        ->toContain('dt-activity-log-activity')
        ->toContain('dt-activity-log-summary')
        ->toContain('width: \'12rem\'')
        ->toContain('width: \'15rem\'')
        ->toContain('responsivePriority: 1')
        ->toContain('responsivePriority: 30')
        ->toContain('ReportUI.bindFilters(table, $table, {')
        ->toContain('resetTableState: true')
        ->not->toContain('updateSummaryCards')
        ->not->toContain("data: 'changes_label'")
        ->not->toContain("data: 'company_label'")
        ->not->toContain("data: 'ip_address'")
        ->not->toContain('js-active-filter-badges')
        ->not->toContain('js-report-clear')
        ->not->toContain('updateActiveFilterBadges')
        ->not->toContain('activeFilters')
        ->not->toContain('dateRangeLabel')
        ->not->toContain('dateRangeSeparator')
        ->not->toContain('dt-ellipsis')
        ->not->toContain('stateSave: false');

    expect($sharedScript)
        ->toContain('resetTableState')
        ->toContain("table.search('')")
        ->toContain("table.columns().search('')")
        ->toContain('table.order(defaultOrder)')
        ->toContain("table.page('first')")
        ->toContain('js-report-reset')
        ->toContain("key.indexOf('DataTables_' + id + '_') === 0")
        ->toContain('table.ajax.reload()');

    expect($stylesheet)
        ->toContain('.activity-logs-report .report-table-card .erp-datatable')
        ->toContain('.admin-report-page .report-table-card .falcon-data-table')
        ->toContain('min-width: 72rem')
        ->toContain('display: block')
        ->toContain('.activity-logs-report .erp-datatable thead th.dt-actions')
        ->toContain('text-align: center !important')
        ->toContain('.activity-logs-report .erp-datatable tbody td.dt-activity-log-summary .activity-log-wrapped')
        ->toContain('-webkit-line-clamp: 3')
        ->toContain('.activity-logs-report .report-table-card div.dataTables_wrapper div.dataTables_filter');
});

test('auth logs datatable script keeps responsive columns and saved ordering', function () {
    $script = file_get_contents(public_path('assets/js/modules/Auth/auth-logs.js'));
    $sharedScript = file_get_contents(public_path('assets/js/modules/Core/report-ui.js'));

    expect($script)
        ->toContain('const ReportUI = window.AppReportUI')
        ->toContain('const responsiveControlTarget = 1')
        ->toContain('const protectedColumns = [0, 1, 3, -1]')
        ->toContain("const allowedFilterNames = ['date_from', 'date_to', 'user', 'event', 'status', 'ip', 'failure_reason']")
        ->toContain("'branch'")
        ->toContain("'financial_period'")
        ->toContain('autoWidth: false')
        ->toContain('stateSave: true')
        ->toContain('stateLoadParams: function (settings, data) { ReportUI.sanitizeSavedState(data, removedFilterNames); ReportUI.protectStateColumns(data, protectedColumns); }')
        ->toContain('responsive: { details: { type: \'inline\', target: responsiveControlTarget } }')
        ->toContain('const reportTableDom =')
        ->toContain('<"erp-datatable-scroll"rt>')
        ->toContain('order: defaultOrder')
        ->not->toContain("{ data: 'branch_label', name: 'branch_label', orderable: true, searchable: true")
        ->not->toContain("{ data: 'financial_period_label', name: 'financial_period_label', orderable: true, searchable: true")
        ->toContain("{ data: 'location_label', name: 'location_label', orderable: true, searchable: true")
        ->toContain("{ data: 'ip_label', name: 'ip_label', orderable: true, searchable: true")
        ->toContain("{ data: 'actions', name: 'actions', orderable: false, searchable: false")
        ->toContain('dt-auth-log-user')
        ->toContain('dt-auth-log-location')
        ->toContain('width: \'11rem\'')
        ->toContain('width: \'13rem\'')
        ->toContain('responsivePriority: 1')
        ->toContain('responsivePriority: 40')
        ->toContain('ReportUI.bindFilters(table, $table, {')
        ->toContain('resetTableState: true')
        ->not->toContain('updateSummaryCards')
        ->not->toContain('js-active-filter-badges')
        ->not->toContain('js-report-clear')
        ->not->toContain('updateActiveFilterBadges')
        ->not->toContain('activeFilters')
        ->not->toContain('dateRangeLabel')
        ->not->toContain('dateRangeSeparator')
        ->not->toContain('stateSave: false')
        ->not->toContain("data: 'identifier_label'")
        ->not->toContain("data: 'summary_label'");

    expect($sharedScript)
        ->toContain('js-report-reset')
        ->toContain('resetTableState')
        ->toContain("table.search('')")
        ->toContain("table.columns().search('')")
        ->toContain('table.order(defaultOrder)')
        ->toContain("table.page('first')")
        ->toContain("key.indexOf('DataTables_' + id + '_') === 0")
        ->toContain('table.ajax.reload()')
        ->toContain('js-report-refresh');
});

test('auth logs remember filter ignores empty and removed filters while device cell stays readable', function () {
    app()->setLocale('en');

    $actor = reportUserWithPermissions(['auth.logs.view']);

    AuthLog::query()->create([
        'user_id' => $actor->id,
        'event' => 'login_success',
        'status' => 'success',
        'identifier' => 'remembered@example.test',
        'ip_address' => '127.0.0.1',
        'browser_name' => 'Firefox',
        'browser_version' => '151.0',
        'os_name' => 'Linux',
        'device_type' => 'desktop',
        'country' => 'Egypt',
        'city' => 'Cairo',
        'guard' => 'web',
        'remember_me' => true,
        'created_at' => now(),
    ]);

    AuthLog::query()->create([
        'user_id' => $actor->id,
        'event' => 'login_failed_invalid_credentials',
        'status' => 'failed',
        'identifier' => 'not-remembered@example.test',
        'ip_address' => '127.0.0.2',
        'browser_name' => 'Chrome',
        'os_name' => 'Windows',
        'device_type' => 'mobile',
        'country' => 'United States',
        'city' => 'Austin',
        'guard' => 'web',
        'remember_me' => false,
        'created_at' => now()->subMinute(),
    ]);

    AuthLog::query()->create([
        'user_id' => $actor->id,
        'event' => 'logout_success',
        'status' => 'success',
        'identifier' => 'unknown@example.test',
        'ip_address' => '127.0.0.3',
        'remember_me' => null,
        'created_at' => now()->subMinutes(2),
    ]);

    $unfiltered = $this->actingAs($actor)
        ->getJson(route('admin.auth-logs.data', [
            'remember_me' => '',
            'country' => 'United States',
            'city' => 'Austin',
            'guard' => 'api',
            'browser_name' => 'Chrome',
            'os_name' => 'Windows',
            'device_type' => 'mobile',
        ]));

    $remembered = $this->actingAs($actor)
        ->getJson(route('admin.auth-logs.data', ['remember_me' => '1']));

    $notRemembered = $this->actingAs($actor)
        ->getJson(route('admin.auth-logs.data', ['remember_me' => '0']));

    $unfiltered->assertOk();
    $remembered->assertOk();
    $notRemembered->assertOk();

    expect($unfiltered->json('recordsFiltered'))
        ->toBe(3)
        ->and($remembered->json('recordsFiltered'))->toBe(1)
        ->and($notRemembered->json('recordsFiltered'))->toBe(1)
        ->and($remembered->json('data.0.device_label'))
        ->toContain('auth-log-device')
        ->toContain('Firefox 151.0')
        ->toContain('Linux &middot; Computer')
        ->not->toContain('dt-ellipsis-content')
        ->not->toContain('user_agent')
        ->and(app(AuthLogReport::class)->deviceTypeLabel('desktop'))->toBe('Computer');

    app()->setLocale('ar');

    expect(app(AuthLogReport::class)->deviceTypeLabel('desktop'))->toBe('حاسوب');
});

test('active sessions report ui translations exist in english and arabic', function () {
    app()->setLocale('en');

    expect(__('auth_sessions.actions.refresh'))
        ->toBe('Refresh')
        ->and(__('auth_sessions.actions.end_session'))->toBe('End Session')
        ->and(__('auth_sessions.fields.duration'))->toBe('Session duration')
        ->and(__('auth_sessions.fields.offline_reason'))->toBe('Logout reason')
        ->and(__('auth_sessions.filters.status'))->toBe('Presence');

    app()->setLocale('ar');

    expect(__('auth_sessions.actions.refresh'))
        ->toBe('تحديث')
        ->and(__('auth_sessions.actions.end_session'))->toBe('إنهاء الجلسة')
        ->and(__('auth_sessions.fields.duration'))->toBe('مدة الجلسة')
        ->and(__('auth_sessions.fields.offline_reason'))->toBe('سبب الخروج')
        ->and(__('auth_sessions.filters.status'))->toBe('حالة التواجد');
});

test('activity log report requires permission and returns sanitized human rows', function () {
    app()->setLocale('en');

    $actor = reportUserWithPermissions(['activity.logs.view', 'activity.logs.details']);

    Activity::query()->create([
        'public_id' => (string) Str::uuid(),
        'log_name' => 'core',
        'description' => 'core.companies.create.success',
        'event' => 'companies.create',
        'causer_type' => User::class,
        'causer_id' => $actor->id,
        'properties' => [
            'doc_num' => 'Company-00001',
            'password' => 'super-secret',
            'internal_id' => 15,
        ],
        'module' => 'core',
        'action' => 'companies.create',
        'status' => 'success',
        'ip_address' => '127.0.0.1',
        'method' => 'POST',
        'url' => 'http://localhost/admin/companies',
    ]);

    $this->actingAs(User::factory()->create())
        ->get(route('admin.activity-logs.index'))
        ->assertForbidden();

    $response = $this->actingAs($actor)
        ->getJson(route('admin.activity-logs.data'));

    $response->assertOk();
    $row = implode(' ', array_map('strval', $response->json('data.0')));

    expect($row)
        ->toContain('Company created')
        ->toContain('Completed')
        ->toContain('Created record')
        ->not->toContain('super-secret')
        ->not->toContain('internal_id')
        ->not->toContain('companies.create');
});

test('activity log report filters and exports use readable values', function () {
    app()->setLocale('en');

    $actor = reportUserWithPermissions([
        'activity.logs.view',
        'activity.logs.details',
        'activity.logs.export',
    ]);
    $company = Company::factory()->create(['name' => 'Client Company']);
    $dateFormat = app(DateFormatService::class);

    Activity::query()->create([
        'public_id' => (string) Str::uuid(),
        'log_name' => 'auth',
        'description' => 'language.changed.success',
        'event' => 'language.changed',
        'causer_type' => User::class,
        'causer_id' => $actor->id,
        'properties' => [
            'user_doc_num' => $actor->doc_num,
            'username' => $actor->username,
            'old_locale' => 'ar',
            'new_locale' => 'en',
            'token' => 'hidden',
        ],
        'module' => 'auth',
        'action' => 'language.changed',
        'status' => 'success',
        'ip_address' => '127.0.0.1',
        'method' => 'GET',
        'url' => 'http://localhost/lang/en',
        'created_at' => now()->subDay(),
    ]);

    Activity::query()->create([
        'public_id' => (string) Str::uuid(),
        'log_name' => 'core',
        'description' => 'companies.delete_blocked.blocked',
        'event' => 'companies.delete_blocked',
        'causer_type' => User::class,
        'causer_id' => $actor->id,
        'properties' => [
            'doc_num' => 'Company-00009',
            'company_name' => 'Blocked Company',
            'reason' => 'related_data',
        ],
        'module' => 'core',
        'action' => 'companies.delete_blocked',
        'status' => 'blocked',
        'company_id' => $company->id,
        'ip_address' => '127.0.0.2',
        'method' => 'DELETE',
        'url' => 'http://localhost/admin/companies/Company-00009',
        'created_at' => now(),
    ]);

    $users = $this->actingAs($actor)
        ->getJson(route('admin.activity-logs.filter-options.users', ['q' => $actor->doc_num]));

    $users->assertOk()
        ->assertJsonFragment(['id' => $actor->doc_num]);

    $companies = $this->actingAs($actor)
        ->getJson(route('admin.activity-logs.filter-options.companies', ['q' => 'Client']));

    $companies->assertOk()
        ->assertJsonFragment(['id' => $company->doc_num]);

    $date = $dateFormat->formatDate(now());
    $rows = $this->actingAs($actor)
        ->getJson(route('admin.activity-logs.data', [
            'date_from' => $date,
            'date_to' => $date,
            'causer' => $actor->doc_num,
            'company' => $company->doc_num,
            'action' => 'companies.delete_blocked',
            'status' => 'blocked',
        ]));

    $rows->assertOk()
        ->assertJsonPath('recordsTotal', 1);

    $row = implode(' ', array_map('strval', $rows->json('data.0')));

    expect($row)
        ->toContain('Company deletion blocked')
        ->toContain('Blocked')
        ->toContain('Blocked Company')
        ->not->toContain('companies.delete_blocked')
        ->not->toContain('related_data')
        ->not->toContain('"id"');

    $details = $this->actingAs($actor)
        ->getJson(route('admin.activity-logs.details', Activity::query()->where('action', 'language.changed')->value('public_id')));

    $details->assertOk();

    expect($details->getContent())
        ->toContain('Language changed from')
        ->not->toContain('hidden');

    $export = $this->actingAs($actor)
        ->get(route('admin.activity-logs.export.csv', ['action' => 'language.changed']));

    $export->assertOk();

    $mappedExportRow = app(ActivityLogReport::class)
        ->map(Activity::query()->where('action', 'language.changed')->firstOrFail());

    expect(implode(' ', $mappedExportRow))
        ->toContain('Language changed')
        ->toContain('Language settings')
        ->not->toContain('language.changed')
        ->not->toContain('hidden');
});

test('activity log humanizer builds useful area activity result and changes labels', function () {
    app()->setLocale('en');

    $actor = reportUserWithPermissions(['activity.logs.view']);

    $update = Activity::query()->create([
        'public_id' => (string) Str::uuid(),
        'log_name' => 'auth',
        'description' => 'roles.update.success',
        'event' => 'roles.update',
        'causer_type' => User::class,
        'causer_id' => $actor->id,
        'properties' => [
            'doc_num' => 'Role-00001',
            'role_name' => 'Admin',
            'old_name' => 'Old Admin',
            'new_name' => 'Admin',
            'changed_fields' => ['name'],
            'internal_id' => 99,
        ],
        'module' => 'auth',
        'action' => 'roles.update',
        'status' => 'success',
        'created_at' => now(),
    ]);

    $create = Activity::query()->create([
        'public_id' => (string) Str::uuid(),
        'log_name' => 'core',
        'description' => 'companies.create.success',
        'event' => 'companies.create',
        'causer_type' => User::class,
        'causer_id' => $actor->id,
        'properties' => [
            'doc_num' => 'Company-00001',
            'company_name' => 'Client Company',
        ],
        'module' => 'core',
        'action' => 'companies.create',
        'status' => 'success',
        'created_at' => now(),
    ]);

    $hr = Activity::query()->create([
        'public_id' => (string) Str::uuid(),
        'log_name' => 'hr',
        'description' => 'hr.countries.restore.success',
        'event' => 'hr.countries.restore',
        'causer_type' => User::class,
        'causer_id' => $actor->id,
        'properties' => [
            'doc_num' => 'Country-00001',
            'name' => 'Egypt',
        ],
        'module' => 'hr',
        'action' => 'hr.countries.restore',
        'status' => 'success',
        'created_at' => now(),
    ]);

    $report = app(ActivityLogReport::class);

    expect(implode(' ', $report->map($update)))
        ->toContain('User Groups')
        ->toContain('User group updated')
        ->toContain('Completed')
        ->toContain('Name changed from "Old Admin" to "Admin"')
        ->not->toContain('No readable details')
        ->not->toContain('internal_id')
        ->and(implode(' ', $report->map($create)))
        ->toContain('Companies')
        ->toContain('Created record: Company "Client Company" (Company-00001)')
        ->not->toContain('No readable details')
        ->and(implode(' ', $report->map($hr)))
        ->toContain('Human Resources / Countries')
        ->toContain('Country restored')
        ->toContain('Restored record');
});

test('ActivityLogHumanizer supports canonical crud properties and legacy rows', function () {
    app()->setLocale('en');

    $actor = reportUserWithPermissions(['activity.logs.view']);

    $update = Activity::query()->create([
        'public_id' => (string) Str::uuid(),
        'log_name' => 'core',
        'description' => 'companies.update.success',
        'event' => 'companies.update',
        'causer_type' => User::class,
        'causer_id' => $actor->id,
        'properties' => ActivityLogProperties::crudUpdated('companies', 'Short Coded', 'Company-00001', [
            'name' => ['old' => 'Old Company', 'new' => 'Short Coded'],
            'status' => ['old' => 'inactive', 'new' => 'active'],
            'is_main' => ['old' => false, 'new' => true],
        ], ['submit_action' => 'save_edit']),
        'module' => 'core',
        'action' => 'companies.update',
        'status' => 'success',
        'created_at' => now(),
    ]);

    $bulkDelete = Activity::query()->create([
        'public_id' => (string) Str::uuid(),
        'log_name' => 'auth',
        'description' => 'roles.bulk_delete.success',
        'event' => 'roles.bulk_delete',
        'causer_type' => User::class,
        'causer_id' => $actor->id,
        'properties' => ActivityLogProperties::bulkDeleted('roles', 2, ['Role-00001', 'Role-00002']),
        'module' => 'auth',
        'action' => 'roles.bulk_delete',
        'status' => 'success',
        'created_at' => now(),
    ]);

    $legacy = Activity::query()->create([
        'public_id' => (string) Str::uuid(),
        'log_name' => 'auth',
        'description' => 'users.restore.success',
        'event' => 'users.restore',
        'causer_type' => User::class,
        'causer_id' => $actor->id,
        'properties' => [
            'doc_num' => 'User-00001',
            'name' => 'Legacy User',
        ],
        'module' => 'auth',
        'action' => 'users.restore',
        'status' => 'success',
        'created_at' => now(),
    ]);

    $report = app(ActivityLogReport::class);

    expect(implode(' ', $report->map($update)))
        ->toContain('Company "Short Coded" (Company-00001)')
        ->toContain('Company name changed from "Old Company" to "Short Coded"')
        ->toContain('Status changed from "Inactive" to "Active"')
        ->toContain('Main company changed from "No" to "Yes"')
        ->not->toContain('No readable details')
        ->not->toContain('submit_action')
        ->not->toContain('save_edit')
        ->and(implode(' ', $report->map($bulkDelete)))
        ->toContain('Deleted 2 records: Role-00001, Role-00002')
        ->not->toContain('bulk_doc_nums')
        ->and(implode(' ', $report->map($legacy)))
        ->toContain('Restored record')
        ->toContain('Legacy User')
        ->not->toContain('No readable details');
});

test('ActivityLogHumanizer details expose structured changes and record context JSON', function () {
    app()->setLocale('en');

    $actor = reportUserWithPermissions(['activity.logs.view', 'activity.logs.details']);

    $canonical = Activity::query()->create([
        'public_id' => (string) Str::uuid(),
        'log_name' => 'core',
        'description' => 'companies.update.success',
        'event' => 'companies.update',
        'causer_type' => User::class,
        'causer_id' => $actor->id,
        'properties' => ActivityLogProperties::crudUpdated('companies', 'Short Coded', 'Company-00001', [
            'name' => ['old' => 'Old Name', 'new' => 'Short Coded'],
            'status' => ['old' => 'active', 'new' => 'inactive'],
        ], ['submit_action' => 'save_edit']),
        'module' => 'core',
        'action' => 'companies.update',
        'status' => 'success',
        'created_at' => now(),
    ]);
    $flat = Activity::query()->create([
        'public_id' => (string) Str::uuid(),
        'log_name' => 'auth',
        'description' => 'roles.update.success',
        'event' => 'roles.update',
        'causer_type' => User::class,
        'causer_id' => $actor->id,
        'properties' => [
            'doc_num' => 'Role-00001',
            'role_name' => 'Admin',
            'old_name' => 'Old Admin',
            'new_name' => 'Admin',
            'old_locale' => 'en',
            'new_locale' => 'ar',
        ],
        'module' => 'auth',
        'action' => 'roles.update',
        'status' => 'success',
        'created_at' => now(),
    ]);
    $changedFields = Activity::query()->create([
        'public_id' => (string) Str::uuid(),
        'log_name' => 'auth',
        'description' => 'users.update.success',
        'event' => 'users.update',
        'causer_type' => User::class,
        'causer_id' => $actor->id,
        'properties' => [
            'doc_num' => 'User-00001',
            'name' => 'User Name',
            'changed_fields' => ['status'],
            'old_status' => 'active',
            'new_status' => 'blocked',
        ],
        'module' => 'auth',
        'action' => 'users.update',
        'status' => 'success',
        'created_at' => now(),
    ]);
    $spatieStyle = Activity::query()->create([
        'public_id' => (string) Str::uuid(),
        'log_name' => 'core',
        'description' => 'companies.update.success',
        'event' => 'companies.update',
        'causer_type' => User::class,
        'causer_id' => $actor->id,
        'properties' => [
            'doc_num' => 'Company-00002',
            'company_name' => 'Second Company',
            'old' => ['name' => 'Old Company'],
            'attributes' => ['name' => 'Second Company'],
        ],
        'module' => 'core',
        'action' => 'companies.update',
        'status' => 'success',
        'created_at' => now(),
    ]);

    $report = app(ActivityLogReport::class);
    $technicalItem = function (Activity $activity, string $label) use ($report): string {
        $section = collect($report->details($activity)['sections'])
            ->firstWhere('title', __('activity_logs.fields.raw_properties'));

        return (string) data_get(
            collect(data_get($section, 'items', []))->firstWhere('label', $label),
            'value'
        );
    };

    $canonicalChanges = $technicalItem($canonical, __('activity_logs.fields.changes_json'));
    $canonicalRecord = $technicalItem($canonical, __('activity_logs.fields.record_json'));
    $flatChanges = $technicalItem($flat, __('activity_logs.fields.changes_json'));
    $changedFieldsJson = $technicalItem($changedFields, __('activity_logs.fields.changes_json'));
    $spatieChanges = $technicalItem($spatieStyle, __('activity_logs.fields.changes_json'));

    expect($canonicalChanges)
        ->toContain('"field": "name"')
        ->toContain('"label": "Company name"')
        ->toContain('"old": "Old Name"')
        ->toContain('"new": "Short Coded"')
        ->toContain('"old_raw": "Old Name"')
        ->toContain('"new_raw": "Short Coded"')
        ->toContain('"field": "status"')
        ->toContain('"old": "Active"')
        ->toContain('"new": "Inactive"')
        ->toContain('"old_raw": "active"')
        ->toContain('"new_raw": "inactive"')
        ->and($canonicalRecord)
        ->toContain('"record"')
        ->toContain('"type": "companies"')
        ->toContain('"label": "Short Coded"')
        ->toContain('"doc_num": "Company-00001"')
        ->toContain('"meta"')
        ->toContain('"submit_action": "save_edit"')
        ->and($flatChanges)
        ->toContain('"field": "name"')
        ->toContain('"old": "Old Admin"')
        ->toContain('"new": "Admin"')
        ->toContain('"field": "locale"')
        ->toContain('"old_raw": "en"')
        ->toContain('"new_raw": "ar"')
        ->and($changedFieldsJson)
        ->toContain('"field": "status"')
        ->toContain('"old": "Active"')
        ->toContain('"new": "Blocked"')
        ->and($spatieChanges)
        ->toContain('"field": "name"')
        ->toContain('"old": "Old Company"')
        ->toContain('"new": "Second Company"');

    app()->setLocale('ar');

    $arabicChanges = $technicalItem($canonical, __('activity_logs.fields.changes_json'));

    expect($arabicChanges)
        ->toContain('"label": "الحالة"')
        ->toContain('"old": "نشط"')
        ->toContain('"new": "غير نشط"')
        ->toContain('"old_raw": "active"')
        ->toContain('"new_raw": "inactive"');
});

test('ActivityLogHumanizer report fallback uses sanitized technical JSON', function () {
    app()->setLocale('en');

    $actor = reportUserWithPermissions([
        'activity.logs.view',
        'activity.logs.details',
        'activity.logs.export',
        'activity.logs.pdf',
    ]);

    $activity = Activity::query()->create([
        'public_id' => (string) Str::uuid(),
        'log_name' => 'custom',
        'description' => 'custom.audit',
        'event' => 'custom.audit',
        'causer_type' => User::class,
        'causer_id' => $actor->id,
        'properties' => [
            'safe_context' => [
                'doc_num' => 'Safe-0001',
                'name' => 'Readable payload',
                'status' => 'active',
                'arabic_note' => 'مرحبا',
            ],
            'submit_action' => 'save_edit',
            'password' => 'plain-password',
            'password_confirmation' => 'plain-password',
            'current_password' => 'plain-password',
            '_token' => 'csrf-token',
            'remember_token' => 'remember-token',
            'reset_token' => 'reset-token',
            'api_token' => 'api-token',
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'raw_session_id' => 'raw-session',
            'cookie' => 'laravel-session-cookie',
            'authorization' => 'Bearer unsafe-token',
            'internal_id' => 55,
            'user_id' => 99,
            'model' => [
                'id' => 1,
                'doc_num' => 'Model-0001',
                'name' => 'Model dump',
            ],
        ],
        'module' => 'custom',
        'action' => 'custom.audit',
        'status' => 'success',
        'created_at' => now(),
    ]);

    $report = app(ActivityLogReport::class);
    $mappedRow = implode(' ', $report->map($activity));

    expect($mappedRow)
        ->not->toContain('No additional details.')
        ->not->toContain('No readable details')
        ->not->toContain('Technical details available')
        ->not->toContain('Technical details')
        ->not->toContain('safe_context')
        ->not->toContain('Safe-0001')
        ->not->toContain('مرحبا')
        ->not->toContain('submit_action')
        ->not->toContain('"password":"[redacted]"')
        ->not->toContain('"access_token":"[redacted]"')
        ->not->toContain('plain-password')
        ->not->toContain('csrf-token')
        ->not->toContain('remember-token')
        ->not->toContain('api-token')
        ->not->toContain('unsafe-token')
        ->not->toContain('raw-session')
        ->not->toContain('internal_id')
        ->not->toContain('user_id')
        ->not->toContain('Model dump');

    $details = $report->details($activity);
    $technicalSection = collect($details['sections'])
        ->firstWhere('title', __('activity_logs.fields.raw_properties'));
    $encodedDetails = json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $technicalItems = collect(data_get($technicalSection, 'items', []));
    $changesJsonItem = $technicalItems->firstWhere('label', __('activity_logs.fields.changes_json'));
    $recordJsonItem = $technicalItems->firstWhere('label', __('activity_logs.fields.record_json'));
    $originalPropertiesItem = $technicalItems->firstWhere('label', __('activity_logs.fields.original_properties_json'));

    expect($technicalSection)
        ->not->toBeNull()
        ->and(data_get($technicalSection, 'collapsed'))->toBeTrue()
        ->and(data_get($changesJsonItem, 'value'))->toBe('No structured changes detected.')
        ->and(data_get($recordJsonItem, 'type'))->toBe('json')
        ->and((string) data_get($recordJsonItem, 'value'))->toContain('"meta"')
        ->and((string) data_get($recordJsonItem, 'value'))->toContain('"submit_action": "save_edit"')
        ->and(data_get($originalPropertiesItem, 'type'))->toBe('json')
        ->and((string) data_get($originalPropertiesItem, 'value'))->toContain('"safe_context"')
        ->and((string) data_get($originalPropertiesItem, 'value'))->toContain('"Safe-0001"')
        ->and((string) data_get($originalPropertiesItem, 'value'))->toContain('"arabic_note": "مرحبا"')
        ->and((string) data_get($originalPropertiesItem, 'value'))->toContain('"password": "[redacted]"')
        ->and((string) data_get($originalPropertiesItem, 'value'))->toContain('"access_token": "[redacted]"')
        ->and($encodedDetails)->not->toContain('plain-password')
        ->and($encodedDetails)->not->toContain('raw-session');

    $detailsResponse = $this->actingAs($actor)
        ->getJson(route('admin.activity-logs.details', $activity->public_id));
    $detailsResponse->assertOk();

    $responseTechnicalSection = collect($detailsResponse->json('data.sections'))
        ->firstWhere('title', __('activity_logs.fields.raw_properties'));
    $responseTechnicalItems = collect(data_get($responseTechnicalSection, 'items', []));
    $responseOriginalJson = (string) data_get(
        $responseTechnicalItems->firstWhere('label', __('activity_logs.fields.original_properties_json')),
        'value'
    );

    expect($responseOriginalJson)
        ->toContain('"safe_context"')
        ->toContain('"Safe-0001"')
        ->toContain('"password": "[redacted]"')
        ->not->toContain('plain-password')
        ->not->toContain('raw-session');

    $response = $this->actingAs($actor)
        ->getJson(route('admin.activity-logs.data', ['action' => 'custom.audit']));

    $response->assertOk();

    expect((string) $response->json('data.0.summary_label'))
        ->toContain('Custom Audit')
        ->not->toContain('safe_context')
        ->not->toContain('Safe-0001')
        ->not->toContain('plain-password');

    $empty = Activity::query()->create([
        'public_id' => (string) Str::uuid(),
        'log_name' => 'custom',
        'description' => 'custom.empty',
        'event' => 'custom.empty',
        'causer_type' => User::class,
        'causer_id' => $actor->id,
        'properties' => [],
        'module' => 'custom',
        'action' => 'custom.empty',
        'status' => 'success',
        'created_at' => now(),
    ]);

    $emptyMappedRow = $report->map($empty);

    expect($emptyMappedRow[9])
        ->toBe('')
        ->and(implode(' ', $emptyMappedRow))
        ->not->toContain('No additional details.')
        ->not->toContain('No readable details');

    expect(json_encode($report->details($empty), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
        ->toContain('No technical details.');

    $long = Activity::query()->create([
        'public_id' => (string) Str::uuid(),
        'log_name' => 'custom',
        'description' => 'custom.long',
        'event' => 'custom.long',
        'causer_type' => User::class,
        'causer_id' => $actor->id,
        'properties' => [
            'safe_context' => str_repeat('A', 5000),
            'access_token' => 'hidden-token',
        ],
        'module' => 'custom',
        'action' => 'custom.long',
        'status' => 'success',
        'created_at' => now(),
    ]);

    $pdfRow = implode(' ', $report->pdfMap($long));
    $exportHeadings = (new ActivityLogsExport($report))->headings();
    $exportRow = implode(' ', (new ActivityLogsExport($report))->map($long));

    expect($pdfRow)
        ->not->toContain('No additional details.')
        ->not->toContain('Technical details available')
        ->not->toContain('Technical details truncated for PDF.')
        ->not->toContain('Technical details')
        ->not->toContain('safe_context')
        ->not->toContain(str_repeat('A', 100))
        ->not->toContain('hidden-token')
        ->and(mb_strlen($pdfRow))->toBeLessThan(2500)
        ->and($report->headings())->toContain('Changes')
        ->and($report->headings())->not->toContain('Technical details')
        ->and($report->headings())->not->toContain('Original JSON')
        ->and($exportHeadings)->not->toContain('Technical details')
        ->and($exportHeadings)->not->toContain('Original JSON')
        ->and($exportRow)->not->toContain('No additional details.')
        ->and($exportRow)->not->toContain('Technical details')
        ->and($exportRow)->not->toContain('safe_context')
        ->and($exportRow)->not->toContain('hidden-token');
});

test('report empty optional values render cleanly across tables details and exports', function () {
    app()->setLocale('en');

    $actor = reportUserWithPermissions([
        'activity.logs.view',
        'activity.logs.details',
        'auth.logs.view',
        'auth.logs.details',
        'auth.sessions.view',
        'auth.sessions.details',
    ]);

    $activity = Activity::query()->create([
        'public_id' => (string) Str::uuid(),
        'log_name' => 'custom',
        'description' => 'custom.empty_optional',
        'event' => 'custom.empty_optional',
        'properties' => [],
        'module' => 'custom',
        'action' => 'custom.empty_optional',
        'status' => 'success',
        'ip_address' => '-',
        'created_at' => now(),
    ]);
    $authLog = AuthLog::query()->create([
        'user_id' => $actor->id,
        'event' => 'login_success',
        'status' => 'success',
        'identifier' => '-',
        'ip_address' => '-',
        'client_ip' => 'N/A',
        'browser_name' => '-',
        'os_name' => 'Not available',
        'device_type' => 'غير متاح',
        'city' => 'لا يوجد',
        'country' => '—',
        'failure_reason' => 'N/A',
        'guard' => '-',
        'platform' => 'Not available',
        'remember_me' => null,
        'created_at' => now(),
    ]);
    $session = UserPresenceSession::query()->create([
        'user_id' => $actor->id,
        'status' => UserPresenceService::StatusOnline,
        'ip_address' => '-',
        'browser_name' => 'N/A',
        'os_name' => 'Not available',
        'device_type' => 'غير متاح',
        'offline_reason' => 'لا يوجد',
        'login_at' => now(),
        'last_seen_at' => now(),
        'last_activity_at' => now(),
    ]);

    $activityTable = $this->actingAs($actor)
        ->getJson(route('admin.activity-logs.data', ['action' => 'custom.empty_optional']));
    $authTable = $this->actingAs($actor)
        ->getJson(route('admin.auth-logs.data', ['event' => 'login_success']));

    $activityTable->assertOk();
    $authTable->assertOk();

    expect($activityTable->json('data.0.causer_label'))
        ->toContain('&mdash;')
        ->and($activityTable->json('data.0.record_label'))->toContain('&mdash;')
        ->and($activityTable->json('data.0'))->not->toHaveKey('company_label')
        ->and($activityTable->json('data.0.ip_address'))->toBeNull()
        ->and($activityTable->json('data.0.changes_label'))->toBeNull()
        ->and($activityTable->json('data.0.result_label'))->toContain('Completed')
        ->and($authTable->json('data.0.device_label'))->toBe('')
        ->and($authTable->json('data.0.location_label'))->toBe('')
        ->and($authTable->json('data.0.ip_label'))->toBe('')
        ->and($authTable->json('data.0.status_label'))->toContain('Completed');

    $authDetails = app(AuthLogReport::class)->details($authLog);
    $securityDetails = collect(data_get(collect($authDetails['sections'])->firstWhere('title', __('auth_logs.fields.security_context')), 'items', []));
    $deviceDetails = collect(data_get(collect($authDetails['sections'])->firstWhere('title', __('auth_logs.fields.device_details')), 'items', []));
    $locationDetails = collect(data_get(collect($authDetails['sections'])->firstWhere('title', __('auth_logs.fields.location_details')), 'items', []));

    expect(data_get($securityDetails->firstWhere('label', __('auth_logs.fields.failure_reason')), 'value'))
        ->toBe('')
        ->and(data_get($securityDetails->firstWhere('label', __('auth_logs.fields.remember_me')), 'value'))->toBe('')
        ->and(data_get($deviceDetails->firstWhere('label', __('auth_logs.fields.device')), 'value'))->toBe('')
        ->and(data_get($locationDetails->firstWhere('label', __('auth_logs.fields.location')), 'value'))->toBe('')
        ->and(data_get($locationDetails->firstWhere('label', __('auth_logs.fields.map_link')), 'value'))->toBe('');

    $activityExportRow = app(ActivityLogReport::class)->map($activity);
    $authExportRow = app(AuthLogReport::class)->map($authLog);
    $sessionExportRow = app(AuthSessionReport::class)->map($session);

    expect($activityExportRow[1])
        ->toBe('')
        ->and($activityExportRow[5])->toBe('')
        ->and($activityExportRow[6])->toBe('')
        ->and($activityExportRow[7])->toBe('')
        ->and($activityExportRow[9])->toBe('')
        ->and($authExportRow[5])->toBe('')
        ->and($authExportRow[6])->toBe('')
        ->and($authExportRow[7])->toBe('')
        ->and($authExportRow[8])->toBe('')
        ->and($authExportRow)->toHaveCount(10)
        ->and($sessionExportRow[1])->toBe('Not selected')
        ->and($sessionExportRow[2])->toBe('Not selected')
        ->and($sessionExportRow[5])->toBe('')
        ->and($sessionExportRow[6])->toBe('')
        ->and($sessionExportRow)->toHaveCount(10)
        ->and($sessionExportRow[4])->toBe('Online');

    $pdfBody = view('reports.auth-logs', [
        'headings' => app(AuthLogReport::class)->headings(),
        'rows' => [$authExportRow],
    ])->render();

    expect($pdfBody)
        ->not->toContain('Not available')
        ->not->toContain('غير متاح')
        ->not->toContain('N/A')
        ->not->toContain('> - <');
});

test('auth log details and exports are permission protected and sanitized', function () {
    $actor = reportUserWithPermissions(['auth.logs.view', 'auth.logs.details', 'auth.logs.export', 'auth.logs.pdf']);

    $authLog = AuthLog::query()->create([
        'user_id' => $actor->id,
        'event' => 'login_failed',
        'status' => 'blocked',
        'identifier' => 'admin@example.test',
        'ip_address' => '127.0.0.1',
        'browser_name' => 'Firefox',
        'os_name' => 'Linux',
        'device_type' => 'desktop',
        'payload_summary' => ['fields' => ['login']],
        'context' => [
            'password' => 'secret',
            'safe_reason' => 'invalid_credentials',
        ],
    ]);

    $this->actingAs(User::factory()->create())
        ->get(route('admin.auth-logs.export.excel'))
        ->assertForbidden();

    $details = $this->actingAs($actor)
        ->getJson(route('admin.auth-logs.details', $authLog->public_id));

    $details->assertOk();

    expect($details->getContent())
        ->toContain('invalid_credentials')
        ->not->toContain('secret');

    $this->actingAs($actor)
        ->get(route('admin.auth-logs.export.csv'))
        ->assertOk();

    $this->actingAs($actor)
        ->get(route('admin.auth-logs.export.pdf'))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf')
        ->assertHeader('content-disposition', 'inline; filename="auth-logs-report.pdf"');
});

test('auth log report shows valid captured coordinates as a safe google maps link', function () {
    app()->setLocale('en');

    $actor = reportUserWithPermissions([
        'auth.logs.view',
        'auth.logs.details',
        'auth.logs.export',
    ]);

    $authLog = AuthLog::query()->create([
        'user_id' => $actor->id,
        'event' => 'login_success',
        'status' => 'success',
        'identifier' => 'admin@example.test',
        'ip_address' => '127.0.0.1',
        'city' => 'Cairo',
        'country' => 'Egypt',
        'latitude' => 30.04442,
        'longitude' => 31.235712,
        'location_accuracy' => 25,
        'geo_source' => 'html5',
        'context' => [
            'password' => 'hidden-password',
            'safe_context' => 'kept',
        ],
        'created_at' => now(),
    ]);
    $invalid = AuthLog::query()->create([
        'user_id' => $actor->id,
        'event' => 'login_success',
        'status' => 'success',
        'latitude' => 91,
        'longitude' => 31.235712,
        'created_at' => now(),
    ]);
    $denied = AuthLog::query()->create([
        'user_id' => $actor->id,
        'event' => 'login_failed_invalid_credentials',
        'status' => 'failed',
        'geo_source' => 'denied',
        'created_at' => now(),
    ]);
    $unavailable = AuthLog::query()->create([
        'user_id' => $actor->id,
        'event' => 'login_failed_invalid_credentials',
        'status' => 'failed',
        'location_context' => ['source' => 'html5', 'unavailable' => true],
        'created_at' => now(),
    ]);

    $report = app(AuthLogReport::class);
    $mapUrl = 'https://www.google.com/maps?q=30.0444200,31.2357120';

    expect($report->mapUrl($authLog->latitude, $authLog->longitude))
        ->toBe($mapUrl)
        ->and($report->mapUrl($invalid->latitude, $invalid->longitude))
        ->toBeNull()
        ->and($report->location($denied))
        ->toBe('Location denied')
        ->and($report->location($unavailable))
        ->toBe('Location unavailable')
        ->and($report->row($authLog))
        ->toMatchArray([
            'location' => 'Cairo, Egypt',
            'location_url' => $mapUrl,
            'location_accuracy_label' => 'Accuracy: 25 m',
            'location_source_label' => 'HTML5 Geolocation',
        ]);

    $table = $this->actingAs($actor)
        ->getJson(route('admin.auth-logs.data'));

    $table->assertOk();
    $locationCell = collect($table->json('data'))
        ->pluck('location_label')
        ->first(fn (mixed $value): bool => is_string($value) && str_contains($value, 'Cairo, Egypt'));

    $tableRowJson = json_encode($table->json('data.0'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    expect($tableRowJson)
        ->not->toContain('hidden-password')
        ->not->toContain('safe_context')
        ->not->toContain('client_context')
        ->not->toContain('device_context')
        ->not->toContain('location_context')
        ->not->toContain('network_context');

    expect($locationCell)
        ->toContain('Cairo, Egypt')
        ->toContain('View on map')
        ->toContain('href="'.$mapUrl.'"')
        ->toContain('target="_blank"')
        ->toContain('rel="noopener noreferrer"')
        ->not->toContain('>'.$mapUrl);

    $visibleLocationText = strip_tags((string) $locationCell);

    expect($visibleLocationText)
        ->toContain('View on map')
        ->toContain('Cairo, Egypt')
        ->not->toContain('https://')
        ->not->toContain('30.0444200')
        ->not->toContain('31.2357120')
        ->not->toContain('Accuracy: 25 m');

    $details = $this->actingAs($actor)
        ->getJson(route('admin.auth-logs.details', $authLog->public_id));

    $details->assertOk();
    $detailsPayload = $details->json('data');
    $detailsJson = json_encode($detailsPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    expect($detailsJson)
        ->toContain('30.0444200')
        ->toContain('31.2357120')
        ->toContain('Accuracy: 25 m')
        ->toContain('HTML5 Geolocation')
        ->toContain($mapUrl)
        ->not->toContain('hidden-password');

    $export = new AuthLogsExport($report);

    expect($export->headings())
        ->toContain('Map link')
        ->and($export->map($authLog))
        ->toContain($mapUrl);

    app()->setLocale('ar');

    expect($report->location($denied))
        ->toBe('تم رفض الموقع')
        ->and($report->location($unavailable))
        ->toBe('الموقع غير متاح');
});

test('report pdf shell uses inline disposition and shared header footer data', function () {
    app()->setLocale('en');

    $actor = reportUserWithPermissions(['activity.logs.pdf']);
    $company = Company::factory()->main()->create(['name' => 'Client Facing Company']);

    $response = $this->actingAs($actor)
        ->get(route('admin.activity-logs.export.pdf'));

    $response->assertOk()
        ->assertHeader('content-type', 'application/pdf')
        ->assertHeader('content-disposition', 'inline; filename="activity-logs-report.pdf"');

    $branding = app(BrandingService::class)->current();
    $payload = [
        'companyLogoPath' => $branding['logo_path'],
        'companyName' => $company->name,
        'direction' => 'ltr',
        'generatedByName' => $actor->name,
        'printDate' => app(DateFormatService::class)->formatDateTime(now()),
        'reportTitle' => __('activity_logs.report_title'),
    ];

    $header = view('reports.partials.header', $payload)->render();
    $footer = view('reports.partials.footer', $payload)->render();

    expect($header)
        ->toContain(__('activity_logs.report_title'))
        ->toContain(__('reports.print_date'))
        ->toContain('assets/img/logos/Logo.svg')
        ->toContain('max-width:110px;max-height:60px;width:auto;height:auto;object-fit:contain;')
        ->not->toContain('report-company-name')
        ->and(substr_count($header, '<img'))->toBe(1)
        ->and($footer)
        ->toContain($actor->name)
        ->toContain($company->name)
        ->toContain(__('reports.page').' {PAGENO}/{nbpg}');
});

test('report pdf branding header logo uses company logo then default logo then text fallback', function () {
    app()->setLocale('en');

    Storage::fake('public');
    Storage::disk('public')->put(
        'company-logos/main-logo.png',
        base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAFgwJ/l1p8WQAAAABJRU5ErkJggg==')
    );
    Storage::disk('public')->put(
        'company-favicons/main-favicon.png',
        base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAFgwJ/l1p8WQAAAABJRU5ErkJggg==')
    );

    $company = Company::factory()->main()->create([
        'name' => 'Logo Company',
        'logo' => 'company-logos/main-logo.png',
        'favicon' => 'company-favicons/main-favicon.png',
    ]);
    $branding = app(BrandingService::class)->current();
    $basePayload = [
        'companyName' => $company->name,
        'direction' => 'ltr',
        'generatedByName' => 'Admin User',
        'printDate' => app(DateFormatService::class)->formatDateTime(now()),
        'reportTitle' => __('activity_logs.report_title'),
    ];

    $companyHeader = view('reports.partials.header', $basePayload + [
        'companyLogoPath' => $branding['logo_path'],
    ])->render();

    expect($branding['logo_path'])
        ->toContain('company-logos/main-logo.png')
        ->and($companyHeader)
        ->toContain('company-logos/main-logo.png')
        ->toContain('max-width:110px;max-height:60px;width:auto;height:auto;object-fit:contain;')
        ->not->toContain('report-company-name')
        ->and(substr_count($companyHeader, '<img'))->toBe(1);

    $company->update(['logo' => null]);
    $defaultBranding = app(BrandingService::class)->current();
    $defaultHeader = view('reports.partials.header', $basePayload + [
        'companyLogoPath' => $defaultBranding['logo_path'],
    ])->render();

    expect($defaultBranding['logo_path'])
        ->toBe(public_path('assets/img/logos/Logo.svg'))
        ->and($defaultHeader)
        ->toContain('assets/img/logos/Logo.svg')
        ->not->toContain('company-favicons/main-favicon.png')
        ->toContain('max-width:110px;max-height:60px;width:auto;height:auto;object-fit:contain;')
        ->not->toContain('report-company-name')
        ->and(substr_count($defaultHeader, '<img'))->toBe(1);

    $textHeader = view('reports.partials.header', $basePayload + [
        'companyLogoPath' => storage_path('missing/report-logo.png'),
    ])->render();

    expect($textHeader)
        ->toContain('report-company-name')
        ->toContain('Logo Company')
        ->and(substr_count($textHeader, '<img'))->toBe(0);
});

test('report maps and headings use localized values and setting date formats', function () {
    app()->setLocale('en');

    app(SettingService::class)->set(SettingService::DateTimeFormatKey, 'Y**m**d H:i');

    $actor = reportUserWithPermissions(['auth.logs.view', 'auth.sessions.view']);

    $authLog = AuthLog::query()->create([
        'user_id' => $actor->id,
        'event' => 'login_failed_invalid_credentials',
        'status' => 'failed',
        'identifier' => 'admin@example.test',
        'ip_address' => '127.0.0.1',
        'browser_name' => 'Chrome',
        'os_name' => 'Linux',
        'device_type' => 'desktop',
        'failure_reason' => 'invalid_credentials',
        'context' => [
            'password' => 'hidden-password',
            'safe_reason' => 'invalid_credentials',
        ],
        'created_at' => now(),
    ]);
    $presence = UserPresenceSession::query()->create([
        'user_id' => $actor->id,
        'status' => UserPresenceService::StatusOnline,
        'ip_address' => '127.0.0.2',
        'device_type' => 'desktop',
        'browser_name' => 'Chrome',
        'os_name' => 'Linux',
        'context' => [
            'screen' => ['width' => 1440],
            'session_fingerprint' => 'raw-session-fingerprint',
        ],
        'login_at' => now(),
        'last_seen_at' => now(),
        'last_activity_at' => now(),
    ]);

    $authLogRow = app(AuthLogReport::class)
        ->map(AuthLog::query()->whereKey($authLog->id)->firstOrFail());
    $sessionRow = app(AuthSessionReport::class)
        ->map(UserPresenceSession::query()->whereKey($presence->id)->firstOrFail());

    expect(implode(' ', $authLogRow))
        ->toContain(now()->format('Y**m**d'))
        ->toContain('Login failed due to invalid credentials')
        ->toContain('Failed')
        ->toContain('Chrome')
        ->not->toContain('login_failed_invalid_credentials')
        ->not->toContain('invalid_credentials')
        ->not->toContain('hidden-password')
        ->and(implode(' ', $sessionRow))
        ->toContain(now()->format('Y**m**d'))
        ->toContain('Online')
        ->toContain('Desktop')
        ->toContain('Chrome on Linux')
        ->not->toContain(UserPresenceService::StatusOnline)
        ->not->toContain('raw-session-fingerprint');

    $authDetails = json_encode(
        app(AuthLogReport::class)->details(AuthLog::query()->whereKey($authLog->id)->firstOrFail()),
        JSON_UNESCAPED_UNICODE
    );
    $sessionDetails = json_encode(
        app(AuthSessionReport::class)->details(UserPresenceSession::query()->whereKey($presence->id)->firstOrFail()),
        JSON_UNESCAPED_UNICODE
    );

    expect($authDetails)
        ->toContain('Advanced details')
        ->toContain('safe_reason')
        ->not->toContain('hidden-password')
        ->and($sessionDetails)
        ->toContain('Technical Context')
        ->toContain('screen')
        ->not->toContain('raw-session-fingerprint')
        ->and(app(AuthLogReport::class)->headings())
        ->not->toContain('Technical Context')
        ->and(app(AuthSessionReport::class)->headings())
        ->not->toContain('Technical Context');

    app()->setLocale('ar');

    expect(app(AuthLogReport::class)->headings())
        ->toContain('التاريخ / الوقت')
        ->and(app(AuthSessionReport::class)->headings())
        ->toContain('وقت الدخول');
});

test('active sessions force logout marks a target session offline without exposing fingerprints', function () {
    $admin = reportUserWithPermissions([
        'auth.sessions.view',
        'auth.sessions.details',
        'auth.sessions.force_logout',
    ]);
    $target = User::factory()->create();
    $presence = UserPresenceSession::query()->create([
        'user_id' => $target->id,
        'session_fingerprint' => 'target-fingerprint',
        'status' => UserPresenceService::StatusOnline,
        'ip_address' => '127.0.0.2',
        'last_seen_at' => now(),
        'last_activity_at' => now(),
        'login_at' => now()->subMinutes(5),
        'expires_at' => now()->addHour(),
    ]);

    $response = $this->actingAs($admin)
        ->postJson(route('admin.auth-sessions.force-logout', $presence->public_id));

    $response->assertOk()
        ->assertJsonPath('success', true);

    $presence->refresh();

    expect($presence->status)->toBe(UserPresenceService::StatusOffline)
        ->and($presence->offline_reason)->toBe(UserPresenceService::ReasonForcedLogout)
        ->and($response->getContent())->not->toContain('target-fingerprint')
        ->and($response->getContent())->not->toContain('"id"');
});

test('auth report branding uses main company name', function () {
    $company = Company::factory()->main()->create(['name' => 'Main ERP Company']);

    reportUserWithPermissions(['auth.logs.view']);

    $this->get(route('login'))->assertOk()->assertSee($company->name);
});
