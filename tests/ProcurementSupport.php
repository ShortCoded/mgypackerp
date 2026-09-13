<?php

use App\Models\User;
use Database\Seeders\DefaultOperatingContextSeeder;
use Illuminate\Support\Facades\Storage;
use Modules\Accounting\Database\Seeders\DefaultChartOfAccountsSeeder;
use Modules\Accounting\Models\Account;
use Modules\Core\Database\Seeders\CurrencySeeder;
use Modules\Core\Models\ArchiveFile;
use Modules\Core\Models\Branch;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Company;
use Modules\Core\Models\Currency;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Models\Product;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\OperatingContextService;
use Modules\Production\Models\ProductionOrder;
use Modules\Production\Models\ProductionOrderLine;
use Modules\Purchases\Models\PurchaseRequisition;
use Modules\Purchases\Models\Supplier;
use Modules\Purchases\Services\ProcurementSourcingService;
use Modules\Sales\Models\Customer;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderLine;

/** @return array<string, mixed> */
function procurementFixture(bool $isolatedCompany = false): array
{
    if ($isolatedCompany) {
        [$company, $branch, $period] = DB::transaction(function (): array {
            $documents = app(DocumentNumberService::class);
            $company = Company::query()->create([...$documents->next('companies', Company::class), 'name' => 'Procurement Release '.bin2hex(random_bytes(4)), 'status' => 'active', 'country' => 'Egypt']);
            $branch = Branch::query()->create([...$documents->next('branches', Branch::class), 'company_id' => $company->id, 'name' => 'Verification Branch', 'type' => 'administrative', 'status' => 'active']);
            $period = FinancialPeriod::query()->create([...$documents->next('financial_periods', FinancialPeriod::class), 'company_id' => $company->id, 'name' => 'Verification year', 'from_date' => now()->startOfYear()->toDateString(), 'to_date' => now()->endOfYear()->toDateString(), 'is_closed' => false]);

            return [$company, $branch, $period];
        });
    } else {
        test()->seed(DefaultOperatingContextSeeder::class);
    }
    test()->seed(CurrencySeeder::class);
    test()->seed(DefaultChartOfAccountsSeeder::class);

    $user = DB::transaction(fn () => User::factory()->create($isolatedCompany ? app(DocumentNumberService::class)->next('users', User::class) : []));
    auth()->login($user);
    request()->setUserResolver(fn (): User => $user);
    if (! request()->hasSession()) {
        request()->setLaravelSession(app('session.store'));
    }

    $company ??= Company::query()->where('status', 'active')->firstOrFail();
    $branch ??= Branch::query()->where('company_id', $company->getKey())->where('status', 'active')->firstOrFail();
    $period ??= FinancialPeriod::query()->where('company_id', $company->getKey())->where('is_closed', false)->firstOrFail();
    $branch->forceFill(['type' => Branch::TypeFactory])->save();
    $currency = Currency::query()->where('company_id', $company->getKey())->orderByDesc('is_main')->firstOrFail();
    $store = BranchStore::query()->create(['branch_id' => $branch->getKey(), 'name' => 'Raw Materials', 'position' => 1]);
    $unit = ItemUnit::query()->create(['company_id' => $company->getKey(), 'doc_number' => 9101, 'doc_num' => 'Unit-PROC', 'name' => 'Kilogram', 'status' => 'active']);
    $raw = Product::query()->create(['company_id' => $company->getKey(), 'doc_number' => 9101, 'doc_num' => 'Product-RESIN', 'name' => 'Polymer Resin', 'item_classification' => Product::ClassificationRawMaterial, 'item_unit_id' => $unit->getKey(), 'status' => 'active']);
    $service = Product::query()->create(['company_id' => $company->getKey(), 'doc_number' => 9102, 'doc_num' => 'Product-SERVICE-PROC', 'name' => 'Machine Calibration', 'item_classification' => Product::ClassificationService, 'item_unit_id' => $unit->getKey(), 'status' => 'active']);
    $finished = Product::query()->create(['company_id' => $company->getKey(), 'doc_number' => 9103, 'doc_num' => 'Product-FINISHED-PROC', 'name' => 'Finished Container', 'item_classification' => Product::ClassificationFinishedProduct, 'item_unit_id' => $unit->getKey(), 'status' => 'active']);
    $firstSupplier = Supplier::query()->create(['doc_number' => 9101, 'doc_num' => 'Supplier-PROC-1', 'company_id' => $company->getKey(), 'name' => 'Resin Supplier One', 'status' => 'active']);
    $secondSupplier = Supplier::query()->create(['doc_number' => 9102, 'doc_num' => 'Supplier-PROC-2', 'company_id' => $company->getKey(), 'name' => 'Resin Supplier Two', 'status' => 'active']);

    $context = [
        OperatingContextService::CompanyIdKey => $company->getKey(),
        OperatingContextService::CompanyDocNumKey => $company->doc_num,
        OperatingContextService::BranchIdKey => $branch->getKey(),
        OperatingContextService::BranchDocNumKey => $branch->doc_num,
        OperatingContextService::FinancialPeriodIdKey => $period->getKey(),
        OperatingContextService::FinancialPeriodDocNumKey => $period->doc_num,
    ];
    session($context);
    test()->withSession($context);

    return compact('user', 'company', 'branch', 'period', 'currency', 'store', 'unit', 'raw', 'service', 'finished', 'firstSupplier', 'secondSupplier');
}

