<?php

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Auth\Models\Role;
use Modules\Auth\Services\LockScreenService;
use Modules\Core\Database\Seeders\SettingSeeder;
use Modules\Core\Models\ArchiveFile;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Models\Setting;
use Modules\Core\Services\ArchiveFileService;
use Modules\Core\Services\ArchiveFolderService;
use Modules\Core\Services\BrandingService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\PwaSettingsService;
use Modules\Core\Services\SettingService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

function coreSettingsUserWithPermissions(array $permissions): User
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $user = User::factory()->create();
    $user->givePermissionTo($permissions);

    return $user;
}

function coreSettingsOperatingContext(object $test): Company
{
    static $documentNumber = 9200;

    $documentNumber++;
    $company = Company::query()->create([
        'doc_number' => $documentNumber,
        'doc_num' => 'Company-'.str_pad((string) $documentNumber, 5, '0', STR_PAD_LEFT),
        'name' => 'Settings Company '.$documentNumber,
        'status' => 'active',
        'is_main' => ! Company::query()->where('is_main', true)->exists(),
    ]);
    $branch = Branch::query()->create([
        'doc_number' => $documentNumber,
        'doc_num' => 'Branch-'.str_pad((string) $documentNumber, 5, '0', STR_PAD_LEFT),
        'company_id' => $company->getKey(),
        'name' => 'Settings Branch '.$documentNumber,
        'type' => 'administrative',
        'status' => 'active',
    ]);
    $period = FinancialPeriod::query()->create([
        'doc_number' => $documentNumber,
        'doc_num' => 'Period-'.str_pad((string) $documentNumber, 5, '0', STR_PAD_LEFT),
        'company_id' => $company->getKey(),
        'name' => 'Settings Period '.$documentNumber,
        'from_date' => '2026-01-01',
        'to_date' => '2026-12-31',
        'is_closed' => false,
    ]);

    $test->withSession([
        OperatingContextService::CompanyIdKey => $company->getKey(),
        OperatingContextService::CompanyDocNumKey => $company->doc_num,
        OperatingContextService::BranchIdKey => $branch->getKey(),
        OperatingContextService::BranchDocNumKey => $branch->doc_num,
        OperatingContextService::FinancialPeriodIdKey => $period->getKey(),
        OperatingContextService::FinancialPeriodDocNumKey => $period->doc_num,
    ]);

    return $company;
}

function coreSettingsArchiveFileForCompany(Company $company, UploadedFile $file): ArchiveFile
{
    $root = app(ArchiveFolderService::class)->generalRoot();
    $files = app(ArchiveFileService::class)->upload(
        files: [$file],
        attachable: $company,
        module: 'core',
        recordType: 'company',
        recordDocNum: $company->doc_num,
        folder: $root,
    );

    return $files[0];
}

test('settings seeder stores default date formats idempotently', function () {
    $this->seed(SettingSeeder::class);
    $this->seed(SettingSeeder::class);

    $settings = app(SettingService::class);

    expect($settings->dateFormat())->toBe('d/m/Y')
        ->and($settings->dateTimeFormat())->toBe('d/m/Y h:i A');

    $this->assertDatabaseHas('settings', [
        'key' => 'date_format',
        'value' => 'd/m/Y',
    ]);

    $this->assertDatabaseHas('settings', [
        'key' => 'date_time_format',
        'value' => 'd/m/Y h:i A',
    ]);
});

test('setting cache refreshes when a setting is updated', function () {
    Cache::flush();

    $settings = app(SettingService::class);

    $settings->set('layout.cached_setting', 'first');

    expect($settings->get('layout.cached_setting'))->toBe('first');

    $settings->set('layout.cached_setting', 'second');

    expect($settings->get('layout.cached_setting'))->toBe('second');
});

test('setting cache refreshes when a setting model is updated directly', function () {
    Cache::flush();

    $setting = Setting::query()->create([
        'key' => 'layout.direct_cached_setting',
        'value' => 'first',
    ]);

    expect(app(SettingService::class)->get('layout.direct_cached_setting'))->toBe('first');

    $setting->forceFill(['value' => 'second'])->save();

    expect(app(SettingService::class)->get('layout.direct_cached_setting'))->toBe('second');
});

