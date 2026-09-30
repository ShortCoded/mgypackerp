<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Modules\Auth\Database\Seeders\PermissionSeeder;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\Currency;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\MenuService;
use Modules\Core\Services\OperatingContextService;
use Modules\Finance\Models\OpeningBalance;
use Modules\Inventory\Models\OpeningStock;
use Modules\Inventory\Models\OpeningStockPricing;
use Modules\Purchases\Models\GoodsReceiptInspection;
use Modules\Purchases\Models\PurchaseOrder;
use Modules\Purchases\Models\PurchaseOrderChangeRequest;
use Modules\Purchases\Models\PurchaseRequisition;
use Modules\Purchases\Services\ProcurementSourcingService;
use Modules\Purchases\Services\PurchaseOrderService;
use Modules\Sales\Models\CustomerInvoice;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesRequest;
use Modules\Sales\Services\SalesOrderService;
use Modules\Sales\Services\SalesRequestService;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

require_once dirname(__DIR__, 2).'/SalesCycleSupport.php';
require_once dirname(__DIR__, 2).'/ProcurementSupport.php';

function openDocumentsActor(array $permissions = []): User
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $user = User::factory()->create();

    if ($permissions !== []) {
        $user->givePermissionTo($permissions);
    }

    return $user;
}

function openDocumentsContext(object $test): array
{
    static $number = 12000;

    $number++;

    $company = Company::query()->create([
        'doc_number' => $number,
        'doc_num' => 'Company-'.str_pad((string) $number, 5, '0', STR_PAD_LEFT),
        'name' => 'Open Documents Company '.$number,
        'status' => 'active',
        'is_main' => ! Company::query()->exists(),
    ]);

    $branch = openDocumentsBranch($company, Branch::TypeWarehouse);

    $period = FinancialPeriod::query()->create([
        'doc_number' => $number,
        'doc_num' => 'Period-'.str_pad((string) $number, 5, '0', STR_PAD_LEFT),
        'company_id' => $company->getKey(),
        'name' => 'Open Documents Period '.$number,
        'from_date' => '2026-01-01',
        'to_date' => '2026-12-31',
        'is_closed' => false,
    ]);

    $currency = openDocumentsCurrency();

    $test->withSession([
        OperatingContextService::CompanyIdKey => $company->getKey(),
        OperatingContextService::CompanyDocNumKey => $company->doc_num,
        OperatingContextService::BranchIdKey => $branch->getKey(),
        OperatingContextService::BranchDocNumKey => $branch->doc_num,
        OperatingContextService::FinancialPeriodIdKey => $period->getKey(),
        OperatingContextService::FinancialPeriodDocNumKey => $period->doc_num,
    ]);

    return compact('company', 'branch', 'period', 'currency');
}

function openDocumentsBranch(Company $company, string $type): Branch
{
    static $number = 13000;

    $number++;

    return Branch::query()->create([
        'doc_number' => $number,
        'doc_num' => 'Branch-'.str_pad((string) $number, 5, '0', STR_PAD_LEFT),
        'company_id' => $company->getKey(),
        'name' => 'Open Documents Branch '.$number,
        'type' => $type,
        'status' => 'active',
    ]);
}

function openDocumentsPeriod(Company $company): FinancialPeriod
{
    static $number = 14000;

    $number++;

    return FinancialPeriod::query()->create([
        'doc_number' => $number,
        'doc_num' => 'Period-'.str_pad((string) $number, 5, '0', STR_PAD_LEFT),
        'company_id' => $company->getKey(),
        'name' => 'Other Period '.$number,
        'from_date' => '2027-01-01',
        'to_date' => '2027-12-31',
        'is_closed' => false,
    ]);
}

function openDocumentsCurrency(): Currency
{
    static $number = 15000;

    $number++;

    return Currency::query()->create([
        'doc_number' => $number,
        'doc_num' => 'Currency-'.str_pad((string) $number, 5, '0', STR_PAD_LEFT),
        'name' => 'Currency '.$number,
        'code' => 'OD'.$number,
        'is_main' => false,
        'status' => 'active',
    ]);
}

function openDocumentsOpeningBalance(Company $company, FinancialPeriod $period, Currency $currency, int $docNumber, array $overrides = []): OpeningBalance
{
    return OpeningBalance::query()->create([
        'company_id' => $company->getKey(),
        'financial_period_id' => $period->getKey(),
        'currency_id' => $currency->getKey(),
        'doc_number' => $docNumber,
        'doc_num' => 'OB-'.str_pad((string) $docNumber, 5, '0', STR_PAD_LEFT),
        'document_date' => '2026-02-01',
        'exchange_rate' => 1,
        'is_closed' => true,
        'approved' => false,
        'status' => OpeningBalance::StatusDraft,
        ...$overrides,
    ]);
}