/** @param array<string, mixed> $fixture */
function procurementProductionSource(array $fixture): ProductionOrderLine
{
    $customer = Customer::query()->create(['doc_number' => 9101, 'doc_num' => 'Customer-PROC', 'company_id' => $fixture['company']->getKey(), 'name' => 'Production Customer', 'status' => 'active']);
    $salesOrder = SalesOrder::query()->create([
        'doc_number' => 9101, 'doc_num' => 'SO-PROC', 'company_id' => $fixture['company']->getKey(),
        'financial_period_id' => $fixture['period']->getKey(), 'branch_id' => $fixture['branch']->getKey(),
        'branch_store_id' => $fixture['store']->getKey(), 'customer_id' => $customer->getKey(),
        'currency_id' => $fixture['currency']->getKey(), 'order_date' => now()->toDateString(),
        'expected_delivery_date' => now()->addWeek()->toDateString(), 'status' => SalesOrder::StatusApproved,
    ]);
    $salesLine = SalesOrderLine::query()->create([
        'sales_order_id' => $salesOrder->getKey(), 'line_number' => 1, 'product_id' => $fixture['finished']->getKey(),
        'unit_id' => $fixture['unit']->getKey(), 'description' => 'Finished production demand', 'quantity' => 10,
    ]);
    $productionOrder = ProductionOrder::query()->create([
        'doc_number' => 9101, 'doc_num' => 'PROD-PROC', 'company_id' => $fixture['company']->getKey(),
        'financial_period_id' => $fixture['period']->getKey(), 'branch_id' => $fixture['branch']->getKey(),
        'sales_order_id' => $salesOrder->getKey(), 'customer_id' => $customer->getKey(),
        'production_order_date' => now()->toDateString(), 'expected_delivery_date' => now()->addWeek()->toDateString(),
        'status' => ProductionOrder::StatusReleased,
    ]);

    return ProductionOrderLine::query()->create([
        'production_order_id' => $productionOrder->getKey(), 'sales_order_line_id' => $salesLine->getKey(),
        'line_number' => 1, 'product_id' => $fixture['finished']->getKey(), 'unit_id' => $fixture['unit']->getKey(),
        'description' => 'Finished production demand', 'quantity' => 10,
    ]);
}

/** @param array<string, mixed> $fixture */
function procurementManualRequisition(array $fixture, float $quantity = 10): PurchaseRequisition
{
    return app(ProcurementSourcingService::class)->createRequisition([
        'request_date' => now()->toDateString(), 'required_by_date' => now()->addWeek()->toDateString(),
        'branch_store_uuid' => $fixture['store']->public_uuid, 'priority' => 'high',
        'lines' => [[
            'product_doc_num' => $fixture['raw']->doc_num, 'unit_doc_num' => $fixture['unit']->doc_num,
            'requested_quantity' => $quantity, 'source_type' => 'manual',
        ]],
    ]);
}

/** @param array<string, mixed> $fixture */
function procurementAdministrativeBranch(array $fixture): Branch
{
    return Branch::query()->create([
        ...app(DocumentNumberService::class)->next('branches', Branch::class),
        'company_id' => $fixture['company']->getKey(),
        'name' => 'Procurement Administration',
        'type' => Branch::TypeAdministrative,
        'status' => 'active',
    ]);
}

/** @param array<string, mixed> $fixture */
function procurementUseBranch(array $fixture, Branch $branch): void
{
    $context = [
        OperatingContextService::CompanyIdKey => $fixture['company']->getKey(),
        OperatingContextService::CompanyDocNumKey => $fixture['company']->doc_num,
        OperatingContextService::BranchIdKey => $branch->getKey(),
        OperatingContextService::BranchDocNumKey => $branch->doc_num,
        OperatingContextService::FinancialPeriodIdKey => $fixture['period']->getKey(),
        OperatingContextService::FinancialPeriodDocNumKey => $fixture['period']->doc_num,
    ];
    session($context);
    test()->withSession($context);
}

function procurementPostingAccount(Company $company, string $parentCode, string $accountCode, string $name): Account
{
    $parent = Account::query()
        ->where('company_id', $company->getKey())
        ->where('account_code', $parentCode)
        ->firstOrFail();

    return Account::query()->create([
        ...app(DocumentNumberService::class)->nextForCompany('accounts', Account::class, $company->getKey()),
        'company_id' => $company->getKey(),
        'account_code' => $accountCode,
        'name' => $name,
        'name_en' => $name,
        'parent_id' => $parent->getKey(),
        'level' => ((int) $parent->level) + 1,
        'account_classification_id' => $parent->account_classification_id,
        'account_type' => $parent->account_type,
        'statement_type' => $parent->statement_type,
        'normal_balance' => $parent->normal_balance,
        'is_group' => false,
        'is_postable' => true,
        'is_system' => false,
        'status' => 'active',
    ]);
}

function procurementDocumentAttachment(Company $company): ArchiveFile
{
    $path = 'tests/procurement/supplier-quotation.pdf';
    Storage::disk('public')->put($path, 'procurement-document');

    return ArchiveFile::query()->create([
        'doc_number' => 9401,
        'doc_num' => 'File-PROC-1',
        'attachable_type' => (new Company)->getMorphClass(),
        'attachable_id' => $company->getKey(),
        'module' => 'purchases',
        'record_type' => 'procurement_attachment',
        'hidden_from_picker' => false,
        'original_name' => 'supplier-quotation.pdf',
        'stored_name' => 'supplier-quotation.pdf',
        'disk' => 'public',
        'path' => $path,
        'mime_type' => 'application/pdf',
        'extension' => 'pdf',
        'size_bytes' => 20,
    ]);
}