test('branding settings cache refreshes when main company changes', function () {
    Cache::flush();

    $company = Company::factory()->main()->create([
        'name' => 'Cached Branding',
        'status' => 'active',
    ]);

    expect(app(BrandingService::class)->current()['name'])->toBe('Cached Branding');

    $company->forceFill(['name' => 'Fresh Branding'])->save();

    expect(app(BrandingService::class)->current()['name'])->toBe('Fresh Branding');
});

test('application and database use cairo timezone', function () {
    expect(config('app.timezone'))->toBe('Africa/Cairo')
        ->and(date_default_timezone_get())->toBe('Africa/Cairo')
        ->and(config('database.connections.pgsql.timezone'))->toBe('Africa/Cairo');

    if (DB::connection()->getDriverName() === 'pgsql') {
        $timezone = DB::selectOne("select current_setting('TIMEZONE') as timezone")->timezone ?? null;

        expect($timezone)->toBe('Africa/Cairo');
    }
});

test('roles datatable formats timestamps using settings date time format', function () {
    app(SettingService::class)->set('date_time_format', 'd/m/Y h:i A');

    $user = coreSettingsUserWithPermissions(['roles.view']);

    Role::query()->create([
        'name' => 'manager',
        'guard_name' => 'web',
        'doc_number' => 1,
        'doc_num' => 'Role-00001',
        'created_at' => CarbonImmutable::create(2026, 4, 27, 21, 35),
        'updated_at' => CarbonImmutable::create(2026, 4, 28, 9, 5),
    ]);

    $this->actingAs($user)
        ->getJson(route('admin.roles.data', [
            'draw' => 1,
            'start' => 0,
            'length' => 10,
        ]))
        ->assertOk()
        ->assertJsonFragment([
            'created_at' => '27/04/2026 09:35 PM',
            'updated_at' => '28/04/2026 09:05 AM',
        ]);
});

test('role and user forms format tracking timestamps using settings date time format', function () {
    app(SettingService::class)->set('date_time_format', 'd/m/Y h:i A');

    $actor = coreSettingsUserWithPermissions(['roles.view', 'users.view']);
    $role = Role::query()->create([
        'name' => 'formatted-role',
        'guard_name' => 'web',
        'doc_number' => 2,
        'doc_num' => 'Role-00002',
        'created_at' => CarbonImmutable::create(2026, 4, 27, 21, 35),
        'updated_at' => CarbonImmutable::create(2026, 4, 28, 9, 5),
    ]);
    $user = User::factory()->create([
        'created_at' => CarbonImmutable::create(2026, 4, 27, 21, 35),
        'updated_at' => null,
    ]);

    $this->actingAs($actor)
        ->get(route('admin.roles.show', $role->doc_num))
        ->assertOk()
        ->assertSee('27/04/2026 09:35 PM')
        ->assertSee('28/04/2026 09:05 AM')
        ->assertSee('class="form-control date-value erp-view-field-control"', false)
        ->assertSee('dir="ltr"', false)
        ->assertDontSee('2026-04-27 21:35');

    $this->actingAs($actor)
        ->get(route('admin.users.show', $user->doc_num))
        ->assertOk()
        ->assertSee('27/04/2026 09:35 PM')
        ->assertDontSee(__('common.messages.not_available'))
        ->assertSee('class="form-control date-value erp-view-field-control"', false)
        ->assertSee('dir="ltr"', false)
        ->assertDontSee('2026-04-27 21:35');
});

test('profile page formats tracking timestamps using settings date time format', function () {
    app(SettingService::class)->set('date_time_format', 'd/m/Y h:i A');

    $user = coreSettingsUserWithPermissions(['profile.view']);
    $user->forceFill([
        'created_at' => CarbonImmutable::create(2026, 4, 27, 21, 35),
        'updated_at' => CarbonImmutable::create(2026, 4, 28, 9, 5),
    ])->saveQuietly();

    $this->actingAs($user)
        ->get(route('profile.show'))
        ->assertOk()
        ->assertSee('27/04/2026 09:35 PM')
        ->assertSee('28/04/2026 09:05 AM')
        ->assertSee('class="fw-semi-bold text-1000 date-value"', false)
        ->assertSee('dir="ltr"', false)
        ->assertDontSee('2026-04-27 21:35');
});

