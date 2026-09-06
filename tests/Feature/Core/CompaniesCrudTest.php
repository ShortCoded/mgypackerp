<?php

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Modules\Accounting\Models\Account;
use Modules\Auth\Database\Seeders\PermissionSeeder;
use Modules\Auth\Models\Role;
use Modules\Core\Models\ArchiveFile;
use Modules\Core\Models\Company;
use Modules\Core\Services\CompanyPrintIdentityService;
use Modules\Core\Services\CompanyService;
use Modules\Core\Services\SettingService;
use Modules\HR\Models\HrArea;
use Modules\HR\Models\HrCity;
use Modules\HR\Models\HrCountry;
use Modules\HR\Models\HrGovernorate;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

function coreCompanyCrudActor(array $permissions): User
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $user = User::factory()->create();
    $user->givePermissionTo($permissions);

    return $user;
}

function companyPayload(array $overrides = []): array
{
    return [
        'name' => 'Acme Company',
        'legal_name' => 'Acme Legal LLC',
        'commercial_name' => 'Acme Trading',
        'status' => 'active',
        'phone' => '+20 100 123 4567',
        'hotline' => '16000',
        'email' => 'acme@example.com',
        'country' => 'Egypt',
        'city' => 'Cairo',
        'notes' => 'Core company notes',
        ...$overrides,
    ];
}

function companyAuthorizationImage(Company $company, string $docNum, string $fileName): ArchiveFile
{
    static $documentNumber = 900;

    $documentNumber++;
    $path = 'tests/company-authorization/'.$fileName;
    Storage::disk('public')->put($path, 'image-content');

    return ArchiveFile::query()->create([
        'doc_number' => $documentNumber,
        'doc_num' => $docNum,
        'attachable_type' => (new Company)->getMorphClass(),
        'attachable_id' => $company->getKey(),
        'module' => 'core',
        'record_type' => 'company_authorization',
        'hidden_from_picker' => false,
        'original_name' => $fileName,
        'stored_name' => $fileName,
        'disk' => 'public',
        'path' => $path,
        'mime_type' => 'image/png',
        'extension' => 'png',
        'size_bytes' => 13,
    ]);
}

beforeEach(function (): void {
    config()->set('companies.max_companies', null);
});

test('companies permissions are discoverable and assigned to admin role', function () {
    $this->seed(PermissionSeeder::class);

    $adminRole = Role::query()
        ->where('name', 'admin')
        ->where('guard_name', 'web')
        ->firstOrFail();

    expect(Permission::query()->where('name', 'companies.view')->exists())->toBeTrue()
        ->and(Permission::query()->where('name', 'companies.view_trashed')->exists())->toBeTrue()
        ->and(Permission::query()->where('name', 'companies.restore')->exists())->toBeTrue()
        ->and(Permission::query()->where('name', 'companies.main.control')->exists())->toBeTrue()
        ->and($adminRole->hasPermissionTo('companies.document_number_settings.update'))->toBeTrue();
});

test('companies routes render index without internal ids', function () {
    $actor = coreCompanyCrudActor(['companies.view', 'companies.create', 'companies.document_number_settings.update']);

    $this->actingAs($actor)
        ->get(route('admin.companies.index'))
        ->assertOk()
        ->assertSee(__('companies.title'))
        ->assertSee(__('menu.basic_data'))
        ->assertSee(__('menu.companies'))
        ->assertSee(route('admin.companies.data'), false)
        ->assertSee('assets/js/modules/Core/companies.js', false)
        ->assertDontSee('id="companies_trash_filter"', false)
        ->assertDontSee('data-id=', false);

    expect(Activity::query()->where('action', 'companies.index')->exists())->toBeFalse();
    expect(route('admin.companies.create'))->toBe(url('/admin/companies/create'));
});

test('companies schema uses document number as the company reference without separate code', function () {
    expect(Schema::hasColumn('companies', 'doc_number'))->toBeTrue()
        ->and(Schema::hasColumn('companies', 'doc_num'))->toBeTrue()
        ->and(Schema::hasColumn('companies', 'authorized_signatory_name'))->toBeTrue()
        ->and(Schema::hasColumn('companies', 'authorized_signatory_title'))->toBeTrue()
        ->and(Schema::hasColumn('companies', 'company_stamp_archive_file_id'))->toBeTrue()
        ->and(Schema::hasColumn('companies', 'authorized_signatory_signature_archive_file_id'))->toBeTrue()
        ->and(Schema::hasColumn('companies', 'code'))->toBeFalse();
});

test('companies index shows trash filter only to deleted-record viewers', function () {
    $viewer = coreCompanyCrudActor(['companies.view']);

    $this->actingAs($viewer)
        ->get(route('admin.companies.index'))
        ->assertOk()
        ->assertDontSee('id="companies_trash_filter"', false);

    $trashViewer = coreCompanyCrudActor(['companies.view', 'companies.view_trashed']);

    $this->actingAs($trashViewer)
        ->get(route('admin.companies.index'))
        ->assertOk()
        ->assertSee('id="companies_trash_filter"', false)
        ->assertSee(__('common.trash.active'))
        ->assertSee(__('common.trash.trashed'))
        ->assertSee(__('common.trash.all'))
        ->assertSee('trash_filter', false)
        ->assertSee('companies_trash_filter', false);
});

test('companies create form uses main save data as save and new', function () {
    $actor = coreCompanyCrudActor(['companies.create', 'companies.view', 'companies.edit', 'companies.clone']);

    $this->actingAs($actor)
        ->get(route('admin.companies.create'))
        ->assertOk()
        ->assertSee('data-mode="create"', false)
        ->assertSee('name="submit_action" value="save"', false)
        ->assertDontSee('data-submit-action="save_new"', false)
        ->assertSee('<span class="text-danger" aria-hidden="true">*</span>', false)
        ->assertSee(__('common.shortcuts.save'), false)
        ->assertDontSee('data-shortcut-action="form.save_new"', false)
        ->assertDontSee(__('common.fields.deleted_by'))
        ->assertDontSee(__('common.fields.deleted_at'))
        ->assertDontSee(__('common.fields.restored_by'))
        ->assertDontSee(__('common.fields.restored_at'));
});

test('companies index hides add button when configured company limit is reached', function () {
    config()->set('companies.max_companies', 1);

    Company::factory()->main()->create([
        'name' => 'Limit Existing Company',
        'email' => 'limit-existing-company@example.com',
    ]);

    $actor = coreCompanyCrudActor(['companies.view', 'companies.create']);

    $this->actingAs($actor)
        ->get(route('admin.companies.index'))
        ->assertOk()
        ->assertSee(__('companies.messages.cannot_create_more_companies'))
        ->assertSee(__('companies.messages.max_companies_reached_help'))
        ->assertDontSee('id="btn_add_record"', false)
        ->assertDontSee(route('admin.companies.create'), false);
});

