<?php

use App\Models\User;
use Illuminate\Support\Str;
use Modules\Accounting\Models\Account;
use Modules\Auth\Database\Seeders\PermissionSeeder;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\Currency;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Models\Product;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\Reports\ProductDataReport;
use Modules\Purchases\Models\Supplier;
use Modules\Purchases\Models\SupplierCreditLimit;
use Modules\Sales\Models\Customer;
use Modules\Sales\Models\CustomerCreditLimit;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * @return array{company: Company, branch: Branch, period: FinancialPeriod}
 */
function businessPartnerReportContext(object $test, int $number = 8801): array
{
    $company = Company::query()->create([
        'doc_number' => $number,
        'doc_num' => 'Company-'.$number,
        'name' => 'Report Company',
        'status' => 'active',
        'is_main' => true,
    ]);
    $branch = Branch::query()->create([
        'doc_number' => $number,
        'doc_num' => 'Branch-'.$number,
        'company_id' => $company->getKey(),
        'name' => 'Report Branch',
        'type' => 'main',
        'status' => 'active',
    ]);
    $period = FinancialPeriod::query()->create([
        'doc_number' => $number,
        'doc_num' => 'Period-'.$number,
        'company_id' => $company->getKey(),
        'name' => 'Report Period',
        'from_date' => '2026-01-01',
        'to_date' => '2026-12-31',
        'is_closed' => false,
        'status' => 'active',
    ]);
    $session = [
        OperatingContextService::CompanyIdKey => $company->getKey(),
        OperatingContextService::CompanyDocNumKey => $company->doc_num,
        OperatingContextService::BranchIdKey => $branch->getKey(),
        OperatingContextService::BranchDocNumKey => $branch->doc_num,
        OperatingContextService::FinancialPeriodIdKey => $period->getKey(),
        OperatingContextService::FinancialPeriodDocNumKey => $period->doc_num,
    ];

    if (! request()->hasSession()) {
        request()->setLaravelSession(app('session.store'));
    }

    session($session);
    $test->withSession($session);

    return compact('company', 'branch', 'period');
}

function businessPartnerReportActor(array $permissions, string $locale = 'en'): User
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $user = User::factory()->create(['locale' => $locale]);
    $user->givePermissionTo($permissions);
    auth()->login($user);
    request()->setUserResolver(fn (): User => $user);

    return $user;
}

/**
 * @return array{customer: Customer, supplier: Supplier, customer_group: Account, customer_account: Account, supplier_group: Account, supplier_account: Account}
 */