test('pwa settings page is permission protected and shows only client friendly controls', function () {
    $actor = coreSettingsUserWithPermissions(['settings.pwa.view', 'file_manager.view']);
    coreSettingsOperatingContext($this);

    $html = $this->actingAs($actor)
        ->get(route('admin.settings.pwa'))
        ->assertOk()
        ->assertSee(__('pwa.title'))
        ->assertSee(__('pwa.fields.enabled'))
        ->assertSee(__('pwa.fields.app_name'))
        ->assertSee(__('pwa.fields.short_name'))
        ->assertSee(__('pwa.fields.description'))
        ->assertSee(__('pwa.fields.display'))
        ->assertSee(__('pwa.fields.orientation'))
        ->assertSee(__('pwa.fields.direction'))
        ->assertSee(__('pwa.fields.offline_title'))
        ->assertSee(__('pwa.fields.offline_message'))
        ->assertSee('data-file-picker', false)
        ->assertSee('name="icon_192_archive_file_doc_num"', false)
        ->assertSee('name="icon_512_archive_file_doc_num"', false)
        ->assertSee('assets/js/modules/Core/file-picker.js', false)
        ->assertSee('assets/js/modules/Core/pwa-settings.js', false)
        ->assertSee('id="file-picker-modal"', false)
        ->assertDontSee('name="icon_192"', false)
        ->assertDontSee('name="pwa.icon_192_path"', false)
        ->getContent();

    expect($html)
        ->toContain('name="display"')
        ->toContain('name="orientation"')
        ->toContain('name="direction"')
        ->toContain('name="offline_title"')
        ->toContain('name="offline_message"')
        ->not->toContain('name="service_worker_enabled"')
        ->not->toContain('name="offline_enabled"')
        ->not->toContain('name="start_url"')
        ->not->toContain('name="scope"')
        ->not->toContain('name="theme_color"')
        ->not->toContain('name="background_color"')
        ->not->toContain('name="locale"')
        ->not->toContain('name="cache_name"');

    $this->actingAs(coreSettingsUserWithPermissions([]))
        ->get(route('admin.settings.pwa'))
        ->assertForbidden();
});