test('company creation limit blocks create page and direct store when reached', function () {
    config()->set('companies.max_companies', 1);

    Company::factory()->main()->create([
        'name' => 'Only Allowed Company',
        'email' => 'only-allowed-company@example.com',
    ]);

    $actor = coreCompanyCrudActor(['companies.view', 'companies.create']);

    $this->actingAs($actor)
        ->get(route('admin.companies.create'))
        ->assertRedirect(route('admin.companies.index'))
        ->assertSessionHas('warning', __('companies.messages.cannot_create_more_companies'));

    $this->actingAs($actor)
        ->postJson(route('admin.companies.store'), companyPayload([
            'name' => 'Blocked Limit Company',
            'email' => 'blocked-limit-company@example.com',
        ]))
        ->assertUnprocessable()
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', __('companies.messages.max_companies_reached'));

    expect(Company::withTrashed()->whereNull('deleted_at')->count())->toBe(1)
        ->and(Company::query()->where('name', 'Blocked Limit Company')->exists())->toBeFalse();
});

test('company creation is allowed while configured company limit has room', function () {
    config()->set('companies.max_companies', 2);

    Company::factory()->main()->create([
        'name' => 'First Allowed Company',
        'email' => 'first-allowed-company@example.com',
    ]);

    $actor = coreCompanyCrudActor(['companies.view', 'companies.create']);

    $this->actingAs($actor)
        ->get(route('admin.companies.create'))
        ->assertOk()
        ->assertSee('data-mode="create"', false);

    $this->actingAs($actor)
        ->postJson(route('admin.companies.store'), companyPayload([
            'name' => 'Second Allowed Company',
            'email' => 'second-allowed-company@example.com',
        ]))
        ->assertOk()
        ->assertJsonPath('success', true);

    expect(Company::withTrashed()->whereNull('deleted_at')->count())->toBe(2)
        ->and(Company::query()->where('name', 'Second Allowed Company')->exists())->toBeTrue();

    $createdCompany = Company::query()->where('name', 'Second Allowed Company')->firstOrFail();

    expect(Account::withTrashed()->where('company_id', $createdCompany->getKey())->count())->toBe(0);
});

test('trashed companies are not counted against configured company creation limit', function () {
    config()->set('companies.max_companies', 1);

    $trashed = Company::factory()->create([
        'name' => 'Trashed Limit Company',
        'email' => 'trashed-limit-company@example.com',
    ]);
    $trashed->delete();

    $actor = coreCompanyCrudActor(['companies.view', 'companies.create']);

    $this->actingAs($actor)
        ->get(route('admin.companies.create'))
        ->assertOk();

    $this->actingAs($actor)
        ->postJson(route('admin.companies.store'), companyPayload([
            'name' => 'Replacement Limit Company',
            'email' => 'replacement-limit-company@example.com',
        ]))
        ->assertOk()
        ->assertJsonPath('success', true);

    expect(Company::withTrashed()->whereNull('deleted_at')->count())->toBe(1)
        ->and(Company::withTrashed()->count())->toBe(2)
        ->and(Company::query()->where('name', 'Replacement Limit Company')->exists())->toBeTrue();
});

test('company update and edit remain available when configured company limit is reached', function () {
    config()->set('companies.max_companies', 1);

    $company = Company::factory()->main()->create([
        'name' => 'Editable Limit Company',
        'email' => 'editable-limit-company@example.com',
    ]);

    $actor = coreCompanyCrudActor(['companies.edit']);

    $this->actingAs($actor)
        ->get(route('admin.companies.edit', $company->doc_num))
        ->assertOk()
        ->assertSee('data-mode="edit"', false);

    $this->actingAs($actor)
        ->putJson(route('admin.companies.update', $company->doc_num), [
            'name' => 'Edited Limit Company',
            'email' => $company->email,
            'status' => 'active',
        ])
        ->assertOk()
        ->assertJsonPath('success', true);

    expect($company->refresh()->name)->toBe('Edited Limit Company')
        ->and(Company::withTrashed()->whereNull('deleted_at')->count())->toBe(1);
});

test('company form uses separated cards and Falcon upload sections', function () {
    $actor = coreCompanyCrudActor(['companies.create', 'companies.document_number.control', 'companies.main.control', 'file_manager.view']);
    $logoAccept = collect(config('archive.logo.allowed_extensions', ['jpg', 'jpeg', 'png', 'webp']))
        ->map(fn (string $extension): string => '.'.ltrim($extension, '.'))
        ->implode(',');
    $faviconAccept = collect(config('archive.favicon.allowed_extensions', ['ico', 'png', 'jpg', 'jpeg', 'webp']))
        ->map(fn (string $extension): string => '.'.ltrim($extension, '.'))
        ->implode(',');

    $this->actingAs($actor)
        ->get(route('admin.companies.create'))
        ->assertOk()
        ->assertSee(__('companies.sections.basic_information'))
        ->assertSee(__('companies.sections.signature_authorization'))
        ->assertSee(__('companies.sections.legal_tax_information'))
        ->assertSee(__('companies.sections.contact_information'))
        ->assertSee(__('companies.sections.address'))
        ->assertSee(__('companies.sections.business_activity'))
        ->assertSee(__('companies.sections.notes'))
        ->assertSee('enctype="multipart/form-data"', false)
        ->assertSee('method="POST"', false)
        ->assertSee('js-company-logo-uploader', false)
        ->assertSee('js-company-logo-input', false)
        ->assertSee('name="logo"', false)
        ->assertSee('accept="'.$logoAccept.'"', false)
        ->assertSee('for="company-logo"', false)
        ->assertSee('name="favicon"', false)
        ->assertSee('name="company_stamp_archive_file_doc_num"', false)
        ->assertSee('name="authorized_signatory_name"', false)
        ->assertSee('name="authorized_signatory_title"', false)
        ->assertSee('name="authorized_signatory_signature_archive_file_doc_num"', false)
        ->assertSee('data-picker-collection="company_stamp"', false)
        ->assertSee('data-picker-collection="company_authorized_signature"', false)
        ->assertSee('for="company-favicon"', false)
        ->assertSee('accept="'.$faviconAccept.'"', false)
        ->assertSee(__('companies.favicon.help'))
        ->assertDontSee('datetimepicker js-date-picker', false)
        ->assertSee('class="form-control js-date-picker"', false)
        ->assertDontSee('js-company-logo-dropzone', false)
        ->assertDontSee('js-company-logo-preview-template', false)
        ->assertDontSee('dropzone dropzone-single', false)
        ->assertDontSee('dz-preview dz-file-preview dz-preview-single', false)
        ->assertDontSee('assets/img/generic/image-file-2.png', false)
        ->assertSee('js-date-picker', false)
        ->assertSee(route('admin.select2.countries'), false)
        ->assertSee(route('admin.select2.governorates'), false)
        ->assertSee(route('admin.select2.cities'), false)
        ->assertSee(route('admin.select2.areas'), false)
        ->assertSee('name="country_doc_num"', false)
        ->assertDontSee(__('companies.archive.company_archive'))
        ->assertDontSee('company-archive-files-table', false)
        ->assertDontSee('assets/js/modules/Core/file-manager.js', false)
        ->assertDontSee('name="country" type="text"', false)
        ->assertDontSee('name="code"', false)
        ->assertDontSee('name="legal_form"', false)
        ->assertDontSee('name="national_id"', false)
        ->assertDontSee('name="whatsapp"', false)
        ->assertSee('name="hotline"', false)
        ->assertDontSee('currency_code', false)
        ->assertDontSee('fiscal_year_start_month', false)
        ->assertDontSee('vat_rate', false);
});