function openDocumentsOpeningStock(Company $company, FinancialPeriod $period, Branch $branch, int $docNumber, array $overrides = []): OpeningStock
{
    return OpeningStock::query()->create([
        'company_id' => $company->getKey(),
        'financial_period_id' => $period->getKey(),
        'branch_id' => $branch->getKey(),
        'doc_number' => $docNumber,
        'doc_num' => 'OS-'.str_pad((string) $docNumber, 5, '0', STR_PAD_LEFT),
        'document_date' => '2026-02-01',
        'is_closed' => true,
        'approved' => false,
        'status' => OpeningStock::StatusClosed,
        ...$overrides,
    ]);
}

function openDocumentsOpeningStockPricing(Company $company, FinancialPeriod $period, Branch $branch, Currency $currency, int $docNumber, array $overrides = []): OpeningStockPricing
{
    $openingStock = openDocumentsOpeningStock($company, $period, $branch, $docNumber + 1000);

    return OpeningStockPricing::query()->create([
        'company_id' => $company->getKey(),
        'financial_period_id' => $period->getKey(),
        'branch_id' => $branch->getKey(),
        'opening_stock_id' => $openingStock->getKey(),
        'currency_id' => $currency->getKey(),
        'doc_number' => $docNumber,
        'doc_num' => 'OSP-'.str_pad((string) $docNumber, 5, '0', STR_PAD_LEFT),
        'document_date' => '2026-02-01',
        'exchange_rate' => 1,
        'total_amount' => 0,
        'is_closed' => true,
        'status' => OpeningStockPricing::StatusClosed,
        ...$overrides,
    ]);
}

test('Open Document menu appears under Tools with permission', function (): void {
    $this->seed(PermissionSeeder::class);

    $actor = openDocumentsActor(['tools.open_documents.view']);
    $menu = app(MenuService::class)->getMenu($actor);
    $tools = collect($menu)->firstWhere('label', 'tools');
    $filesAndDocuments = collect($tools['children'] ?? [])->firstWhere('label', 'files_documents');
    $openDocuments = collect($filesAndDocuments['children'] ?? [])->firstWhere('label', 'open_documents');

    expect($openDocuments)->not->toBeNull()
        ->and($openDocuments['route'])->toBe('admin.tools.open-documents.index')
        ->and($openDocuments['permission'])->toContain('tools.open_documents.view', 'sales_orders.reopen')
        ->and(Permission::query()->where('name', 'tools.open_documents.view')->exists())->toBeTrue()
        ->and(Permission::query()->where('name', 'tools.open_documents.execute')->exists())->toBeTrue()
        ->and(Permission::query()->where('name', 'purchase_orders.reopen')->exists())->toBeTrue()
        ->and(Permission::query()->where('name', 'purchases.purchase_requisitions.reopen')->exists())->toBeTrue()
        ->and(Permission::query()->where('name', 'production.material_requests.reopen')->exists())->toBeTrue();
});

test('Open Document screen and execute action require their permissions', function (): void {
    $viewer = openDocumentsActor(['tools.open_documents.view']);
    $executorOnly = openDocumentsActor(['tools.open_documents.execute']);

    $this->actingAs($executorOnly)
        ->get(route('admin.tools.open-documents.index'))
        ->assertForbidden();

    $this->actingAs($viewer)
        ->postJson(route('admin.tools.open-documents.store'), [
            'document_type' => 'opening_balances',
            'from_number' => 1,
            'to_number' => 1,
        ])
        ->assertForbidden();
});

test('sales reopen permission grants only its own type on the central screen', function (): void {
    openDocumentsContext($this);
    $actor = openDocumentsActor(['sales_orders.reopen']);
    $menu = app(MenuService::class)->getMenu($actor);
    $tools = collect($menu)->firstWhere('label', 'tools');
    $filesAndDocuments = collect($tools['children'] ?? [])->firstWhere('label', 'files_documents');
    $openDocuments = collect($filesAndDocuments['children'] ?? [])->firstWhere('label', 'open_documents');

    expect($openDocuments)->not->toBeNull();

    $this->actingAs($actor)
        ->get(route('admin.tools.open-documents.index'))
        ->assertOk()
        ->assertSee('value="sales_orders"', false)
        ->assertDontSee('value="opening_balances"', false)
        ->assertDontSee('value="customer_invoices"', false);

    $this->postJson(route('admin.tools.open-documents.store'), [
        'document_type' => 'opening_balances',
        'from_number' => 1,
        'to_number' => 1,
    ])->assertForbidden();

    $this->postJson(route('admin.tools.open-documents.store'), [
        'document_type' => 'sales_orders',
        'from_number' => 1,
        'to_number' => 1,
        'reason' => 'Correct this approved sales order.',
    ])->assertOk()->assertJsonPath('summary.total_found', 0);
});

