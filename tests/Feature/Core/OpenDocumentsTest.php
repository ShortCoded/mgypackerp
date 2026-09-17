<?php

use App\Models\User;
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
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

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
        ->and($openDocuments['permission'])->toBe('tools.open_documents.view')
        ->and(Permission::query()->where('name', 'tools.open_documents.view')->exists())->toBeTrue()
        ->and(Permission::query()->where('name', 'tools.open_documents.execute')->exists())->toBeTrue();
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

test('Open Document form exposes only the three supported document types', function (): void {
    $actor = openDocumentsActor(['tools.open_documents.view']);

    $this->actingAs($actor)
        ->get(route('admin.tools.open-documents.index'))
        ->assertOk()
        ->assertSee('value="opening_balances"', false)
        ->assertSee('value="opening_stocks"', false)
        ->assertSee('value="opening_stock_pricings"', false)
        ->assertDontSee('value="customers"', false);
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