function businessPartnerReportRecords(Company $company): array
{
    $customerGroup = businessPartnerReportAccount($company, 8810, '110', 'Retail Customers', 'Retail Customers', true);
    $customerAccount = businessPartnerReportAccount($company, 8811, '1101', 'Alpha Account', 'Alpha Account', false, $customerGroup);
    $supplierGroup = businessPartnerReportAccount($company, 8820, '210', 'Local Suppliers', 'Local Suppliers', true);
    $supplierAccount = businessPartnerReportAccount($company, 8821, '2101', 'Beta Account', 'Beta Account', false, $supplierGroup);
    $currency = Currency::query()->create([
        'doc_number' => 8801,
        'doc_num' => 'Currency-8801',
        'company_id' => $company->getKey(),
        'name' => 'Egyptian Pound',
        'name_en' => 'Egyptian Pound',
        'code' => 'EGP',
        'minor_unit_name' => 'Piastre',
        'minor_unit_factor' => 100,
        'is_main' => true,
        'status' => 'active',
    ]);
    $customer = Customer::query()->create([
        'doc_number' => 8801,
        'doc_num' => 'CUS-8801',
        'company_id' => $company->getKey(),
        'account_id' => $customerAccount->getKey(),
        'account_group_id' => $customerGroup->getKey(),
        'name' => '<span>Alpha Customer</span>',
        'status' => 'active',
        'phone' => '02-1111',
        'mobile' => '01000000001',
        'email' => 'alpha@example.test',
        'contact_person' => 'Alice',
        'address' => 'Cairo',
        'tax_number' => 'TAX-CUS-1',
        'commercial_register' => 'CR-CUS-1',
        'created_at' => '2026-04-10 09:00:00',
    ]);
    $supplier = Supplier::query()->create([
        'doc_number' => 8801,
        'doc_num' => 'SUP-8801',
        'company_id' => $company->getKey(),
        'account_id' => $supplierAccount->getKey(),
        'account_group_id' => $supplierGroup->getKey(),
        'name' => '<span>Beta Supplier</span>',
        'status' => 'active',
        'phone' => '02-2222',
        'mobile' => '01100000002',
        'email' => 'beta@example.test',
        'contact_person' => 'Bob',
        'address' => 'Giza',
        'tax_number' => 'TAX-SUP-1',
        'commercial_register' => 'CR-SUP-1',
        'created_at' => '2026-05-11 10:00:00',
    ]);
    $customer->forceFill(['created_at' => '2026-04-10 09:00:00'])->saveQuietly();
    $supplier->forceFill(['created_at' => '2026-05-11 10:00:00'])->saveQuietly();

    CustomerCreditLimit::query()->create([
        'public_uuid' => (string) Str::uuid(),
        'company_id' => $company->getKey(),
        'customer_id' => $customer->getKey(),
        'currency_id' => $currency->getKey(),
        'credit_limit' => 15000,
    ]);
    SupplierCreditLimit::query()->create([
        'public_uuid' => (string) Str::uuid(),
        'company_id' => $company->getKey(),
        'supplier_id' => $supplier->getKey(),
        'currency_id' => $currency->getKey(),
        'credit_limit' => 25000,
    ]);

    Customer::query()->create([
        'doc_number' => 8802,
        'doc_num' => 'CUS-8802',
        'company_id' => $company->getKey(),
        'name' => 'Inactive Customer',
        'status' => 'inactive',
        'created_at' => '2026-04-12 09:00:00',
    ]);
    Supplier::query()->create([
        'doc_number' => 8802,
        'doc_num' => 'SUP-8802',
        'company_id' => $company->getKey(),
        'name' => 'Inactive Supplier',
        'status' => 'inactive',
        'created_at' => '2026-05-12 10:00:00',
    ]);

    $deletedCustomer = Customer::query()->create([
        'doc_number' => 8803,
        'doc_num' => 'CUS-8803',
        'company_id' => $company->getKey(),
        'name' => 'Deleted Customer',
        'status' => 'active',
    ]);
    $deletedSupplier = Supplier::query()->create([
        'doc_number' => 8803,
        'doc_num' => 'SUP-8803',
        'company_id' => $company->getKey(),
        'name' => 'Deleted Supplier',
        'status' => 'active',
    ]);
    $deletedCustomer->delete();
    $deletedSupplier->delete();

    $otherCompany = Company::query()->create([
        'doc_number' => 8899,
        'doc_num' => 'Company-8899',
        'name' => 'Other Company',
        'status' => 'active',
        'is_main' => false,
    ]);
    Customer::query()->create([
        'doc_number' => 8899,
        'doc_num' => 'CUS-8899',
        'company_id' => $otherCompany->getKey(),
        'name' => 'Other Company Customer',
        'status' => 'active',
    ]);
    Supplier::query()->create([
        'doc_number' => 8899,
        'doc_num' => 'SUP-8899',
        'company_id' => $otherCompany->getKey(),
        'name' => 'Other Company Supplier',
        'status' => 'active',
    ]);

    return compact('customer', 'supplier', 'customerGroup', 'customerAccount', 'supplierGroup', 'supplierAccount') + [
        'customer_group' => $customerGroup,
        'customer_account' => $customerAccount,
        'supplier_group' => $supplierGroup,
        'supplier_account' => $supplierAccount,
    ];
}

function businessPartnerReportAccount(Company $company, int $number, string $code, string $name, string $nameEn, bool $isGroup, ?Account $parent = null): Account
{
    return Account::query()->create([
        'doc_number' => $number,
        'doc_num' => 'Account-'.$number,
        'company_id' => $company->getKey(),
        'account_code' => $code,
        'name' => $name,
        'name_en' => $nameEn,
        'parent_id' => $parent?->getKey(),
        'level' => $parent ? 2 : 1,
        'account_type' => str_starts_with($code, '2') ? Account::TypeLiability : Account::TypeAsset,
        'statement_type' => Account::StatementFinancialPosition,
        'normal_balance' => str_starts_with($code, '2') ? Account::BalanceCredit : Account::BalanceDebit,
        'is_group' => $isGroup,
        'is_postable' => ! $isGroup,
        'status' => 'active',
    ]);
}

/**
 * @return array<string, mixed>
 */