test('Open Document form exposes opening documents and hides unauthorized workflows', function (): void {
    $actor = openDocumentsActor(['tools.open_documents.view']);

    $this->actingAs($actor)
        ->get(route('admin.tools.open-documents.index'))
        ->assertOk()
        ->assertSee('value="opening_balances"', false)
        ->assertSee('value="opening_stocks"', false)
        ->assertSee('value="opening_stock_pricings"', false)
        ->assertDontSee('value="production_material_requests"', false)
        ->assertDontSee('value="customers"', false);
});

test('Open Document form preselects an authorized document linked from its detail page', function (): void {
    $actor = openDocumentsActor(['tools.open_documents.view', 'sales_orders.reopen']);

    $this->actingAs($actor)
        ->get(route('admin.tools.open-documents.index', [
            'document_type' => 'sales_orders',
            'from_number' => 57,
            'to_number' => 57,
        ]))
        ->assertOk()
        ->assertSee('value="sales_orders" selected', false)
        ->assertSee('name="from_number"', false)
        ->assertSee('name="to_number"', false)
        ->assertSee('value="57"', false);
});

test('Open Document form does not show stale validation errors before submission', function (): void {
    $actor = openDocumentsActor(['tools.open_documents.view']);

    $errors = new ViewErrorBag;
    $errors->put('default', new MessageBag([
        'document_type' => 'Error from a previous screen',
        'from_number' => 'Error from a previous screen',
        'to_number' => 'Error from a previous screen',
    ]));

    $this->actingAs($actor)
        ->withSession(['errors' => $errors])
        ->get(route('admin.tools.open-documents.index'))
        ->assertOk()
        ->assertDontSee('Error from a previous screen')
        ->assertDontSee('is-invalid', false);
});