test('pwa orientation direction and offline copy persist while technical values stay server controlled', function () {
    Storage::fake('public');
    Storage::fake('local');
    config()->set('archive.disk', 'local');
    app(SettingService::class)->set(PwaSettingsService::ThemeColorKey, '#112233');
    app(SettingService::class)->set(PwaSettingsService::BackgroundColorKey, '#f8fafc');
    app(SettingService::class)->set(PwaSettingsService::ServiceWorkerEnabledKey, '0');
    app(SettingService::class)->set(PwaSettingsService::OfflineEnabledKey, '0');

    $actor = coreSettingsUserWithPermissions(['settings.pwa.view', 'settings.pwa.update', 'file_manager.view']);
    $company = coreSettingsOperatingContext($this);
    $icon = coreSettingsArchiveFileForCompany($company, UploadedFile::fake()->image('pwa-icon.png', 192, 192)->size(64));

    $this->actingAs($actor)
        ->post(route('admin.settings.pwa.update'), [
            'enabled' => '1',
            'app_name' => 'Short Coded ERP',
            'short_name' => 'ERP',
            'description' => 'Installable ERP.',
            'display' => 'standalone',
            'start_url' => '//evil.example',
            'scope' => '//evil.example',
            'orientation' => 'landscape',
            'theme_color' => '#445566',
            'background_color' => '#000000',
            'locale' => 'xx',
            'direction' => 'rtl',
            'service_worker_enabled' => '0',
            'offline_enabled' => '0',
            'offline_title' => 'Posted Offline',
            'offline_message' => 'Posted offline message.',
            'cache_name' => 'posted-cache-name',
            'icon_192_archive_file_doc_num' => $icon->doc_num,
        ])
        ->assertRedirect();

    $settings = app(PwaSettingsService::class)->settings();

    expect($settings['enabled'])->toBeTrue()
        ->and($settings['app_name'])->toBe('Short Coded ERP')
        ->and($settings['start_url'])->toBe(route('dashboard', [], false))
        ->and($settings['scope'])->toBe('/')
        ->and($settings['orientation'])->toBe('landscape')
        ->and($settings['theme_color'])->toBe('#112233')
        ->and($settings['background_color'])->toBe('#f8fafc')
        ->and($settings['locale'])->toBe(app()->getLocale())
        ->and($settings['direction'])->toBe('rtl')
        ->and($settings['service_worker_enabled'])->toBeTrue()
        ->and($settings['offline_enabled'])->toBeTrue()
        ->and($settings['offline_title'])->toBe('Posted Offline')
        ->and($settings['offline_message'])->toBe('Posted offline message.')
        ->and($settings['cache_name'])->toBe('erp-pwa-cache-v1')
        ->and($settings['icon_192_path'])->toStartWith('pwa/icons/')
        ->and($settings['icon_512_path'])->toBeNull();

    Storage::disk('public')->assertExists($settings['icon_192_path']);

    $this->get(route('pwa.manifest'))
        ->assertOk()
        ->assertJsonPath('name', 'Short Coded ERP')
        ->assertJsonPath('theme_color', '#112233')
        ->assertJsonPath('orientation', 'landscape')
        ->assertJsonPath('dir', 'rtl')
        ->assertJsonPath('icons.0.sizes', '192x192');

    expect($this->get(route('pwa.manifest'))->json('icons.0.src'))
        ->toContain('/storage/pwa/icons/')
        ->not->toContain('/admin/');

    $this->actingAs($actor)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee(route('pwa.manifest'), false)
        ->assertSee('window.AppPwaRuntime', false)
        ->assertSee('enabled: true', false)
        ->assertSee('assets/js/modules/Core/pwa-runtime.js', false);

    $this->get(route('pwa.service-worker'))
        ->assertOk()
        ->assertSee('const ERP_PWA_OFFLINE_ENABLED = true;', false)
        ->assertSee('erp-pwa-cache-v1');

    $this->get(route('pwa.offline'))
        ->assertOk()
        ->assertSee('Posted Offline')
        ->assertSee('Posted offline message.');
});

test('pwa orientation automatic labels are translated in arabic and english', function () {
    expect(trans('pwa.orientations.any', [], 'ar'))->toBe('تلقائي')
        ->and(trans('pwa.orientations.any', [], 'en'))->toBe('Automatic');
});

test('legacy service worker endpoint retires stale root registrations without reloading clients', function () {
    $staticScript = file_get_contents(public_path('service-worker.js'));
    $response = $this->get(route('pwa.legacy-service-worker'));

    expect($staticScript)->toBeString();

    $response
        ->assertOk()
        ->assertHeader('Service-Worker-Allowed', '/');

    expect($response->headers->get('Content-Type'))
        ->toContain('application/javascript');

    expect($response->headers->get('Cache-Control'))
        ->toContain('no-store')
        ->toContain('no-cache')
        ->toContain('must-revalidate')
        ->toContain('max-age=0');

    expect($staticScript)
        ->toContain('self.skipWaiting()')
        ->toContain('self.registration.unregister()')
        ->not->toContain('clients.claim()')
        ->not->toContain('location.reload');

    expect(trim($response->getContent()))
        ->toBe(trim($staticScript))
        ->toContain('self.skipWaiting()')
        ->toContain('self.registration.unregister()')
        ->not->toContain('clients.claim()')
        ->not->toContain('location.reload');
});