function businessPartnerReportDataTablePayload(?string $search = null): array
{
    $columns = [
        'doc_num', 'name', 'account_group', 'account', 'phone', 'mobile', 'email', 'contact_person', 'address',
        'country', 'governorate', 'city', 'area', 'tax_number', 'commercial_register', 'credit_limits', 'status', 'created_at',
    ];

    return [
        'draw' => 1,
        'start' => 0,
        'length' => 10,
        'search' => ['value' => $search ?? '', 'regex' => 'false'],
        'order' => [['column' => 0, 'dir' => 'desc']],
        'columns' => collect($columns)->map(fn (string $column): array => [
            'data' => $column,
            'name' => $column,
            'searchable' => 'true',
            'orderable' => $column === 'credit_limits' ? 'false' : 'true',
            'search' => ['value' => '', 'regex' => 'false'],
        ])->all(),
    ];
}

test('business partner report permissions are synchronized and every endpoint is protected', function (): void {
    $this->seed(PermissionSeeder::class);

    foreach ([
        'reports.customers.view',
        'reports.customers.export',
        'reports.customers.pdf',
        'reports.suppliers.view',
        'reports.suppliers.export',
        'reports.suppliers.pdf',
    ] as $permission) {
        expect(Permission::query()->where('name', $permission)->where('guard_name', 'web')->exists())->toBeTrue();
    }

    $actor = businessPartnerReportActor([]);

    foreach ([
        'admin.reports.customers.index',
        'admin.reports.customers.export.excel',
        'admin.reports.customers.export.csv',
        'admin.reports.customers.export.pdf',
        'admin.reports.suppliers.index',
        'admin.reports.suppliers.export.excel',
        'admin.reports.suppliers.export.csv',
        'admin.reports.suppliers.export.pdf',
    ] as $routeName) {
        $this->actingAs($actor)->get(route($routeName))->assertForbidden();
    }
});

test('customer and supplier report pages render their shared responsive UI in both directions', function (): void {
    businessPartnerReportContext($this);
    $actor = businessPartnerReportActor([
        'reports.customers.view',
        'reports.customers.export',
        'reports.customers.pdf',
        'reports.suppliers.view',
        'reports.suppliers.export',
        'reports.suppliers.pdf',
    ]);

    foreach (['customers', 'suppliers'] as $report) {
        $response = $this->actingAs($actor)->get(route("admin.reports.{$report}.index"));

        $response->assertOk()
            ->assertSee('dir="ltr"', false)
            ->assertSee("{$report}-data-report", false)
            ->assertSee('business-partner-data-report.js', false)
            ->assertSee('business-partner-data-report.css', false)
            ->assertSee('name="doc_num"', false)
            ->assertSee('name="name"', false)
            ->assertSee('name="phone"', false)
            ->assertSee('name="account_group_doc_num"', false)
            ->assertSee('name="account_doc_num"', false)
            ->assertSee('name="status"', false)
            ->assertSee('name="created_from"', false)
            ->assertSee('name="created_to"', false)
            ->assertSee(route("admin.reports.{$report}.export.excel"), false)
            ->assertSee(route("admin.reports.{$report}.export.csv"), false)
            ->assertSee(route("admin.reports.{$report}.export.pdf"), false)
            ->assertDontSee('Print');
    }

    $actor->forceFill(['locale' => 'ar'])->save();

    foreach (['customers', 'suppliers'] as $report) {
        $this->actingAs($actor)
            ->get(route("admin.reports.{$report}.index"))
            ->assertOk()
            ->assertSee('lang="ar" dir="rtl"', false)
            ->assertSee(__("business_partner_reports.{$report}.title"));
    }
});