test('Open Document rejects invalid document type and reversed ranges', function (): void {
    $context = openDocumentsContext($this);
    $actor = openDocumentsActor(['tools.open_documents.view', 'tools.open_documents.execute']);

    $this->actingAs($actor)
        ->postJson(route('admin.tools.open-documents.store'), [
            'document_type' => 'customers',
            'from_number' => 1,
            'to_number' => 1,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['document_type']);

    $this->withSession([
        OperatingContextService::CompanyIdKey => $context['company']->getKey(),
        OperatingContextService::CompanyDocNumKey => $context['company']->doc_num,
        OperatingContextService::BranchIdKey => $context['branch']->getKey(),
        OperatingContextService::BranchDocNumKey => $context['branch']->doc_num,
        OperatingContextService::FinancialPeriodIdKey => $context['period']->getKey(),
        OperatingContextService::FinancialPeriodDocNumKey => $context['period']->doc_num,
    ])->actingAs($actor)
        ->postJson(route('admin.tools.open-documents.store'), [
            'document_type' => 'opening_balances',
            'from_number' => 5,
            'to_number' => 3,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['from_number']);
});

test('Open Document limits a posted-invoice batch before any financial reversal', function (): void {
    $actor = openDocumentsActor(['tools.open_documents.view', 'tools.open_documents.execute', 'customer_invoices.reopen']);

    $this->actingAs($actor)
        ->postJson(route('admin.tools.open-documents.store'), [
            'document_type' => 'customer_invoices',
            'from_number' => 1,
            'to_number' => 11,
            'reason' => 'Correct invoice descriptions.',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('to_number');
});

test('Open Document reopens only closed unapproved Opening Balances in current company and period', function (): void {
    $context = openDocumentsContext($this);
    $actor = openDocumentsActor(['tools.open_documents.view', 'tools.open_documents.execute']);
    $otherCompanyContext = openDocumentsContext($this);
    $otherPeriod = openDocumentsPeriod($context['company']);

    $eligible = openDocumentsOpeningBalance($context['company'], $context['period'], $context['currency'], 10);
    $approved = openDocumentsOpeningBalance($context['company'], $context['period'], $context['currency'], 11, [
        'approved' => true,
        'approved_at' => now(),
        'approved_by' => $actor->getKey(),
        'status' => OpeningBalance::StatusApproved,
    ]);
    $alreadyOpen = openDocumentsOpeningBalance($context['company'], $context['period'], $context['currency'], 12, [
        'is_closed' => false,
    ]);
    $deleted = openDocumentsOpeningBalance($context['company'], $context['period'], $context['currency'], 13);
    $deleted->delete();
    $otherCompany = openDocumentsOpeningBalance($otherCompanyContext['company'], $otherCompanyContext['period'], $otherCompanyContext['currency'], 14);
    $otherPeriodRecord = openDocumentsOpeningBalance($context['company'], $otherPeriod, $context['currency'], 15);

    $this->withSession([
        OperatingContextService::CompanyIdKey => $context['company']->getKey(),
        OperatingContextService::CompanyDocNumKey => $context['company']->doc_num,
        OperatingContextService::BranchIdKey => $context['branch']->getKey(),
        OperatingContextService::BranchDocNumKey => $context['branch']->doc_num,
        OperatingContextService::FinancialPeriodIdKey => $context['period']->getKey(),
        OperatingContextService::FinancialPeriodDocNumKey => $context['period']->doc_num,
    ])->actingAs($actor)
        ->postJson(route('admin.tools.open-documents.store'), [
            'document_type' => 'opening_balances',
            'from_number' => 10,
            'to_number' => 15,
        ])
        ->assertOk()
        ->assertJsonPath('summary.total_found', 4)
        ->assertJsonPath('summary.opened', 1)
        ->assertJsonPath('summary.skipped_approved', 1)
        ->assertJsonPath('summary.skipped_already_open', 1)
        ->assertJsonPath('summary.skipped_deleted', 1)
        ->assertJsonPath('summary.not_found', 2)
        ->assertJsonMissingPath('id')
        ->assertJsonMissingPath('summary.id');

    expect($eligible->refresh()->is_closed)->toBeFalse()
        ->and($eligible->status)->toBe(OpeningBalance::StatusDraft)
        ->and($approved->refresh()->is_closed)->toBeTrue()
        ->and($approved->approved)->toBeTrue()
        ->and($alreadyOpen->refresh()->is_closed)->toBeFalse()
        ->and(OpeningBalance::withTrashed()->findOrFail($deleted->getKey())->is_closed)->toBeTrue()
        ->and($otherCompany->refresh()->is_closed)->toBeTrue()
        ->and($otherPeriodRecord->refresh()->is_closed)->toBeTrue();

    $activity = Activity::query()->where('event', 'tools.open_documents.open')->first();
    $properties = $activity?->properties?->toArray() ?? [];

    expect($properties['record']['doc_num'] ?? null)->toBe($eligible->doc_num)
        ->and($properties['meta']['document_number'] ?? null)->toBe((int) $eligible->doc_number)
        ->and($properties)->not->toHaveKey('id')
        ->and($properties['record'] ?? [])->not->toHaveKey('id');
});

test('Open Document keeps a generic opening document closed when its financial period is closed', function (): void {
    $context = openDocumentsContext($this);
    $actor = openDocumentsActor(['tools.open_documents.view', 'tools.open_documents.execute']);
    $opening = openDocumentsOpeningBalance($context['company'], $context['period'], $context['currency'], 88);
    $context['period']->forceFill(['is_closed' => true])->save();

    $this->actingAs($actor)->postJson(route('admin.tools.open-documents.store'), [
        'document_type' => 'opening_balances',
        'from_number' => 88,
        'to_number' => 88,
    ])->assertUnprocessable();
    expect($opening->fresh()->is_closed)->toBeTrue();
});

test('Open Document reopens Opening Stock across all branches in current company and period', function (): void {
    $context = openDocumentsContext($this);
    $actor = openDocumentsActor(['tools.open_documents.view', 'tools.open_documents.execute']);
    $otherBranch = openDocumentsBranch($context['company'], Branch::TypeWarehouse);
    $otherPeriod = openDocumentsPeriod($context['company']);

    $branchDocument = openDocumentsOpeningStock($context['company'], $context['period'], $otherBranch, 20);
    $approved = openDocumentsOpeningStock($context['company'], $context['period'], $context['branch'], 21, [
        'approved' => true,
        'approved_at' => now(),
        'approved_by' => $actor->getKey(),
        'status' => OpeningStock::StatusApproved,
    ]);
    $otherPeriodRecord = openDocumentsOpeningStock($context['company'], $otherPeriod, $otherBranch, 22);

    $this->actingAs($actor)
        ->postJson(route('admin.tools.open-documents.store'), [
            'document_type' => 'opening_stocks',
            'from_number' => 20,
            'to_number' => 22,
        ])
        ->assertOk()
        ->assertJsonPath('summary.total_found', 2)
        ->assertJsonPath('summary.opened', 1)
        ->assertJsonPath('summary.skipped_approved', 1);

    expect($branchDocument->refresh()->is_closed)->toBeFalse()
        ->and($branchDocument->branch_id)->toBe($otherBranch->getKey())
        ->and($approved->refresh()->is_closed)->toBeTrue()
        ->and($approved->approved)->toBeTrue()
        ->and($otherPeriodRecord->refresh()->is_closed)->toBeTrue();
});

test('Open Document reopens Opening Stock Pricing across all branches in current company and period', function (): void {
    $context = openDocumentsContext($this);
    $actor = openDocumentsActor(['tools.open_documents.view', 'tools.open_documents.execute']);
    $otherBranch = openDocumentsBranch($context['company'], Branch::TypeWarehouse);
    $otherCompanyContext = openDocumentsContext($this);

    $branchPricing = openDocumentsOpeningStockPricing($context['company'], $context['period'], $otherBranch, $context['currency'], 30);
    $otherCompanyPricing = openDocumentsOpeningStockPricing($otherCompanyContext['company'], $otherCompanyContext['period'], $otherBranch, $otherCompanyContext['currency'], 31);

    $this->withSession([
        OperatingContextService::CompanyIdKey => $context['company']->getKey(),
        OperatingContextService::CompanyDocNumKey => $context['company']->doc_num,
        OperatingContextService::BranchIdKey => $context['branch']->getKey(),
        OperatingContextService::BranchDocNumKey => $context['branch']->doc_num,
        OperatingContextService::FinancialPeriodIdKey => $context['period']->getKey(),
        OperatingContextService::FinancialPeriodDocNumKey => $context['period']->doc_num,
    ])->actingAs($actor)
        ->postJson(route('admin.tools.open-documents.store'), [
            'document_type' => 'opening_stock_pricings',
            'from_number' => 30,
            'to_number' => 31,
        ])
        ->assertOk()
        ->assertJsonPath('summary.total_found', 1)
        ->assertJsonPath('summary.opened', 1);

    expect($branchPricing->refresh()->is_closed)->toBeFalse()
        ->and($branchPricing->status)->toBe(OpeningStockPricing::StatusDraft)
        ->and($branchPricing->branch_id)->toBe($otherBranch->getKey())
        ->and($otherCompanyPricing->refresh()->is_closed)->toBeTrue();
});

test('Open Document returns no-reopenable message when no documents are eligible', function (): void {
    openDocumentsContext($this);
    $actor = openDocumentsActor(['tools.open_documents.view', 'tools.open_documents.execute']);

    $this->actingAs($actor)
        ->postJson(route('admin.tools.open-documents.store'), [
            'document_type' => 'opening_balances',
            'from_number' => 99,
            'to_number' => 99,
        ])
        ->assertOk()
        ->assertJsonPath('success', false)
        ->assertJsonPath('type', 'no_changes')
        ->assertJsonPath('message', __('open_documents.messages.none_reopenable'))
        ->assertJsonPath('summary.opened', 0);
});

test('Open Document routes approved sales order reopening through its workflow and enforces its permission', function (): void {
    $fixture = salesCycleFixture();
    $actor = $fixture['user'];
    foreach (['tools.open_documents.view', 'tools.open_documents.execute', 'sales_orders.reopen'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $actor->givePermissionTo(['tools.open_documents.view', 'tools.open_documents.execute']);
    $this->actingAs($actor)->withSession(salesCycleSession($fixture));

    $order = app(SalesOrderService::class)->approve(app(SalesOrderService::class)->create(salesCycleOrderPayload($fixture)));
    $this->get(route('admin.tools.open-documents.index'))->assertOk()->assertDontSee('value="sales_orders"', false);
    $this->postJson(route('admin.tools.open-documents.store'), [
        'document_type' => 'sales_orders', 'from_number' => $order->doc_number, 'to_number' => $order->doc_number, 'reason' => 'Correct the agreed quantity.',
    ])->assertForbidden();
    expect($order->fresh()->status)->toBe(SalesOrder::StatusApproved);

    $actor->givePermissionTo('sales_orders.reopen');
    $this->get(route('admin.tools.open-documents.index'))->assertOk()->assertSee('value="sales_orders"', false);
    $this->postJson(route('admin.tools.open-documents.store'), [
        'document_type' => 'sales_orders', 'from_number' => $order->doc_number, 'to_number' => $order->doc_number,
    ])->assertInvalid('reason');
    $this->postJson(route('admin.tools.open-documents.store'), [
        'document_type' => 'sales_orders', 'from_number' => $order->doc_number, 'to_number' => $order->doc_number, 'reason' => 'Correct the agreed quantity.',
    ])->assertOk()->assertJsonPath('summary.opened', 1);

    $reopened = $order->fresh();
    expect($reopened->status)->toBe(SalesOrder::StatusReopened)
        ->and($reopened->reopen_reason)->toBe('Correct the agreed quantity.')
        ->and($reopened->canCancelSafely())->toBeFalse();
    Permission::findOrCreate('sales_orders.cancel', 'web');
    $actor->givePermissionTo('sales_orders.cancel');
    $this->postJson(route('admin.sales.sales-orders.cancel', $order), ['reason' => 'Delete the previously approved order.'])->assertUnprocessable();
    expect($order->fresh()->status)->toBe(SalesOrder::StatusReopened);
});

test('Open Document cannot reopen a sales order in a closed financial period', function (): void {
    $fixture = salesCycleFixture();
    $actor = $fixture['user'];
    foreach (['tools.open_documents.view', 'tools.open_documents.execute', 'sales_orders.reopen'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $actor->givePermissionTo(['tools.open_documents.view', 'tools.open_documents.execute', 'sales_orders.reopen']);
    $this->actingAs($actor)->withSession(salesCycleSession($fixture));
    $order = app(SalesOrderService::class)->approve(app(SalesOrderService::class)->create(salesCycleOrderPayload($fixture)));
    $fixture['period']->forceFill(['is_closed' => true])->save();

    $this->postJson(route('admin.tools.open-documents.store'), [
        'document_type' => 'sales_orders', 'from_number' => $order->doc_number, 'to_number' => $order->doc_number, 'reason' => 'Correct the agreed quantity.',
    ])->assertUnprocessable();
    expect($order->fresh()->status)->toBe(SalesOrder::StatusApproved);
});

test('Open Document delegates sales request and posted invoice reopening to their audited workflows', function (): void {
    $fixture = salesCycleFixture();
    $actor = $fixture['user'];
    foreach (['tools.open_documents.view', 'tools.open_documents.execute', 'sales_requests.reopen', 'customer_invoices.reopen'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $actor->givePermissionTo(['tools.open_documents.view', 'tools.open_documents.execute', 'sales_requests.reopen', 'customer_invoices.reopen']);
    $this->actingAs($actor)->withSession(salesCycleSession($fixture));

    $requestService = app(SalesRequestService::class);
    $salesRequest = $requestService->save([
        'company_id' => $fixture['company']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'currency_id' => $fixture['currency']->getKey(),
        'request_date' => now()->toDateString(),
        'lines' => [[
            'product_id' => $fixture['finished']->getKey(),
            'unit_id' => $fixture['unit']->getKey(),
            'quantity' => '2',
        ]],
    ]);
    $requestService->transition($salesRequest, 'submitted');
    $requestService->transition($salesRequest->fresh(), SalesRequest::StatusApproved);

    $this->postJson(route('admin.tools.open-documents.store'), [
        'document_type' => 'sales_requests', 'from_number' => $salesRequest->doc_number,
        'to_number' => $salesRequest->doc_number, 'reason' => 'Correct the customer request.',
    ])->assertOk()->assertJsonPath('summary.opened', 1);
    expect($salesRequest->fresh()->status)->toBe(SalesRequest::StatusReopened);

    $invoice = salesPostedServiceInvoice($fixture, '100.0000');
    $originalJournalId = (int) $invoice->journal_entry_id;
    $originalLines = DB::table('journal_entry_lines')->where('journal_entry_id', $originalJournalId)->get();
    $this->postJson(route('admin.tools.open-documents.store'), [
        'document_type' => 'customer_invoices', 'from_number' => $invoice->doc_number,
        'to_number' => $invoice->doc_number, 'reason' => 'Correct the service description.',
    ])->assertOk()->assertJsonPath('summary.opened', 1);
    $reopenedInvoice = $invoice->fresh();
    expect($reopenedInvoice->status)->toBe(CustomerInvoice::StatusReopened)
        ->and($reopenedInvoice->is_closed)->toBeFalse()
        ->and($reopenedInvoice->reversal_journal_entry_id)->not->toBeNull();
    $reversalJournal = DB::table('journal_entries')->find($reopenedInvoice->reversal_journal_entry_id);
    $originalJournal = DB::table('journal_entries')->find($originalJournalId);
    $reversalLines = DB::table('journal_entry_lines')->where('journal_entry_id', $reversalJournal->id)->get();

    expect((int) $originalJournal->reversed_entry_id)->toBe((int) $reversalJournal->id)
        ->and((int) $reversalJournal->company_id)->toBe((int) $originalJournal->company_id)
        ->and((int) $reversalJournal->branch_id)->toBe((int) $originalJournal->branch_id)
        ->and((int) $reversalJournal->financial_period_id)->toBe((int) $originalJournal->financial_period_id)
        ->and($reversalLines)->toHaveCount($originalLines->count());
    foreach ($originalLines as $originalLine) {
        $reversalLine = $reversalLines->firstWhere('account_id', $originalLine->account_id);
        expect($reversalLine)->not->toBeNull()
            ->and((string) $reversalLine->debit_amount)->toBe((string) $originalLine->credit_amount)
            ->and((string) $reversalLine->credit_amount)->toBe((string) $originalLine->debit_amount);
    }
});

test('Open Document reopens an unsent purchase order and blocks orders with downstream commitments', function (): void {
    $fixture = procurementFixture();
    $actor = $fixture['user'];
    foreach (['tools.open_documents.view', 'tools.open_documents.execute', 'purchase_orders.reopen'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $actor->givePermissionTo(['tools.open_documents.view', 'tools.open_documents.execute', 'purchase_orders.reopen']);
    $this->actingAs($actor);

    $orders = app(PurchaseOrderService::class);
    $order = $orders->approve($orders->create([
        'supplier_doc_num' => $fixture['firstSupplier']->doc_num,
        'branch_store_uuid' => $fixture['store']->public_uuid,
        'currency_doc_num' => $fixture['currency']->doc_num,
        'document_date' => now()->toDateString(),
        'exchange_rate' => 1,
        'direct_procurement_override' => true,
        'direct_procurement_reason' => 'Test direct purchasing.',
        'lines' => [[
            'product_doc_num' => $fixture['raw']->doc_num,
            'unit_doc_num' => $fixture['unit']->doc_num,
            'ordered_quantity' => 5,
            'unit_price' => 10,
            'tax_rate' => 0,
        ]],
    ])['record']);

    $this->get(route('admin.tools.open-documents.index'))->assertOk()->assertSee('value="purchase_orders"', false);
    $this->postJson(route('admin.tools.open-documents.store'), [
        'document_type' => 'purchase_orders',
        'from_number' => $order->doc_number,
        'to_number' => $order->doc_number,
    ])->assertInvalid('reason');
    $this->postJson(route('admin.tools.open-documents.store'), [
        'document_type' => 'purchase_orders',
        'from_number' => $order->doc_number,
        'to_number' => $order->doc_number,
        'reason' => 'Correct the requested quantity.',
    ])->assertOk()->assertJsonPath('summary.opened', 1);

    expect($order->fresh()->status)->toBe(PurchaseOrder::StatusDraft)
        ->and($order->fresh()->approved_at)->not->toBeNull()
        ->and($order->fresh()->isDeletable())->toBeFalse()
        ->and(Activity::query()->where('event', 'purchase_order.reopened')->exists())->toBeTrue();
    expect(fn () => $orders->delete($order->fresh()))->toThrow(DomainException::class);

    $order = $orders->approve($order->fresh());
    $order->forceFill(['sent_at' => now(), 'sent_by' => $actor->getKey()])->save();
    $this->postJson(route('admin.tools.open-documents.store'), [
        'document_type' => 'purchase_orders',
        'from_number' => $order->doc_number,
        'to_number' => $order->doc_number,
        'reason' => 'Unsafe after supplier dispatch.',
    ])->assertOk()->assertJsonPath('summary.opened', 0)->assertJsonPath('summary.skipped_blocked', 1);
    expect($order->fresh()->status)->toBe(PurchaseOrder::StatusApproved);

    $order->forceFill(['sent_at' => null, 'sent_by' => null])->save();
    $inspection = GoodsReceiptInspection::query()->create([
        'doc_number' => 99001,
        'doc_num' => 'GRI-REOPEN-BLOCK',
        'company_id' => $fixture['company']->getKey(),
        'financial_period_id' => $fixture['period']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'purchase_order_id' => $order->getKey(),
        'inspection_at' => now(),
    ]);
    expect($order->fresh()->canReopenSafely())->toBeFalse();
    $inspection->delete();
    expect($order->fresh()->canReopenSafely())->toBeFalse();
    expect(fn () => $orders->cancel($order->fresh(), 'The inspection was deleted.'))->toThrow(DomainException::class);
    $inspection->forceDelete();

    $changeRequest = PurchaseOrderChangeRequest::query()->create([
        'doc_number' => 99001,
        'doc_num' => 'POCR-REOPEN-BLOCK',
        'company_id' => $fixture['company']->getKey(),
        'financial_period_id' => $fixture['period']->getKey(),
        'purchase_order_id' => $order->getKey(),
        'request_date' => now()->toDateString(),
        'original_values' => [],
        'requested_values' => ['notes' => 'stale'],
        'reason' => 'Pending amendment.',
    ]);
    expect($order->fresh()->canReopenSafely())->toBeFalse();
    $changeRequest->forceFill(['status' => 'rejected'])->save();
    expect($order->fresh()->canReopenSafely())->toBeTrue();

    $orders->close($order->fresh());
    $this->postJson(route('admin.tools.open-documents.store'), [
        'document_type' => 'purchase_orders',
        'from_number' => $order->doc_number,
        'to_number' => $order->doc_number,
        'reason' => 'Revise a closed order without discarding its history.',
    ])->assertOk()->assertJsonPath('summary.opened', 1);
    expect($order->fresh()->closed_at)->not->toBeNull()
        ->and($order->fresh()->isDeletable())->toBeFalse();
    expect(fn () => $orders->cancel($order->fresh(), 'Do not cancel a previously closed order.'))->toThrow(DomainException::class)
        ->and(fn () => $orders->delete($order->fresh()))->toThrow(DomainException::class);
});

test('Open Document reopens a purchase requisition and preserves downstream sourcing locks', function (): void {
    $fixture = procurementFixture();
    $actor = $fixture['user'];
    foreach (['tools.open_documents.view', 'tools.open_documents.execute', 'purchases.purchase_requisitions.reopen'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $actor->givePermissionTo(['tools.open_documents.view', 'tools.open_documents.execute', 'purchases.purchase_requisitions.reopen']);
    $this->actingAs($actor);

    $sourcing = app(ProcurementSourcingService::class);
    $requisition = procurementManualRequisition($fixture);
    $sourcing->submitRequisition($requisition);
    $sourcing->approveRequisition($requisition->fresh());

    $this->get(route('admin.tools.open-documents.index'))->assertOk()->assertSee('value="purchase_requisitions"', false);
    $this->postJson(route('admin.tools.open-documents.store'), [
        'document_type' => 'purchase_requisitions',
        'from_number' => $requisition->doc_number,
        'to_number' => $requisition->doc_number,
        'reason' => 'Correct the source request.',
    ])->assertOk()->assertJsonPath('summary.opened', 1);
    expect($requisition->fresh()->status)->toBe(PurchaseRequisition::StatusDraft)
        ->and($requisition->fresh()->approved_at)->not->toBeNull()
        ->and((string) $requisition->fresh()->lines()->firstOrFail()->approved_quantity)->toBe('0.00000000');
    expect(fn () => $sourcing->deleteRequisition($requisition->fresh()))->toThrow(DomainException::class);

    $sourcing->submitRequisition($requisition->fresh());
    $sourcing->approveRequisition($requisition->fresh());
    PurchaseOrder::query()->create([
        'doc_number' => 99005,
        'doc_num' => 'PO-REOPEN-REQ-BLOCK',
        'company_id' => $fixture['company']->getKey(),
        'financial_period_id' => $fixture['period']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'branch_store_id' => $fixture['store']->getKey(),
        'supplier_id' => $fixture['firstSupplier']->getKey(),
        'document_date' => now()->toDateString(),
        'purchase_requisition_id' => $requisition->getKey(),
    ]);
    $this->postJson(route('admin.tools.open-documents.store'), [
        'document_type' => 'purchase_requisitions',
        'from_number' => $requisition->doc_number,
        'to_number' => $requisition->doc_number,
        'reason' => 'Unsafe after an order.',
    ])->assertOk()->assertJsonPath('summary.opened', 0)->assertJsonPath('summary.skipped_blocked', 1);
    expect($requisition->fresh()->status)->toBe(PurchaseRequisition::StatusApproved);
    expect(fn () => $sourcing->finishRequisition($requisition->fresh(), PurchaseRequisition::StatusCancelled, 'Attempt to cancel a converted request.'))->toThrow(DomainException::class)
        ->and($requisition->fresh()->status)->toBe(PurchaseRequisition::StatusApproved);

    $closedRequisition = procurementManualRequisition($fixture);
    $sourcing->submitRequisition($closedRequisition);
    $sourcing->approveRequisition($closedRequisition->fresh());
    $sourcing->finishRequisition($closedRequisition->fresh(), PurchaseRequisition::StatusClosed);
    $this->postJson(route('admin.tools.open-documents.store'), [
        'document_type' => 'purchase_requisitions',
        'from_number' => $closedRequisition->doc_number,
        'to_number' => $closedRequisition->doc_number,
        'reason' => 'Revise the closed request.',
    ])->assertOk()->assertJsonPath('summary.opened', 1);
    expect($closedRequisition->fresh()->status)->toBe(PurchaseRequisition::StatusDraft)
        ->and($closedRequisition->fresh()->closed_at)->not->toBeNull();
    expect(fn () => $sourcing->finishRequisition($closedRequisition->fresh(), PurchaseRequisition::StatusCancelled, 'No longer needed.'))->toThrow(DomainException::class)
        ->and(fn () => $sourcing->deleteRequisition($closedRequisition->fresh()))->toThrow(DomainException::class);
});