test('company authorization identity hydrates replaces removes validates and preserves no-op updates', function (): void {
    Storage::fake('public');

    $actor = coreCompanyCrudActor(['companies.view', 'companies.edit', 'file_manager.view']);
    $company = Company::factory()->main()->create([
        'name' => 'Authorization Company',
        'email' => 'authorization-company@example.com',
    ]);
    $stamp = companyAuthorizationImage($company, 'ARCH-STAMP-001', 'company-stamp.png');
    $signature = companyAuthorizationImage($company, 'ARCH-SIGN-001', 'authorized-signature.png');
    $replacementStamp = companyAuthorizationImage($company, 'ARCH-STAMP-002', 'replacement-stamp.png');
    $replacementSignature = companyAuthorizationImage($company, 'ARCH-SIGN-002', 'replacement-signature.png');

    $payload = [
        'name' => $company->name,
        'email' => $company->email,
        'status' => $company->status,
        'authorized_signatory_name' => 'Nadia Hassan',
        'authorized_signatory_title' => 'Finance Director',
        'company_stamp_archive_file_doc_num' => $stamp->doc_num,
        'authorized_signatory_signature_archive_file_doc_num' => $signature->doc_num,
    ];

    $this->actingAs($actor)
        ->putJson(route('admin.companies.update', $company->doc_num), $payload)
        ->assertOk()
        ->assertJsonPath('success', true);

    $company->refresh();

    expect($company->authorized_signatory_name)->toBe('Nadia Hassan')
        ->and($company->authorized_signatory_title)->toBe('Finance Director')
        ->and($company->company_stamp_archive_file_id)->toBe($stamp->getKey())
        ->and($company->authorized_signatory_signature_archive_file_id)->toBe($signature->getKey());

    $activityProperties = Activity::query()
        ->where('action', 'companies.update')
        ->latest('id')
        ->firstOrFail()
        ->properties
        ->toArray();

    expect(data_get($activityProperties, 'changes.company_stamp_archive_file_doc_num.new'))->toBe($stamp->doc_num)
        ->and(data_get($activityProperties, 'changes.authorized_signatory_signature_archive_file_doc_num.new'))->toBe($signature->doc_num)
        ->and(json_encode($activityProperties))->not->toContain('company_stamp_archive_file_id')
        ->and(json_encode($activityProperties))->not->toContain('authorized_signatory_signature_archive_file_id');

    foreach (['admin.companies.edit', 'admin.companies.show'] as $routeName) {
        $this->actingAs($actor)
            ->get(route($routeName, $company->doc_num))
            ->assertOk()
            ->assertSee('Nadia Hassan')
            ->assertSee('Finance Director')
            ->assertSee($stamp->doc_num)
            ->assertSee($signature->doc_num)
            ->assertSee(route('admin.file-manager.files.preview', $stamp->doc_num), false)
            ->assertSee(route('admin.file-manager.files.preview', $signature->doc_num), false);
    }

    $this->actingAs($actor)
        ->putJson(route('admin.companies.update', $company->doc_num), $payload)
        ->assertOk()
        ->assertJsonPath('type', 'no_changes');

    $this->actingAs($actor)
        ->putJson(route('admin.companies.update', $company->doc_num), [
            ...$payload,
            'company_stamp_archive_file_doc_num' => $replacementStamp->doc_num,
            'authorized_signatory_signature_archive_file_doc_num' => $replacementSignature->doc_num,
        ])
        ->assertOk();

    expect($company->refresh()->company_stamp_archive_file_id)->toBe($replacementStamp->getKey())
        ->and($company->authorized_signatory_signature_archive_file_id)->toBe($replacementSignature->getKey());

    $this->actingAs($actor)
        ->putJson(route('admin.companies.update', $company->doc_num), [
            ...$payload,
            'company_stamp_archive_file_doc_num' => '',
            'authorized_signatory_signature_archive_file_doc_num' => '',
        ])
        ->assertOk();

    expect($company->refresh()->company_stamp_archive_file_id)->toBeNull()
        ->and($company->authorized_signatory_signature_archive_file_id)->toBeNull();

    $otherCompany = Company::factory()->create(['name' => 'Other Authorization Company']);
    $otherCompanyImage = companyAuthorizationImage($otherCompany, 'ARCH-OTHER-001', 'other-company.png');

    $this->actingAs($actor)
        ->putJson(route('admin.companies.update', $company->doc_num), [
            ...$payload,
            'company_stamp_archive_file_doc_num' => $otherCompanyImage->doc_num,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['company_stamp_archive_file_doc_num']);

    $this->actingAs($actor)
        ->get(route('admin.companies.show', $company->doc_num))
        ->assertOk()
        ->assertSee(__('companies.stamp.no_file_selected'))
        ->assertSee(__('companies.signature.no_file_selected'));
});

test('company edit form uses post method spoofing for multipart logo uploads', function () {
    $actor = coreCompanyCrudActor(['companies.edit']);
    $company = Company::factory()->main()->create([
        'logo' => 'company-logos/current-logo.png',
        'favicon' => 'company-favicons/current-favicon.png',
    ]);

    $this->actingAs($actor)
        ->get(route('admin.companies.edit', $company->doc_num))
        ->assertOk()
        ->assertSee('method="POST"', false)
        ->assertSee('name="_method" value="PUT"', false)
        ->assertSee('enctype="multipart/form-data"', false)
        ->assertSee('data-current-url=', false)
        ->assertSee(__('companies.logo.existing_file'))
        ->assertSee(__('companies.favicon.existing_file'))
        ->assertSee('name="remove_favicon"', false)
        ->assertDontSee('method="PUT"', false)
        ->assertDontSee('js-company-logo-dropzone', false);
});

test('company view shows consistent placeholders for optional empty fields', function () {
    $actor = coreCompanyCrudActor(['companies.view']);
    $company = Company::factory()->create([
        'name' => 'Blank View Company',
        'legal_name' => null,
        'commercial_name' => null,
        'email' => null,
        'phone' => null,
        'mobile' => null,
        'hotline' => null,
        'postal_code' => null,
        'address' => null,
        'map_url' => null,
        'business_description' => null,
        'notes' => null,
        'commercial_register_date' => null,
        'country' => null,
        'governorate' => null,
        'city' => null,
        'area' => null,
        'country_id' => null,
        'governorate_id' => null,
        'city_id' => null,
        'area_id' => null,
        'updated_by' => null,
        'updated_at' => null,
    ]);

    $response = $this->actingAs($actor)
        ->get(route('admin.companies.show', $company->doc_num))
        ->assertOk()
        ->assertSee(__('common.fields.email'))
        ->assertSee(__('common.fields.phone'))
        ->assertSee('id="company-mobile"', false)
        ->assertSee('id="company-email"', false)
        ->assertSee('id="company-hotline"', false)
        ->assertSee('id="company-country-doc-num"', false)
        ->assertSee('id="company-governorate-doc-num"', false)
        ->assertSee('id="company-city-doc-num"', false)
        ->assertSee('id="company-area-doc-num"', false)
        ->assertSee('id="company-address"', false)
        ->assertSee('id="company-postal-code"', false)
        ->assertSee('id="company-map-url"', false)
        ->assertSee('value="'.__('common.empty_value').'"', false)
        ->assertSee('>'.__('common.empty_value').'</textarea>', false)
        ->assertDontSee('mailto:', false)
        ->assertDontSee('js-user-phone-contact', false)
        ->assertDontSee('value="'.__('common.messages.not_available').'"', false)
        ->assertDontSee('>'.__('common.messages.not_available').'<', false);

    foreach (['company-country-doc-num', 'company-governorate-doc-num', 'company-city-doc-num', 'company-area-doc-num'] as $fieldId) {
        expect($response->getContent())->toMatch('/<input[^>]*(?=[^>]*id="'.preg_quote($fieldId, '/').'")(?=[^>]*class="[^"]*erp-view-field-control)(?=[^>]*value="'.preg_quote(__('common.empty_value'), '/').'")[^>]*>/s');
    }

    foreach (['company-map-url'] as $fieldId) {
        expect($response->getContent())->toMatch('/<div[^>]*(?=[^>]*id="'.preg_quote($fieldId, '/').'")(?=[^>]*class="[^"]*erp-view-field-display)[^>]*>\\s*<span[^>]*>'.preg_quote(__('common.empty_value'), '/').'<\\/span>/s');
    }
});

test('company location select2 endpoints use doc nums and selected values hydrate by company doc num', function () {
    $actor = coreCompanyCrudActor(['companies.create', 'companies.edit', 'companies.view']);
    $country = HrCountry::query()->create(['doc_number' => 1, 'doc_num' => 'Country-00001', 'name' => 'Egypt']);
    $governorate = HrGovernorate::query()->create(['doc_number' => 1, 'doc_num' => 'Governorate-00001', 'name' => 'Cairo']);
    $city = HrCity::query()->create(['doc_number' => 1, 'doc_num' => 'City-00001', 'name' => 'Nasr City']);
    $area = HrArea::query()->create(['doc_number' => 1, 'doc_num' => 'Area-00001', 'name' => 'Abbas El Akkad']);

    $this->actingAs($actor)
        ->getJson(route('admin.select2.countries', ['q' => 'Egypt']))
        ->assertOk()
        ->assertJsonPath('results.0.id', $country->doc_num)
        ->assertJsonPath('results.0.text', $country->name)
        ->assertJsonMissing(['id' => $country->id]);

    app(SettingService::class)->set('date_format', 'd/m/Y');

    $this->postJson(route('admin.companies.store'), companyPayload([
        'name' => 'Location Company',
        'email' => 'location-company@example.com',
        'commercial_register_date' => '28/04/2026',
        'commercial_register_expiry_date' => '30/04/2026',
        'country_doc_num' => $country->doc_num,
        'governorate_doc_num' => $governorate->doc_num,
        'city_doc_num' => $city->doc_num,
        'area_doc_num' => $area->doc_num,
    ]))
        ->assertOk();

    $company = Company::query()->where('name', 'Location Company')->firstOrFail();

    expect($company->country_id)->toBe($country->id)
        ->and($company->governorate_id)->toBe($governorate->id)
        ->and($company->city_id)->toBe($city->id)
        ->and($company->area_id)->toBe($area->id)
        ->and($company->commercial_register_date?->toDateString())->toBe('2026-04-28')
        ->and($company->hotline)->toBe('16000');

    $this->getJson(route('admin.companies.location-selected', $company->doc_num))
        ->assertOk()
        ->assertJsonPath('country.id', $country->doc_num)
        ->assertJsonPath('governorate.id', $governorate->doc_num)
        ->assertJsonPath('city.id', $city->doc_num)
        ->assertJsonPath('area.id', $area->doc_num);

    $this->putJson(route('admin.companies.update', $company->doc_num), [
        'name' => $company->name,
        'email' => $company->email,
        'status' => $company->status,
        'country_doc_num' => '',
        'governorate_doc_num' => '',
        'city_doc_num' => '',
        'area_doc_num' => '',
    ])
        ->assertOk();

    expect($company->refresh()->country_id)->toBeNull()
        ->and($company->governorate_id)->toBeNull()
        ->and($company->city_id)->toBeNull()
        ->and($company->area_id)->toBeNull();

    $this->actingAs(coreCompanyCrudActor(['companies.create']))
        ->postJson(route('admin.companies.store'), companyPayload([
            'name' => 'Invalid Date Company',
            'email' => 'invalid-date-company@example.com',
            'commercial_register_date' => '2026/04/28',
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['commercial_register_date']);
});

test('company logo and favicon uploads validate files and log public asset actions', function () {
    Storage::fake('public');
    $actor = coreCompanyCrudActor(['companies.create', 'companies.edit', 'companies.main.control']);

    $this->actingAs($actor)
        ->postJson(route('admin.companies.store'), companyPayload([
            'name' => 'Logo Company',
            'email' => 'logo-company@example.com',
            'logo' => UploadedFile::fake()->image('logo.png')->size(64),
            'favicon' => UploadedFile::fake()->image('favicon.png')->size(16),
        ]))
        ->assertOk();

    $company = Company::query()->where('name', 'Logo Company')->firstOrFail();

    $logoActivity = Activity::query()->where('action', 'companies.logo.upload')->firstOrFail();
    $faviconActivity = Activity::query()->where('action', 'companies.favicon.upload')->firstOrFail();

    expect($company->logo)->not->toBeNull()
        ->and($company->favicon)->not->toBeNull()
        ->and($company->logo)->not->toContain('logo.png')
        ->and($company->favicon)->not->toContain('favicon.png')
        ->and($logoActivity->properties->get('company_doc_num'))->toBe($company->doc_num)
        ->and($logoActivity->properties->get('logo_original_name'))->toBe('logo.png')
        ->and($logoActivity->properties->get('logo_extension'))->toBe('png')
        ->and($logoActivity->properties->get('logo_size_bytes'))->toBeGreaterThan(0)
        ->and($faviconActivity->properties->get('company_doc_num'))->toBe($company->doc_num)
        ->and($faviconActivity->properties->get('favicon_original_name'))->toBe('favicon.png')
        ->and($faviconActivity->properties->get('favicon_extension'))->toBe('png')
        ->and($faviconActivity->properties->get('favicon_size_bytes'))->toBeGreaterThan(0)
        ->and($logoActivity->properties->has('logo'))->toBeFalse()
        ->and($faviconActivity->properties->has('favicon'))->toBeFalse()
        ->and($logoActivity->properties->has('path'))->toBeFalse()
        ->and($faviconActivity->properties->has('path'))->toBeFalse()
        ->and($logoActivity->properties->has('id'))->toBeFalse();

    Storage::disk('public')->assertExists($company->logo);
    Storage::disk('public')->assertExists($company->favicon);

    $this->actingAs($actor)
        ->postJson(route('admin.companies.store'), companyPayload([
            'name' => 'Bad Logo Company',
            'email' => 'bad-logo-company@example.com',
            'logo' => UploadedFile::fake()->create('bad.pdf', 64, 'application/pdf'),
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['logo']);

    $this->actingAs($actor)
        ->postJson(route('admin.companies.store'), companyPayload([
            'name' => 'Bad Favicon Company',
            'email' => 'bad-favicon-company@example.com',
            'favicon' => UploadedFile::fake()->create('bad.svg', 4, 'image/svg+xml'),
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['favicon']);

    $this->actingAs($actor)
        ->putJson(route('admin.companies.update', $company->doc_num), [
            'name' => $company->name,
            'email' => $company->email,
            'status' => $company->status,
            'is_main' => '1',
            'remove_logo' => '1',
            'remove_favicon' => '1',
        ])
        ->assertOk();

    expect($company->refresh()->logo)->toBeNull()
        ->and($company->favicon)->toBeNull()
        ->and(Activity::query()->where('action', 'companies.logo.delete')->exists())->toBeTrue()
        ->and(Activity::query()->where('action', 'companies.favicon.delete')->exists())->toBeTrue();
});

test('companies datatable returns contact columns and no internal ids', function () {
    $actor = coreCompanyCrudActor(['companies.view', 'companies.edit', 'companies.clone', 'companies.delete']);
    $company = Company::factory()->main()->create([
        'doc_number' => 51,
        'doc_num' => 'Company-00051',
        'name' => 'Data Company',
        'email' => 'data-company@example.com',
        'phone' => '+20 100 123 4567',
        'city' => 'Cairo',
    ]);

    $response = $this->actingAs($actor)
        ->getJson(route('admin.companies.data', [
            'draw' => 1,
            'start' => 0,
            'length' => 10,
            'search' => ['value' => 'Data&&Company-00051'],
        ]))
        ->assertOk()
        ->json();

    expect($response['data'][0])
        ->toHaveKeys(['checkbox', 'doc_num', 'name', 'email', 'phone', 'is_main', 'status', 'actions'])
        ->not->toHaveKey('id')
        ->and($response['data'][0]['doc_num'])->toContain($company->doc_num)
        ->and($response['data'][0]['email'])->toContain('href="mailto:data-company@example.com"')
        ->and($response['data'][0]['phone'])->toContain('js-user-phone-contact')
        ->and($response['data'][0]['phone'])->toContain('data-whatsapp-url="https://wa.me/201001234567"')
        ->and($response['data'][0]['is_main'])->toContain(__('companies.badges.main'))
        ->and($response['data'][0]['actions'])->toContain(route('admin.companies.edit', $company->doc_num))
        ->and($response['data'][0]['actions'])->not->toContain('data-id=');
});

test('companies datatable leaves optional empty fields blank', function () {
    $actor = coreCompanyCrudActor(['companies.view']);
    $company = Company::factory()->create([
        'doc_number' => 52,
        'doc_num' => 'Company-00052',
        'name' => 'Empty Fields Company',
        'legal_name' => null,
        'commercial_name' => null,
        'phone' => null,
        'mobile' => null,
        'hotline' => null,
        'email' => null,
        'city' => null,
        'city_id' => null,
        'is_main' => false,
    ]);

    $row = $this->actingAs($actor)
        ->getJson(route('admin.companies.data', [
            'draw' => 1,
            'start' => 0,
            'length' => 10,
            'search' => ['value' => 'Company-00052'],
        ]))
        ->assertOk()
        ->json('data.0');

    expect($row['doc_num'])->toContain($company->doc_num)
        ->and(trim($row['legal_name']))->toBe('')
        ->and(trim($row['commercial_name']))->toBe('')
        ->and($row)->not->toHaveKey('code')
        ->and(trim($row['phone']))->toBe('')
        ->and(trim($row['email']))->toBe('')
        ->and(trim($row['city']))->toBe('')
        ->and(trim($row['is_main']))->toBe('')
        ->and(implode(' ', $row))->not->toContain(__('common.messages.not_available'));
});

test('companies datatable filters active trashed and all records with correct row actions', function () {
    $active = Company::factory()->create([
        'doc_number' => 151,
        'doc_num' => 'Company-00151',
        'name' => 'Active Filter Company',
        'email' => 'active-filter-company@example.com',
        'is_main' => true,
    ]);
    $trashed = Company::factory()->create([
        'doc_number' => 152,
        'doc_num' => 'Company-00152',
        'name' => 'Trashed Filter Company',
        'email' => 'trashed-filter-company@example.com',
    ]);
    $trashed->delete();

    $viewer = coreCompanyCrudActor(['companies.view', 'companies.edit', 'companies.clone', 'companies.delete']);

    $plainResponse = $this->actingAs($viewer)
        ->getJson(route('admin.companies.data', [
            'draw' => 1,
            'start' => 0,
            'length' => 10,
            'trash_filter' => 'trashed',
            'search' => ['value' => 'Filter Company'],
        ]))
        ->assertOk()
        ->json();
    $plainDocNums = collect($plainResponse['data'])->pluck('doc_num')->implode(' ');

    expect($plainDocNums)->toContain($active->doc_num)
        ->not->toContain($trashed->doc_num);

    $trashViewer = coreCompanyCrudActor([
        'companies.view',
        'companies.view_trashed',
        'companies.restore',
        'companies.edit',
        'companies.clone',
        'companies.delete',
    ]);

    $activeResponse = $this->actingAs($trashViewer)
        ->getJson(route('admin.companies.data', [
            'draw' => 2,
            'start' => 0,
            'length' => 10,
            'trash_filter' => 'active',
            'search' => ['value' => 'Filter Company'],
        ]))
        ->assertOk()
        ->json();
    $activeRow = $activeResponse['data'][0];

    expect($activeRow['doc_num'])->toContain($active->doc_num)
        ->and($activeRow['checkbox'])->toContain($active->doc_num)
        ->and($activeRow['actions'])->toContain('js-edit-record')
        ->and($activeRow['actions'])->toContain('js-clone-record')
        ->and($activeRow['actions'])->toContain('data-company-delete-url');

    $trashedResponse = $this->getJson(route('admin.companies.data', [
        'draw' => 3,
        'start' => 0,
        'length' => 10,
        'trash_filter' => 'trashed',
        'search' => ['value' => 'Filter Company'],
    ]))
        ->assertOk()
        ->json();
    $trashedRow = $trashedResponse['data'][0];

    expect($trashedRow['doc_num'])->toContain($trashed->doc_num)
        ->and($trashedRow['doc_num'])->toContain(route('admin.companies.show', $trashed->doc_num))
        ->and($trashedRow['checkbox'])->not->toContain('js-record-select')
        ->and($trashedRow['status'])->toContain(__('companies.trash.trashed'))
        ->and($trashedRow['actions'])->toContain(route('admin.companies.show', $trashed->doc_num))
        ->and($trashedRow['actions'])->toContain('data-company-restore-url')
        ->and($trashedRow['actions'])->toContain('data-restore-url')
        ->and($trashedRow['actions'])->toContain('js-restore-record')
        ->and($trashedRow['actions'])->toContain(route('admin.companies.restore', $trashed->doc_num))
        ->and($trashedRow['actions'])->not->toContain('js-edit-record')
        ->and($trashedRow['actions'])->not->toContain('js-clone-record')
        ->and($trashedRow['actions'])->not->toContain('data-company-delete-url')
        ->and($trashedRow)->not->toHaveKey('id');

    $allResponse = $this->getJson(route('admin.companies.data', [
        'draw' => 4,
        'start' => 0,
        'length' => 10,
        'trash_filter' => 'all',
        'search' => ['value' => 'Filter Company'],
    ]))
        ->assertOk()
        ->json();
    $allDocNums = collect($allResponse['data'])->pluck('doc_num')->implode(' ');

    expect($allDocNums)->toContain($active->doc_num)
        ->and($allDocNums)->toContain($trashed->doc_num);
});

test('companies can be created with generated document number and no fake update tracking', function () {
    $actor = coreCompanyCrudActor(['companies.create', 'companies.edit', 'companies.view', 'companies.document_number.control']);

    $this->actingAs($actor)
        ->postJson(route('admin.companies.store'), companyPayload([
            'doc_number' => '',
            'submit_action' => 'save_edit',
        ]))
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure(['data' => ['doc_num', 'doc_number', 'urls']]);

    $company = Company::query()->where('name', 'Acme Company')->firstOrFail();

    expect($company->doc_num)->toStartWith('Company-')
        ->and($company->created_by)->toBe($actor->id)
        ->and($company->updated_by)->toBeNull()
        ->and($company->updated_at)->toBeNull()
        ->and($company->deleted_by)->toBeNull()
        ->and($company->deleted_at)->toBeNull()
        ->and($company->restored_by)->toBeNull()
        ->and($company->restored_at)->toBeNull()
        ->and($company->is_main)->toBeTrue();

    $activity = Activity::query()->where('action', 'companies.create')->firstOrFail();

    expect(data_get($activity->properties->toArray(), 'record.doc_num'))->toBe($company->doc_num)
        ->and(data_get($activity->properties->toArray(), 'meta.submit_action'))->toBe('save_edit')
        ->and($activity->properties->has('id'))->toBeFalse()
        ->and($activity->properties->has('code'))->toBeFalse();
});

test('companies validate active uniqueness and manual document numbers', function () {
    $actor = coreCompanyCrudActor(['companies.create', 'companies.document_number.control']);

    Company::factory()->main()->create([
        'name' => 'Existing Company',
        'doc_number' => 1,
        'doc_num' => 'Company-00001',
    ]);

    $this->actingAs($actor)
        ->postJson(route('admin.companies.store'), companyPayload([
            'name' => 'Existing Company',
            'email' => 'unique-company@example.com',
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);

    $this->actingAs($actor)
        ->postJson(route('admin.companies.store'), companyPayload([
            'name' => 'Manual Company',
            'email' => 'manual-company@example.com',
            'doc_number' => '0001',
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['doc_number']);
});

test('companies update supports document number changes and no-change response', function () {
    $actor = coreCompanyCrudActor(['companies.edit', 'companies.view', 'companies.document_number.control', 'companies.main.control']);
    $previousUpdatedAt = now()->subHours(4)->startOfSecond();
    $company = Company::factory()->main()->create([
        'name' => 'Editable Company',
        'email' => 'editable-company@example.com',
        'notes' => 'Old notes',
        'updated_by' => $actor->id,
        'updated_at' => $previousUpdatedAt,
    ]);

    $payload = [
        'name' => $company->name,
        'email' => $company->email,
        'status' => $company->status,
        'notes' => $company->notes,
        'doc_number' => $company->doc_number,
        'is_main' => '1',
    ];

    $this->actingAs($actor)
        ->putJson(route('admin.companies.update', $company->doc_num), $payload)
        ->assertOk()
        ->assertJsonPath('type', 'no_changes');

    $company->refresh();

    expect($company->updated_by)->toBe($actor->id)
        ->and($company->updated_at?->toDateTimeString())->toBe($previousUpdatedAt->toDateTimeString());

    $this->actingAs($actor)
        ->putJson(route('admin.companies.update', $company->doc_num), [
            ...$payload,
            'doc_number' => '77',
            'notes' => 'New notes',
        ])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.doc_num', 'Company-00077');

    $company->refresh();

    expect($company->doc_number)->toBe(77)
        ->and($company->doc_num)->toBe('Company-00077')
        ->and($company->updated_by)->toBe($actor->id)
        ->and(Activity::query()->where('action', 'companies.doc_number.changed')->exists())->toBeTrue();
});

test('main company rules keep one active main company', function () {
    $actor = coreCompanyCrudActor(['companies.create', 'companies.edit', 'companies.main.control']);
    $first = Company::factory()->main()->create(['name' => 'Main Company']);

    $this->actingAs($actor)
        ->postJson(route('admin.companies.store'), companyPayload([
            'name' => 'Second Main',
            'email' => 'second-main@example.com',
            'is_main' => '1',
        ]))
        ->assertOk();

    $second = Company::query()->where('name', 'Second Main')->firstOrFail();

    expect($second->is_main)->toBeTrue()
        ->and($first->refresh()->is_main)->toBeFalse();

    $mainActivity = Activity::query()->where('action', 'companies.main.changed')->firstOrFail();

    expect($mainActivity->properties->get('old_company_doc_num'))->toBe($first->doc_num)
        ->and($mainActivity->properties->get('old_company_name'))->toBe($first->name)
        ->and($mainActivity->properties->get('new_company_doc_num'))->toBe($second->doc_num)
        ->and($mainActivity->properties->get('new_company_name'))->toBe($second->name)
        ->and($mainActivity->properties->has('old_main'))->toBeFalse()
        ->and($mainActivity->properties->has('new_main'))->toBeFalse();

    $this->actingAs($actor)
        ->putJson(route('admin.companies.update', $second->doc_num), companyPayload([
            'name' => $second->name,
            'email' => $second->email,
            'status' => 'inactive',
            'is_main' => '1',
        ]))
        ->assertUnprocessable()
        ->assertJsonPath('message', __('erp_errors.validation_failed'))
        ->assertJsonPath('error_code', 'validation_failed')
        ->assertJsonPath('errors.is_main.0', __('companies.validation.main_requires_active'));
});

test('company clone does not copy document number and financial settings are absent', function () {
    $actor = coreCompanyCrudActor(['companies.clone', 'companies.create', 'companies.edit']);
    $source = Company::factory()->main()->create([
        'name' => 'Source Company',
        'logo' => 'company-logos/source-logo.png',
        'favicon' => 'company-favicons/source-favicon.png',
        'notes' => 'Source notes',
    ]);

    $clonePage = $this->actingAs($actor)
        ->get(route('admin.companies.clone', $source->doc_num))
        ->assertOk()
        ->assertSee(__('companies.defaults.clone_name', ['name' => $source->name]))
        ->assertDontSee('value="'.$source->doc_number.'"', false)
        ->assertDontSee('data-current-url="'.Storage::disk('public')->url($source->logo).'"', false)
        ->assertDontSee('data-current-url="'.Storage::disk('public')->url($source->favicon).'"', false)
        ->assertDontSee('currency_code', false)
        ->assertDontSee('tax_enabled', false);

    preg_match('/name="clone_source_token" value="([^"]+)"/', $clonePage->getContent(), $matches);

    $this->actingAs($actor)
        ->withSession(['companies.clone_sources.'.$matches[1] => $source->doc_num])
        ->postJson(route('admin.companies.store'), companyPayload([
            'clone_source_token' => $matches[1],
            'name' => 'Copy of Source Company',
            'email' => 'source-copy@example.com',
            'notes' => 'Source notes',
        ]))
        ->assertOk()
        ->assertJsonPath('message', __('companies.messages.cloned'))
        ->assertJsonPath('submit_action', 'save_new')
        ->assertJsonPath('reset_form', true);

    $clone = Company::query()->where('name', 'Copy of Source Company')->firstOrFail();

    expect($clone->doc_num)->not->toBe($source->doc_num)
        ->and($clone->logo)->toBeNull()
        ->and($clone->favicon)->toBeNull()
        ->and(Activity::query()->where('action', 'companies.clone')->exists())->toBeTrue();
});

test('company delete blocks main company and deletes non-main company by doc num', function () {
    $actor = coreCompanyCrudActor(['companies.delete']);
    $previousUpdatedAt = now()->subHours(3)->startOfSecond();
    $main = Company::factory()->main()->create(['name' => 'Protected Main']);
    $secondary = Company::factory()->create([
        'name' => 'Deletable Company',
        'updated_by' => $actor->id,
        'updated_at' => $previousUpdatedAt,
    ]);

    $this->actingAs($actor)
        ->deleteJson(route('admin.companies.destroy', $main->doc_num))
        ->assertStatus(409)
        ->assertJsonPath('error_code', 'record_in_use')
        ->assertJsonPath('message', __('companies.messages.main_company_delete_blocked'));

    $this->actingAs($actor)
        ->deleteJson(route('admin.companies.destroy', $secondary->doc_num))
        ->assertOk()
        ->assertJsonPath('success', true);

    expect($secondary->refresh()->trashed())->toBeTrue()
        ->and($secondary->deleted_by)->toBe($actor->id)
        ->and($secondary->deleted_at)->not->toBeNull()
        ->and($secondary->updated_by)->toBe($actor->id)
        ->and($secondary->updated_at?->toDateTimeString())->toBe($previousUpdatedAt->toDateTimeString())
        ->and($secondary->restored_by)->toBeNull()
        ->and($secondary->restored_at)->toBeNull()
        ->and(Activity::query()->where('action', 'companies.delete_blocked')->where('status', 'blocked')->exists())->toBeTrue()
        ->and(Activity::query()->where('action', 'companies.delete')->exists())->toBeTrue();
});

test('trashed company can be viewed only with deleted-record permission and cannot be edited cloned updated or normal deleted', function () {
    $trashed = Company::factory()->create([
        'doc_number' => 801,
        'doc_num' => 'Company-00801',
        'name' => 'Trashed Route Company',
        'email' => 'trashed-route-company@example.com',
    ]);
    $trashed->delete();
    $deleter = User::factory()->create([
        'name' => 'Company Deleter',
        'doc_num' => 'User-00913',
    ]);
    $trashed->forceFill(['deleted_by' => $deleter->id])->saveQuietly();
    $trashed = Company::withTrashed()->whereKey($trashed->getKey())->firstOrFail();

    $viewer = coreCompanyCrudActor(['companies.view', 'companies.edit', 'companies.clone', 'companies.delete', 'companies.restore']);

    $this->actingAs($viewer)
        ->get(route('admin.companies.show', $trashed->doc_num))
        ->assertNotFound();

    $trashViewer = coreCompanyCrudActor([
        'companies.view',
        'companies.view_trashed',
        'companies.edit',
        'companies.clone',
        'companies.delete',
        'companies.restore',
    ]);

    $this->actingAs($trashViewer)
        ->get(route('admin.companies.show', $trashed->doc_num))
        ->assertOk()
        ->assertSee(__('common.fields.deleted_by'))
        ->assertSee(__('common.fields.deleted_at'))
        ->assertDontSee(__('common.fields.restored_by'))
        ->assertDontSee(__('common.fields.restored_at'))
        ->assertSee('Company Deleter / User-00913')
        ->assertSee(app(SettingService::class)->formatDateTime($trashed->deleted_at))
        ->assertDontSee('value="'.$deleter->id.'"', false)
        ->assertDontSee('data-id=', false)
        ->assertSee('data-company-restore-url', false)
        ->assertSee('data-restore-url', false)
        ->assertSee(__('companies.trash.restore'))
        ->assertDontSee('data-shortcut-action="form.edit"', false)
        ->assertDontSee('data-shortcut-action="form.clone"', false)
        ->assertDontSee('data-company-delete-url', false)
        ->assertDontSee('data-shortcut-action="form.delete"', false);

    $this->actingAs($trashViewer)
        ->get(route('admin.companies.edit', $trashed->doc_num))
        ->assertNotFound();

    $this->actingAs($trashViewer)
        ->putJson(route('admin.companies.update', $trashed->doc_num), [
            'name' => 'Blocked Company Update',
            'status' => 'active',
        ])
        ->assertNotFound();

    $this->actingAs($trashViewer)
        ->get(route('admin.companies.clone', $trashed->doc_num))
        ->assertNotFound();

    $this->actingAs($trashViewer)
        ->deleteJson(route('admin.companies.destroy', $trashed->doc_num))
        ->assertNotFound();
});

test('trashed company can be restored by public document number and logs public activity', function () {
    $restorer = coreCompanyCrudActor(['companies.restore']);
    $updater = User::factory()->create();
    $previousUpdatedAt = now()->subHours(5)->startOfSecond();
    $company = Company::factory()->create([
        'doc_number' => 811,
        'doc_num' => 'Company-00811',
        'name' => 'Restorable Company',
        'email' => 'restorable-company@example.com',
        'updated_by' => $updater->id,
        'updated_at' => $previousUpdatedAt,
    ]);
    $company->forceFill(['deleted_by' => $restorer->id])->saveQuietly();
    $company->delete();
    $company->getConnection()
        ->table($company->getTable())
        ->where($company->getKeyName(), $company->getKey())
        ->update([
            'updated_by' => $updater->id,
            'updated_at' => $previousUpdatedAt,
            'deleted_by' => $restorer->id,
        ]);

    $this->actingAs(coreCompanyCrudActor(['companies.view_trashed']))
        ->patchJson(route('admin.companies.restore', $company->doc_num))
        ->assertForbidden();

    $this->actingAs($restorer)
        ->patchJson(route('admin.companies.restore', $company->doc_num))
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', __('companies.messages.restored'));

    $company->refresh();
    $activity = Activity::query()->where('action', 'companies.restore')->firstOrFail();

    expect($company->trashed())->toBeFalse()
        ->and($company->deleted_by)->toBeNull()
        ->and($company->deleted_at)->toBeNull()
        ->and($company->restored_by)->toBe($restorer->id)
        ->and($company->restored_at)->not->toBeNull()
        ->and((int) $company->updated_by)->toBe($updater->id)
        ->and($company->updated_at?->toDateTimeString())->toBe($previousUpdatedAt->toDateTimeString())
        ->and($activity->module)->toBe('core')
        ->and(data_get($activity->properties->toArray(), 'record.doc_num'))->toBe('Company-00811')
        ->and(data_get($activity->properties->toArray(), 'record.label'))->toBe('Restorable Company')
        ->and(data_get($activity->properties->toArray(), 'meta.code'))->toBeNull()
        ->and(data_get($activity->properties->toArray(), 'meta.restored_by_user_doc_num'))->toBe($restorer->doc_num)
        ->and(data_get($activity->properties->toArray(), 'meta.restored_at'))->not->toBeNull()
        ->and($activity->properties->has('id'))->toBeFalse()
        ->and($activity->properties->has('company_id'))->toBeFalse();

    $this->actingAs(coreCompanyCrudActor(['companies.view']))
        ->get(route('admin.companies.show', $company->doc_num))
        ->assertOk()
        ->assertSee(__('common.fields.restored_by'))
        ->assertSee(__('common.fields.restored_at'))
        ->assertSee($restorer->name.' / '.$restorer->doc_num)
        ->assertDontSee(__('common.fields.deleted_by'))
        ->assertDontSee(__('common.fields.deleted_at'))
        ->assertDontSee('value="'.$restorer->id.'"', false);

    $this->actingAs($restorer)
        ->patchJson(route('admin.companies.restore', $company->doc_num))
        ->assertUnprocessable()
        ->assertJsonPath('message', __('companies.messages.restore_not_allowed'))
        ->assertJsonPath('errors.restore.0', __('companies.messages.restore_not_allowed'))
        ->assertJsonMissingPath('data.id')
        ->assertJsonMissingPath('data.company_id');
});

test('trashed company restore is blocked when active companies reuse unique data', function () {
    foreach (['companies_name_unique_active', 'companies_doc_number_unique_active', 'companies_doc_num_unique_active'] as $index) {
        DB::statement("DROP INDEX IF EXISTS {$index}");
    }

    $restorer = coreCompanyCrudActor(['companies.restore']);
    $trashed = Company::factory()->create([
        'doc_number' => 821,
        'doc_num' => 'Company-00821',
        'name' => 'Duplicate Restore Company',
        'email' => 'deleted-duplicate-restore@example.com',
        'is_main' => true,
    ]);
    $trashed->delete();

    Company::factory()->create([
        'doc_number' => 821,
        'doc_num' => 'Company-00821',
        'name' => 'Duplicate Restore Company',
        'email' => 'active-duplicate-restore@example.com',
    ]);

    $this->actingAs($restorer)
        ->patchJson(route('admin.companies.restore', $trashed->doc_num))
        ->assertStatus(409)
        ->assertJsonPath('message', __('companies.messages.restore_conflict'))
        ->assertJsonPath('errors.restore.0', __('companies.messages.restore_conflict'))
        ->assertJsonPath('data.conflict_type', 'unique_data_conflict')
        ->assertJsonPath('data.conflict_fields', ['name', 'doc_number', 'doc_num'])
        ->assertJsonMissingPath('data.id')
        ->assertJsonMissingPath('data.company_id');

    $blockedActivity = Activity::query()->where('action', 'companies.restore_blocked')->firstOrFail();

    expect(Company::withTrashed()->whereKey($trashed->getKey())->firstOrFail()->trashed())->toBeTrue()
        ->and($blockedActivity->status)->toBe('blocked')
        ->and($blockedActivity->properties->get('doc_num'))->toBe('Company-00821')
        ->and($blockedActivity->properties->get('company_name'))->toBe('Duplicate Restore Company')
        ->and($blockedActivity->properties->get('conflict_type'))->toBe('unique_data_conflict')
        ->and($blockedActivity->properties->get('conflict_fields'))->toBe(['name', 'doc_number', 'doc_num'])
        ->and($blockedActivity->properties->has('id'))->toBeFalse()
        ->and($blockedActivity->properties->has('company_id'))->toBeFalse();
});

test('trashed main company restore is blocked when another active main company exists', function () {
    DB::statement('DROP INDEX IF EXISTS companies_one_active_main_unique');

    $restorer = coreCompanyCrudActor(['companies.restore']);
    $deletedMain = Company::factory()->main()->create([
        'doc_number' => 831,
        'doc_num' => 'Company-00831',
        'name' => 'Deleted Main Company',
        'email' => 'deleted-main-company@example.com',
    ]);
    $deletedMain->delete();
    Company::factory()->main()->create([
        'doc_number' => 832,
        'doc_num' => 'Company-00832',
        'name' => 'Active Main Company',
        'email' => 'active-main-company@example.com',
    ]);

    $this->actingAs($restorer)
        ->patchJson(route('admin.companies.restore', $deletedMain->doc_num))
        ->assertStatus(409)
        ->assertJsonPath('message', __('companies.messages.restore_main_conflict'))
        ->assertJsonPath('errors.restore.0', __('companies.messages.restore_main_conflict'))
        ->assertJsonPath('data.conflict_type', 'main_company_conflict')
        ->assertJsonPath('data.conflict_fields', ['is_main'])
        ->assertJsonMissingPath('data.id')
        ->assertJsonMissingPath('data.company_id');

    expect(Company::withTrashed()->whereKey($deletedMain->getKey())->firstOrFail()->trashed())->toBeTrue();
});

test('company bulk delete stays all or nothing when selected records include trashed companies', function () {
    $actor = coreCompanyCrudActor(['companies.delete']);
    $active = Company::factory()->create([
        'doc_number' => 841,
        'doc_num' => 'Company-00841',
        'name' => 'Bulk Active Company',
        'email' => 'bulk-active-company@example.com',
        'is_main' => true,
    ]);
    $trashed = Company::factory()->create([
        'doc_number' => 842,
        'doc_num' => 'Company-00842',
        'name' => 'Bulk Trashed Company',
        'email' => 'bulk-trashed-company@example.com',
    ]);
    $trashed->delete();

    $this->actingAs($actor)
        ->deleteJson(route('admin.companies.bulk-delete'), [
            'doc_nums' => [$active->doc_num, $trashed->doc_num],
        ])
        ->assertUnprocessable();

    expect($active->refresh()->trashed())->toBeFalse();
});

test('operational print identity defaults off and toggles independently without breaking no-op updates', function (): void {
    $company = Company::factory()->create();
    $actor = coreCompanyCrudActor(['companies.edit', 'companies.view']);
    $this->actingAs($actor);
    $service = app(CompanyService::class);
    $identity = app(CompanyPrintIdentityService::class);
    expect($company->show_company_identity_on_prints)->toBeFalse()
        ->and($identity->shouldShow('operational', $company))->toBeFalse()
        ->and($identity->shouldShow('quotation', $company))->toBeTrue()
        ->and($identity->shouldShow('legal', $company))->toBeTrue();
    $service->update($company, ['show_company_identity_on_prints' => true]);
    $company->refresh();
    expect($identity->shouldShow('operational', $company))->toBeTrue();
    $previousUpdate = $company->updated_at->toDateTimeString();
    $this->travel(1)->minutes();
    $service->update($company, ['show_company_identity_on_prints' => true]);
    expect($company->fresh()->updated_at->toDateTimeString())->toBe($previousUpdate);
    $service->update($company, ['notes' => 'Preserves print setting']);
    expect($company->fresh()->show_company_identity_on_prints)->toBeTrue();
    $service->update($company, ['show_company_identity_on_prints' => false]);
    expect($company->fresh()->show_company_identity_on_prints)->toBeFalse()
        ->and($identity->forCompany($company->fresh())['name'])->toBe($company->name);
    $this->travelBack();
});