test('pwa manifest and service worker stay public and bypass lock redirects', function () {
    app(SettingService::class)->set(PwaSettingsService::EnabledKey, '1');
    app(SettingService::class)->set(PwaSettingsService::ServiceWorkerEnabledKey, '1');
    app(SettingService::class)->set(PwaSettingsService::OfflineEnabledKey, '1');

    $serviceWorkerResponse = $this->get(route('pwa.service-worker'));
    $serviceWorkerResponse
        ->assertOk()
        ->assertHeader('Service-Worker-Allowed', '/')
        ->assertSee('const ERP_PWA_OFFLINE_ENABLED = true;', false);

    expect($serviceWorkerResponse->headers->get('Content-Type'))
        ->toContain('application/javascript');

    $this->get(route('pwa.legacy-service-worker'))
        ->assertOk();

    $serviceWorkerScript = str_replace('\\/', '/', $serviceWorkerResponse->getContent());

    expect($serviceWorkerScript)
        ->toContain("request.mode === 'navigate'")
        ->toContain("event.data.type === 'SKIP_WAITING'")
        ->toContain("accept.includes('text/html')")
        ->toContain('/auth/csrf-token')
        ->toContain('/lock-screen/unlock')
        ->toContain('/manifest.webmanifest')
        ->toContain('/service-worker.js')
        ->toContain('/pwa-service-worker.js')
        ->toContain('/admin/notifications/')
        ->toContain('isNetworkOnlyPath')
        ->toContain('networkFirstNavigation')
        ->toContain('cacheFirstStatic')
        ->toContain('caches.delete');

    $manifestResponse = $this->get(route('pwa.manifest'));

    $manifestResponse
        ->assertOk()
        ->assertJsonStructure([
            'name',
            'short_name',
            'start_url',
            'scope',
            'display',
            'icons',
        ]);

    expect($manifestResponse->headers->get('Content-Type'))
        ->toContain('application/manifest+json');

    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession([
            LockScreenService::LockedSessionKey => true,
            LockScreenService::ReturnUrlSessionKey => '/dashboard',
        ])
        ->get(route('pwa.service-worker'))
        ->assertOk()
        ->assertSessionHas(LockScreenService::ReturnUrlSessionKey, '/dashboard');

    $this->actingAs($user)
        ->get(route('pwa.legacy-service-worker'))
        ->assertOk()
        ->assertSessionHas(LockScreenService::ReturnUrlSessionKey, '/dashboard');

    $this->get(route('pwa.manifest'))
        ->assertOk()
        ->assertSessionHas(LockScreenService::ReturnUrlSessionKey, '/dashboard');
});

test('pwa settings reject invalid selected file manager icon files', function () {
    Storage::fake('public');
    Storage::fake('local');
    config()->set('archive.disk', 'local');

    $actor = coreSettingsUserWithPermissions(['settings.pwa.view', 'settings.pwa.update', 'file_manager.view']);
    $company = coreSettingsOperatingContext($this);
    $file = coreSettingsArchiveFileForCompany($company, UploadedFile::fake()->create('not-image.pdf', 16, 'application/pdf'));

    $this->actingAs($actor)
        ->post(route('admin.settings.pwa.update'), [
            'enabled' => '1',
            'app_name' => 'Short Coded ERP',
            'short_name' => 'ERP',
            'description' => 'Installable ERP.',
            'display' => 'standalone',
            'icon_192_archive_file_doc_num' => $file->doc_num,
        ])
        ->assertSessionHasErrors('icon_192_archive_file_doc_num');
});

test('disabled web app setting keeps manifest and service worker registration out of the layout', function () {
    $actor = coreSettingsUserWithPermissions(['settings.pwa.view', 'settings.pwa.update']);

    $this->actingAs($actor)
        ->post(route('admin.settings.pwa.update'), [
            'enabled' => '0',
            'app_name' => 'Short Coded ERP',
            'short_name' => 'ERP',
            'description' => 'Installable ERP.',
            'display' => 'standalone',
        ])
        ->assertRedirect();

    $this->actingAs($actor)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee(route('pwa.manifest'), false)
        ->assertSee('window.AppPwaRuntime', false)
        ->assertSee('enabled: false', false)
        ->assertSee('assets/js/modules/Core/pwa-runtime.js', false);

    $this->get(route('pwa.service-worker'))
        ->assertOk()
        ->assertSee('const ERP_PWA_OFFLINE_ENABLED = false;', false);
});

test('settings seeder creates pwa defaults without overwriting existing values', function () {
    app(SettingService::class)->set(PwaSettingsService::AppNameKey, 'Custom ERP');

    $this->seed(SettingSeeder::class);
    $this->seed(SettingSeeder::class);

    expect(app(SettingService::class)->get(PwaSettingsService::AppNameKey))->toBe('Custom ERP');

    $this->assertDatabaseHas('settings', [
        'key' => PwaSettingsService::EnabledKey,
        'value' => '0',
    ]);
});