test('partner report data is company scoped filterable duplicate free and plain text only', function (): void {
    $context = businessPartnerReportContext($this);
    $actor = businessPartnerReportActor([
        'reports.customers.view',
        'reports.suppliers.view',
    ]);
    $records = businessPartnerReportRecords($context['company']);
    $this->actingAs($actor);

    foreach ([
        'customers' => [
            'record' => $records['customer'],
            'name' => 'Alpha Customer',
            'phone' => '01000000001',
            'group' => $records['customer_group'],
            'account' => $records['customer_account'],
            'created_from' => '2026-04-01',
            'created_to' => '2026-04-30',
            'credit_limit' => 'EGP / Egyptian Pound: 15,000',
        ],
        'suppliers' => [
            'record' => $records['supplier'],
            'name' => 'Beta Supplier',
            'phone' => '01100000002',
            'group' => $records['supplier_group'],
            'account' => $records['supplier_account'],
            'created_from' => '2026-05-01',
            'created_to' => '2026-05-31',
            'credit_limit' => 'EGP / Egyptian Pound: 25,000',
        ],
    ] as $report => $expected) {
        $payload = businessPartnerReportDataTablePayload(explode(' ', $expected['name'])[0]) + [
            'doc_num' => $expected['record']->doc_num,
            'name' => explode(' ', $expected['name'])[0],
            'phone' => $expected['phone'],
            'account_group_doc_num' => $expected['group']->doc_num,
            'account_doc_num' => $expected['account']->doc_num,
            'status' => 'active',
            'created_from' => $expected['created_from'],
            'created_to' => $expected['created_to'],
        ];
        $response = $this->getJson(route("admin.reports.{$report}.data", $payload));

        $response->assertOk()
            ->assertJsonPath('recordsFiltered', 1)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.doc_num', $expected['record']->doc_num)
            ->assertJsonPath('data.0.name', $expected['name'])
            ->assertJsonPath('data.0.credit_limits', $expected['credit_limit'])
            ->assertJsonMissing(['name' => 'Other Company Customer'])
            ->assertJsonMissing(['name' => 'Other Company Supplier'])
            ->assertJsonMissing(['name' => 'Deleted Customer'])
            ->assertJsonMissing(['name' => 'Deleted Supplier']);

        expect($response->json('data.0'))
            ->not->toHaveKeys(['id', 'company_id', 'doc_number', 'deleted_at', 'credit_limits.0.id'])
            ->and(json_encode($response->json('data.0'), JSON_THROW_ON_ERROR))->not->toContain('<span>');

        $this->getJson(route("admin.reports.{$report}.filter-options.accounts", ['q' => explode(' ', $expected['name'])[0]]))
            ->assertOk()
            ->assertJsonFragment(['id' => $expected['account']->doc_num]);
        $this->getJson(route("admin.reports.{$report}.filter-options.account-groups", ['q' => explode(' ', $expected['group']->name)[0]]))
            ->assertOk()
            ->assertJsonFragment(['id' => $expected['group']->doc_num]);
    }
});

test('all partner report exports apply filters and emit clean localized files', function (): void {
    $context = businessPartnerReportContext($this);
    $actor = businessPartnerReportActor([
        'reports.customers.view',
        'reports.customers.export',
        'reports.customers.pdf',
        'reports.suppliers.view',
        'reports.suppliers.export',
        'reports.suppliers.pdf',
    ]);
    businessPartnerReportRecords($context['company']);
    $this->actingAs($actor);

    foreach (['customers' => 'Alpha Customer', 'suppliers' => 'Beta Supplier'] as $report => $expectedName) {
        $term = explode(' ', $expectedName)[0];
        $excel = $this->get(route("admin.reports.{$report}.export.excel", ['name' => $term]));
        $excel->assertOk()->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $spreadsheet = IOFactory::load($excel->baseResponse->getFile()->getPathname());
        $sheet = $spreadsheet->getActiveSheet();

        expect($sheet->getCell('A1')->getValue())->toBe(__("business_partner_reports.{$report}.title"))
            ->and($sheet->getCell('B7')->getValue())->toBe($expectedName)
            ->and((string) $sheet->getCell('B8')->getValue())->toBe('');

        $csv = $this->get(route("admin.reports.{$report}.export.csv", ['name' => $term]));
        $csv->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $csvContents = file_get_contents($csv->baseResponse->getFile()->getPathname());

        expect($csvContents)->toStartWith("\xEF\xBB\xBF")
            ->and($csvContents)->toContain($expectedName)
            ->and($csvContents)->not->toContain('<span>')
            ->and($csvContents)->not->toContain('Inactive');

        $pdf = $this->get(route("admin.reports.{$report}.export.pdf", ['name' => $term]));
        $pdf->assertOk()->assertHeader('content-type', 'application/pdf');
        expect($pdf->getContent())->toStartWith('%PDF-');
    }
});

test('the existing product report strips stored markup from plain text fields', function (): void {
    $product = new Product;
    $product->forceFill([
        'doc_num' => 'PRD-1',
        'name' => '&lt;span class="badge"&gt;Plain Product&lt;/span&gt;',
        'barcode' => '<b>12345</b>',
        'item_classification' => Product::ClassificationFinishedProduct,
        'status' => 'active',
        'component_name' => '<span>Plain Component</span>',
        'component_notes' => '<em>Safe note</em>',
        'created_at' => now(),
    ]);

    $row = app(ProductDataReport::class)->row($product);

    expect($row['name'])->toBe('Plain Product')
        ->and($row['barcode'])->toBe('12345')
        ->and($row['component_name'])->toBe('Plain Component')
        ->and($row['component_notes'])->toBe('Safe note')
        ->and(json_encode($row, JSON_THROW_ON_ERROR))->not->toContain('<span>');
});
