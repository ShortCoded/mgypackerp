<?php

use App\Models\User;
use Database\Seeders\DefaultOperatingContextSeeder;
use Modules\Accounting\Database\Seeders\AccountClassificationsSeeder;
use Modules\Accounting\Database\Seeders\DefaultChartOfAccountsSeeder;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\AccountClassification;
use Modules\Accounting\Services\AccountService;
use Modules\Accounting\Services\AccountTreeReport;
use Modules\Auth\Database\Seeders\PermissionSeeder;
use Modules\Auth\Models\Role;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\OperatingContextService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

function accountSetOperatingContext(Company $company, Branch $branch, FinancialPeriod $period): void
{
    if (! request()->hasSession()) {
        request()->setLaravelSession(app('session.store'));
    }

    session([
        OperatingContextService::CompanyIdKey => $company->getKey(),
        OperatingContextService::CompanyDocNumKey => $company->doc_num,
        OperatingContextService::BranchIdKey => $branch->getKey(),
        OperatingContextService::BranchDocNumKey => $branch->doc_num,
        OperatingContextService::FinancialPeriodIdKey => $period->getKey(),
        OperatingContextService::FinancialPeriodDocNumKey => $period->doc_num,
    ]);
}

function accountEnsureOperatingContext(): array
{
    app(DefaultOperatingContextSeeder::class)->run();

    $company = Company::query()->where('status', 'active')->orderBy('id')->firstOrFail();
    $branch = Branch::query()->where('company_id', $company->getKey())->where('status', 'active')->orderBy('id')->firstOrFail();
    $period = FinancialPeriod::query()->where('company_id', $company->getKey())->where('is_closed', false)->orderBy('id')->firstOrFail();

    accountSetOperatingContext($company, $branch, $period);

    return compact('company', 'branch', 'period');
}

function accountCreateOperatingContext(string $name): array
{
    $documentNumbers = app(DocumentNumberService::class);

    $company = Company::query()->create([
        ...$documentNumbers->next('companies', Company::class),
        'name' => $name,
        'legal_name' => $name,
        'status' => 'active',
        'country' => 'Egypt',
    ]);
    $branch = Branch::query()->create([
        ...$documentNumbers->next('branches', Branch::class),
        'company_id' => $company->getKey(),
        'name' => "{$name} Branch",
        'type' => Branch::TypeAdministrative,
        'status' => 'active',
    ]);
    $period = FinancialPeriod::query()->create([
        ...$documentNumbers->nextForCompany('financial_periods', FinancialPeriod::class, $company->getKey()),
        'company_id' => $company->getKey(),
        'name' => (string) now()->year,
        'from_date' => now()->startOfYear()->toDateString(),
        'to_date' => now()->endOfYear()->toDateString(),
        'is_closed' => false,
        'allows_opening_entries' => true,
    ]);

    accountSetOperatingContext($company, $branch, $period);

    return compact('company', 'branch', 'period');
}

function accountActor(array $permissions): User
{
    accountEnsureOperatingContext();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $user = User::factory()->create();
    $user->givePermissionTo($permissions);
    auth()->login($user);
    request()->setUserResolver(fn (): User => $user);

    return $user;
}

function accountDataTableQuery(int $orderColumn = 2, string $direction = 'asc'): array
{
    $columns = [
        'checkbox',
        'doc_num',
        'account_code',
        'name',
        'parent',
        'classification',
        'statement_type',
        'normal_balance',
        'status',
        'created_by',
        'created_at',
        'updated_by',
        'updated_at',
        'actions',
    ];

    return [
        'draw' => 1,
        'start' => 0,
        'length' => 10,
        'search' => ['value' => '', 'regex' => 'false'],
        'order' => [['column' => $orderColumn, 'dir' => $direction]],
        'columns' => collect($columns)->map(fn (string $column): array => [
            'data' => $column,
            'name' => $column,
            'searchable' => ! in_array($column, ['checkbox', 'actions'], true) ? 'true' : 'false',
            'orderable' => ! in_array($column, ['checkbox', 'actions'], true) ? 'true' : 'false',
            'search' => ['value' => '', 'regex' => 'false'],
        ])->all(),
    ];
}

function accountTreeNodeByCode(array $nodes, string $accountCode): ?array
{
    foreach ($nodes as $node) {
        if (($node['account_code'] ?? null) === $accountCode) {
            return $node;
        }

        $match = accountTreeNodeByCode($node['children'] ?? [], $accountCode);

        if ($match !== null) {
            return $match;
        }
    }

    return null;
}

function accountPayload(array $overrides = []): array
{
    return [
        'account_code' => '1901',
        'name' => 'حساب اختبار',
        'parent_doc_num' => null,
        'classification_code' => null,
        'account_type' => 'asset',
        'statement_type' => 'financial_position',
        'normal_balance' => 'debit',
        'is_group' => '0',
        'is_postable' => '1',
        'status' => 'active',
        'notes' => null,
        ...$overrides,
    ];
}

test('accounting permissions are discovered and assigned to admin', function () {
    $this->seed(PermissionSeeder::class);

    $admin = Role::query()->where('name', 'admin')->where('guard_name', 'web')->firstOrFail();

    foreach (['view', 'create', 'edit', 'delete', 'clone', 'view_trashed', 'restore', 'export', 'document_number.control', 'document_number_settings.update', 'account_code.control'] as $action) {
        expect(Permission::query()->where('name', "accounts.{$action}")->exists())->toBeTrue()
            ->and($admin->hasPermissionTo("accounts.{$action}"))->toBeTrue();
    }
});

test('classification and default chart account seeders are idempotent', function () {
    $this->seed(AccountClassificationsSeeder::class);
    $this->seed(DefaultChartOfAccountsSeeder::class);
    $this->seed(AccountClassificationsSeeder::class);
    $this->seed(DefaultChartOfAccountsSeeder::class);
    $context = accountEnsureOperatingContext();

    expect(AccountClassification::query()->count())->toBe(23)
        ->and(Account::query()->where('company_id', $context['company']->getKey())->whereNull('parent_id')->count())->toBe(5)
        ->and(Account::query()->whereNull('company_id')->exists())->toBeFalse()
        ->and(Account::query()->forCompany($context['company']->getKey())->where('account_code', '1')->first()?->is_system)->toBeTrue()
        ->and(Account::query()->forCompany($context['company']->getKey())->where('account_code', '1')->first()?->is_group)->toBeTrue()
        ->and(Account::query()->forCompany($context['company']->getKey())->where('account_code', '1')->first()?->is_postable)->toBeFalse()
        ->and(Account::query()->forCompany($context['company']->getKey())->where('account_code', '1111')->first()?->parent?->account_code)->toBe('111')
        ->and(Account::query()->forCompany($context['company']->getKey())->where('account_code', '1111')->first()?->classification?->code)->toBe('cash')
        ->and(Account::query()->forCompany($context['company']->getKey())->where('account_code', '1112')->first()?->classification?->code)->toBe('bank')
        ->and(Account::query()->forCompany($context['company']->getKey())->where('account_code', '1112')->first()?->is_group)->toBeTrue()
        ->and(Account::query()->forCompany($context['company']->getKey())->where('account_code', '1112')->first()?->is_postable)->toBeFalse()
        ->and(Account::query()->forCompany($context['company']->getKey())->where('account_code', '1121')->first()?->classification?->code)->toBe('accounts_receivable')
        ->and(Account::query()->forCompany($context['company']->getKey())->where('account_code', '1133')->first()?->classification?->code)->toBe('inventory')
        ->and(Account::query()->forCompany($context['company']->getKey())->where('account_code', '2111')->first()?->classification?->code)->toBe('accounts_payable')
        ->and(Account::query()->forCompany($context['company']->getKey())->where('account_code', '411')->first()?->classification?->code)->toBe('sales_revenue')
        ->and(Account::query()->forCompany($context['company']->getKey())->where('account_code', '521')->first()?->classification?->code)->toBe('salary_expense')
        ->and(Account::query()->forCompany($context['company']->getKey())->where('account_code', '11')->first()?->is_group)->toBeTrue()
        ->and(Account::query()->forCompany($context['company']->getKey())->where('account_code', '11')->first()?->is_postable)->toBeFalse()
        ->and(Account::query()->forCompany($context['company']->getKey())->where('account_code', '1111')->first()?->is_group)->toBeTrue()
        ->and(Account::query()->forCompany($context['company']->getKey())->where('account_code', '1111')->first()?->is_postable)->toBeFalse()
        ->and(Account::query()->forCompany($context['company']->getKey())->where('account_code', '551')->first()?->is_system)->toBeTrue();
});

test('default chart account seeder creates an independent tree for each company', function () {
    $primary = accountEnsureOperatingContext();
    $secondary = accountCreateOperatingContext('Second Accounting Company');

    $this->seed(DefaultChartOfAccountsSeeder::class);
    $this->seed(DefaultChartOfAccountsSeeder::class);

    $primaryAccounts = Account::query()->forCompany($primary['company']->getKey())->pluck('company_id', 'account_code');
    $secondaryAccounts = Account::query()->forCompany($secondary['company']->getKey())->pluck('company_id', 'account_code');
    $primaryRoot = Account::query()->forCompany($primary['company']->getKey())->where('account_code', '1')->firstOrFail();
    $secondaryRoot = Account::query()->forCompany($secondary['company']->getKey())->where('account_code', '1')->firstOrFail();

    expect($primaryAccounts)->toHaveKey('1111')
        ->and($secondaryAccounts)->toHaveKey('1111')
        ->and($primaryAccounts->count())->toBe($secondaryAccounts->count())
        ->and($primaryAccounts->unique()->values()->all())->toBe([$primary['company']->getKey()])
        ->and($secondaryAccounts->unique()->values()->all())->toBe([$secondary['company']->getKey()])
        ->and($primaryRoot->doc_num)->toBe($secondaryRoot->doc_num);
});

test('accounts index and data endpoint render without exposing internal ids', function () {
    $this->seed(DefaultChartOfAccountsSeeder::class);
    $actor = accountActor(['accounts.view', 'accounts.create', 'accounts.edit', 'accounts.delete', 'accounts.clone', 'accounts.view_trashed', 'accounts.export']);

    $this->actingAs($actor)
        ->get(route('admin.accounting.accounts.index'))
        ->assertOk()
        ->assertSee(__('accounts.title'))
        ->assertSee(__('accounts.actions.expand_all'))
        ->assertSee(__('accounts.actions.collapse_all'))
        ->assertSee('class="gap-2 d-none align-items-center accounts-tree-controls"', false)
        ->assertSee('data-accounts-tree-expand-all', false)
        ->assertSee('data-accounts-tree-collapse-all', false)
        ->assertSee(route('admin.accounting.accounts.data'), false)
        ->assertSee(route('admin.accounting.accounts.tree'), false)
        ->assertDontSee('data-id=', false);

    $this->actingAs($actor)
        ->getJson(route('admin.accounting.accounts.data'))
        ->assertOk()
        ->assertJsonMissingPath('data.0.id')
        ->assertJsonFragment(['name' => '<span class="dt-ellipsis-content" title="الأصول">الأصول</span>'])
        ->assertJsonStructure(['data' => [['checkbox', 'doc_num', 'account_code', 'name', 'actions']]]);

    $data = $this->actingAs($actor)
        ->getJson(route('admin.accounting.accounts.data'))
        ->json('data');

    expect($data[0]['checkbox'])->not->toContain('js-record-select')
        ->and($data[0]['checkbox'])->not->toContain('data-doc-num=')
        ->and($data[0]['actions'])->toContain('btn-reveal')
        ->and($data[0]['actions'])->toContain('js-edit-record')
        ->and($data[0]['actions'])->not->toContain('data-delete-url=')
        ->and($data[0]['actions'])->not->toContain('data-id=');
});

test('accounts tree javascript exposes accessible controls and keyboard navigation', function (): void {
    $script = file_get_contents(public_path('assets/js/modules/Accounting/accounts.js'));

    expect($script)
        ->toContain('data-accounts-tree-expand-all')
        ->toContain('data-accounts-tree-collapse-all')
        ->toContain('role="tree"')
        ->toContain('role="treeitem"')
        ->toContain('aria-expanded')
        ->toContain('aria-level')
        ->toContain('tabindex="-1"')
        ->toContain('keydown.accountsTree')
        ->toContain('ArrowDown')
        ->toContain('ArrowUp')
        ->toContain('ArrowRight')
        ->toContain('ArrowLeft')
        ->toContain('Home')
        ->toContain('End')
        ->toContain('Enter')
        ->toContain('visibleTreeNodes')
        ->toContain('.collapse-hidden, .treeview-list:not(.show)')
        ->toContain("document.documentElement.getAttribute('dir')");
});

test('account routes data and parent validation are scoped to the operating company', function () {
    $this->seed(AccountClassificationsSeeder::class);
    $primary = accountEnsureOperatingContext();
    $secondary = accountCreateOperatingContext('Scoped Accounting Company');
    $actor = accountActor(['accounts.view', 'accounts.create', 'accounts.edit', 'accounts.account_code.control']);

    $this->seed(DefaultChartOfAccountsSeeder::class);

    accountSetOperatingContext($secondary['company'], $secondary['branch'], $secondary['period']);
    $secondaryOnly = app(AccountService::class)->create(accountPayload([
        'account_code' => '1901',
        'name' => 'Secondary Company Only',
    ]));

    accountSetOperatingContext($primary['company'], $primary['branch'], $primary['period']);

    $this->actingAs($actor)
        ->get(route('admin.accounting.accounts.show', $secondaryOnly->doc_num))
        ->assertNotFound();

    $dataContent = $this->actingAs($actor)
        ->getJson(route('admin.accounting.accounts.data', [
            ...accountDataTableQuery(),
            'length' => 100,
        ]))
        ->assertOk()
        ->getContent();

    expect($dataContent)->not->toContain('Secondary Company Only');

    $this->actingAs($actor)
        ->postJson(route('admin.accounting.accounts.store'), accountPayload([
            'account_code' => '1901',
            'name' => 'Primary Company Same Code',
            'parent_doc_num' => null,
        ]))
        ->assertOk()
        ->assertJsonPath('success', true);

    $this->actingAs($actor)
        ->postJson(route('admin.accounting.accounts.store'), accountPayload([
            'account_code' => '1901',
            'name' => 'Primary Company Duplicate Code',
            'parent_doc_num' => null,
        ]))
        ->assertJsonValidationErrors(['account_code']);

    accountSetOperatingContext($secondary['company'], $secondary['branch'], $secondary['period']);
    $secondaryParentOnly = app(AccountService::class)->create(accountPayload([
        'account_code' => '1902',
        'name' => 'Secondary Parent Only',
    ]));
    accountSetOperatingContext($primary['company'], $primary['branch'], $primary['period']);

    $this->actingAs($actor)
        ->postJson(route('admin.accounting.accounts.store'), accountPayload([
            'account_code' => '1902',
            'name' => 'Cross Company Parent',
            'parent_doc_num' => $secondaryParentOnly->doc_num,
        ]))
        ->assertJsonValidationErrors(['parent_doc_num']);
});

test('accounts datatable sortable columns use qualified ordering', function () {
    $this->seed(AccountClassificationsSeeder::class);
    $this->seed(DefaultChartOfAccountsSeeder::class);
    $actor = accountActor(['accounts.view']);

    foreach (range(1, 12) as $column) {
        $this->actingAs($actor)
            ->getJson(route('admin.accounting.accounts.data', accountDataTableQuery($column)))
            ->assertOk()
            ->assertJsonPath('error', null);
    }

    $firstRow = $this->actingAs($actor)
        ->getJson(route('admin.accounting.accounts.data', accountDataTableQuery(2)))
        ->assertOk()
        ->json('data.0');

    expect($firstRow['account_code'])->toContain('>1<');
});

test('account classification labels are locale aware across table tree and exports', function () {
    $this->seed(AccountClassificationsSeeder::class);
    $this->seed(DefaultChartOfAccountsSeeder::class);
    $actor = accountActor(['accounts.view', 'accounts.export']);
    $report = app(AccountTreeReport::class);

    $actor->forceFill(['locale' => 'ar'])->save();
    app()->setLocale('ar');

    $arabicCell = $this->actingAs($actor)
        ->getJson(route('admin.accounting.accounts.data', [
            ...accountDataTableQuery(),
            'classification' => 'accounts_receivable',
        ]))
        ->assertOk()
        ->json('data.0.classification');

    expect($arabicCell)->toContain('عملاء / ذمم مدينة')
        ->and($arabicCell)->not->toContain('accounts_receivable')
        ->and($arabicCell)->not->toContain('Accounts Receivable');

    $arabicRows = $report->rows(['classification' => 'accounts_receivable']);
    $arabicExportRow = $report->map($arabicRows->firstWhere('account_code', '1121'));
    $arabicPdfRow = collect($report->pdfRows($arabicRows))->firstWhere('account_code', '1121');
    $arabicTreeNode = accountTreeNodeByCode($report->treeNodes($arabicRows), '1121');

    expect($arabicExportRow[4])->toBe('عملاء / ذمم مدينة')
        ->and($arabicPdfRow['classification'])->toBe('عملاء / ذمم مدينة')
        ->and($arabicTreeNode['classification'] ?? null)->toBe('عملاء / ذمم مدينة');

    $arabicSearchRows = $report->rows(['account_search' => 'ذمم']);
    expect($arabicSearchRows->pluck('account_code'))->toContain('1121');

    $actor->forceFill(['locale' => 'en'])->save();
    app()->setLocale('en');

    $englishCell = $this->actingAs($actor)
        ->getJson(route('admin.accounting.accounts.data', [
            ...accountDataTableQuery(),
            'classification' => 'cash',
        ]))
        ->assertOk()
        ->json('data.0.classification');

    expect($englishCell)->toContain('Cash')
        ->and($englishCell)->not->toContain('cash')
        ->and($englishCell)->not->toContain('نقدية');

    $englishRows = $report->rows(['classification' => 'cash']);
    $englishExportRow = $report->map($englishRows->firstWhere('account_code', '1111'));
    $englishPdfRow = collect($report->pdfRows($englishRows))->firstWhere('account_code', '1111');

    expect($englishExportRow[4])->toBe('Cash')
        ->and($englishPdfRow['classification'])->toBe('Cash');

    $englishSearchRows = $report->rows(['account_search' => 'Cash']);
    expect($englishSearchRows->pluck('account_code'))->toContain('1111');
});

test('account parent labels match datatable display across exports', function () {
    $this->seed(AccountClassificationsSeeder::class);
    $this->seed(DefaultChartOfAccountsSeeder::class);
    $actor = accountActor(['accounts.view', 'accounts.export']);
    $report = app(AccountTreeReport::class);

    $actor->forceFill(['locale' => 'ar'])->save();
    app()->setLocale('ar');

    $arabicTableRows = $this->actingAs($actor)
        ->getJson(route('admin.accounting.accounts.data', [
            ...accountDataTableQuery(),
            'length' => 50,
        ]))
        ->assertOk()
        ->json('data');

    $arabicTableRow = collect($arabicTableRows)->first(fn (array $row): bool => str_contains($row['account_code'], '>1111<'));
    $arabicRows = $report->rows();
    $arabicExportRoot = $report->map($arabicRows->firstWhere('account_code', '1'));
    $arabicExportChild = $report->map($arabicRows->firstWhere('account_code', '1111'));
    $arabicPdfChild = collect($report->pdfRows($arabicRows))->firstWhere('account_code', '1111');

    expect($arabicTableRow['parent'])->toContain('111 / النقدية وما في حكمها')
        ->and($arabicExportRoot[3])->toBe('')
        ->and($arabicExportChild[3])->toBe('111 / النقدية وما في حكمها')
        ->and($arabicPdfChild['parent_code'])->toBe('111 / النقدية وما في حكمها');

    $actor->forceFill(['locale' => 'en'])->save();
    app()->setLocale('en');

    $englishRows = $report->rows();
    $englishExportChild = $report->map($englishRows->firstWhere('account_code', '1111'));
    $englishPdfChild = collect($report->pdfRows($englishRows))->firstWhere('account_code', '1111');

    expect($englishExportChild[3])->toBe('111 / Cash and Cash Equivalents')
        ->and($englishPdfChild['parent_code'])->toBe('111 / Cash and Cash Equivalents');
});

test('accounts bulk delete accepts doc nums and trashed rows expose restore action', function () {
    $this->seed(DefaultChartOfAccountsSeeder::class);
    $actor = accountActor(['accounts.view', 'accounts.delete', 'accounts.view_trashed', 'accounts.restore']);
    $leaf = Account::query()->where('account_code', '551')->firstOrFail();

    $this->actingAs($actor)
        ->deleteJson(route('admin.accounting.accounts.bulk-delete'), ['doc_nums' => [$leaf->doc_num]])
        ->assertOk()
        ->assertJsonPath('success', true);

    expect(Account::withTrashed()->where('account_code', '551')->first()?->trashed())->toBeTrue();

    $trashData = $this->actingAs($actor)
        ->getJson(route('admin.accounting.accounts.data', [
            ...accountDataTableQuery(),
            'trash_filter' => 'trashed',
        ]))
        ->assertOk()
        ->json('data');

    expect($trashData[0]['checkbox'])->toBe('')
        ->and($trashData[0]['actions'])->toContain('js-restore-record')
        ->and($trashData[0]['actions'])->toContain('data-restore-url=')
        ->and($trashData[0]['actions'])->not->toContain('js-edit-record')
        ->and($trashData[0]['actions'])->not->toContain('data-delete-url=');
});

test('accounts tree and exports use report filters and require export permission', function () {
    $this->seed(AccountClassificationsSeeder::class);
    $this->seed(DefaultChartOfAccountsSeeder::class);
    $viewer = accountActor(['accounts.view']);
    $exporter = accountActor(['accounts.view', 'accounts.export']);

    $this->actingAs($viewer)
        ->get(route('admin.accounting.accounts.export.excel'))
        ->assertForbidden();

    $this->actingAs($exporter)
        ->getJson(route('admin.accounting.accounts.tree', ['statement_type' => 'income_statement']))
        ->assertOk()
        ->assertJsonPath('data.0.account_code', '4')
        ->assertJsonMissing(['account_code' => '1'])
        ->assertJsonMissingPath('data.0.account_type');

    $filteredRows = app(AccountTreeReport::class)->rows(['statement_type' => 'income_statement']);

    expect($filteredRows->pluck('statement_type')->unique()->all())->toBe(['income_statement']);

    $this->actingAs($exporter)
        ->get(route('admin.accounting.accounts.export.csv', ['statement_type' => 'income_statement']))
        ->assertOk();

    $this->actingAs($exporter)
        ->get(route('admin.accounting.accounts.export.excel', ['statement_type' => 'income_statement']))
        ->assertOk()
        ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    $this->actingAs($exporter)
        ->get(route('admin.accounting.accounts.export.pdf', ['statement_type' => 'income_statement']))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

test('account exports are flattened in tree traversal order with indentation', function () {
    $this->seed(AccountClassificationsSeeder::class);
    $this->seed(DefaultChartOfAccountsSeeder::class);
    accountActor(['accounts.view', 'accounts.export']);

    $report = app(AccountTreeReport::class);
    $rows = $report->rows();
    $codes = $rows->pluck('account_code');

    expect($codes->search('1'))->toBeLessThan($codes->search('11'))
        ->and($codes->search('11'))->toBeLessThan($codes->search('111'))
        ->and($codes->search('111'))->toBeLessThan($codes->search('1111'))
        ->and($codes->search('1111'))->toBeLessThan($codes->search('1112'))
        ->and($codes->search('1112'))->toBeLessThan($codes->search('112'))
        ->and($codes->search('112'))->toBeLessThan($codes->search('1121'))
        ->and($codes->search('12'))->toBeLessThan($codes->search('2'));

    $mappedRoot = $report->map($rows->firstWhere('account_code', '1'));
    $mappedChild = $report->map($rows->firstWhere('account_code', '11'));
    $mappedGrandchild = $report->map($rows->firstWhere('account_code', '111'));

    expect($report->headings()[0])->toBe(__('accounts.attributes.level'))
        ->and($report->headings())->not->toContain(__('accounts.attributes.account_type'))
        ->and($report->headings())->not->toContain(__('accounts.attributes.is_group'))
        ->and($report->headings())->not->toContain(__('accounts.attributes.is_postable'))
        ->and($report->pdfHeadings())->not->toContain(__('accounts.attributes.account_type'))
        ->and($report->pdfHeadings())->not->toContain(__('accounts.attributes.posting'))
        ->and($mappedRoot[0])->toBe('1')
        ->and($mappedRoot[2])->toBe('الأصول')
        ->and($mappedChild[0])->toBe('2')
        ->and($mappedChild[2])->toStartWith('  ')
        ->and($mappedGrandchild[0])->toBe('3')
        ->and($mappedGrandchild[2])->toStartWith('    ');
});

test('filtered account tree exports include parent context for matching children', function () {
    $this->seed(AccountClassificationsSeeder::class);
    $this->seed(DefaultChartOfAccountsSeeder::class);
    accountActor(['accounts.view', 'accounts.export']);

    $rows = app(AccountTreeReport::class)->rows(['classification' => 'cash']);

    expect($rows->pluck('account_code')->all())->toContain('1', '11', '111', '1111');
});

test('authorized user can create account with account code and parent', function () {
    $this->seed(AccountClassificationsSeeder::class);
    $this->seed(DefaultChartOfAccountsSeeder::class);
    $actor = accountActor(['accounts.view', 'accounts.create', 'accounts.edit', 'accounts.account_code.control']);
    $parent = Account::query()->where('account_code', '1')->firstOrFail();

    $this->actingAs($actor)
        ->postJson(route('admin.accounting.accounts.store'), [
            'account_code' => '19',
            'name' => 'أصول اختبارية',
            'name_en' => 'Test Assets',
            'parent_doc_num' => $parent->doc_num,
            'classification_code' => 'cash',
            'account_type' => 'asset',
            'statement_type' => 'financial_position',
            'normal_balance' => 'debit',
            'is_group' => '1',
            'is_postable' => '0',
            'status' => 'active',
        ])
        ->assertOk()
        ->assertJsonPath('success', true);

    $account = Account::query()->where('account_code', '19')->firstOrFail();

    expect($account->doc_num)->toStartWith('ACC-')
        ->and($account->parent_id)->toBe($parent->getKey())
        ->and($account->level)->toBe(2)
        ->and($account->classification?->code)->toBe('cash');
});

test('account form hides editable account type and keeps statement type read only', function () {
    $actor = accountActor(['accounts.view', 'accounts.create']);

    $this->actingAs($actor)
        ->get(route('admin.accounting.accounts.create'))
        ->assertOk()
        ->assertDontSee('<select class="form-select" id="account_type"', false)
        ->assertSee('type="hidden" id="account_type" name="account_type"', false)
        ->assertSee('id="statement_type_display"', false)
        ->assertSee('disabled required', false)
        ->assertSee(__('accounts.attributes.statement_type'))
        ->assertDontSee('نوع القائمة');
});

test('child account derives type and statement from parent while normal balance stays editable', function () {
    $this->seed(AccountClassificationsSeeder::class);
    $this->seed(DefaultChartOfAccountsSeeder::class);
    $actor = accountActor(['accounts.view', 'accounts.create', 'accounts.edit', 'accounts.account_code.control']);
    $parent = Account::query()->where('account_code', '11')->firstOrFail();

    $response = $this->actingAs($actor)
        ->postJson(route('admin.accounting.accounts.store'), accountPayload([
            'account_code' => '',
            'name' => 'Child Derived Account',
            'parent_doc_num' => $parent->doc_num,
            'classification_code' => 'sales_revenue',
            'account_type' => 'revenue',
            'statement_type' => 'income_statement',
            'normal_balance' => null,
        ]))
        ->assertOk();

    $account = Account::query()->where('doc_num', $response->json('data.doc_num'))->firstOrFail();

    expect($account->account_code)->toStartWith($parent->account_code)
        ->and($account->account_type)->toBe($parent->account_type)
        ->and($account->statement_type)->toBe($parent->statement_type)
        ->and($account->normal_balance)->toBe($parent->normal_balance);

    $this->actingAs($actor)
        ->putJson(route('admin.accounting.accounts.update', $account->doc_num), accountPayload([
            'account_code' => $account->account_code,
            'name' => $account->name,
            'parent_doc_num' => $parent->doc_num,
            'classification_code' => 'sales_revenue',
            'account_type' => 'expense',
            'statement_type' => 'income_statement',
            'normal_balance' => 'credit',
            'status' => 'active',
        ]))
        ->assertOk();

    $account->refresh();

    expect($account->account_type)->toBe($parent->account_type)
        ->and($account->statement_type)->toBe($parent->statement_type)
        ->and($account->normal_balance)->toBe('credit');
});

test('root account derives statement and defaults from classification when no parent exists', function () {
    $this->seed(AccountClassificationsSeeder::class);
    $actor = accountActor(['accounts.view', 'accounts.create', 'accounts.account_code.control']);

    $response = $this->actingAs($actor)
        ->postJson(route('admin.accounting.accounts.store'), accountPayload([
            'account_code' => '61',
            'name' => 'Root Revenue',
            'parent_doc_num' => null,
            'classification_code' => 'sales_revenue',
            'account_type' => 'asset',
            'statement_type' => 'financial_position',
            'normal_balance' => null,
        ]))
        ->assertOk();

    $account = Account::query()->where('doc_num', $response->json('data.doc_num'))->firstOrFail();
    $classification = AccountClassification::query()->where('code', 'sales_revenue')->firstOrFail();

    expect($account->account_type)->toBe($classification->account_type)
        ->and($account->statement_type)->toBe($classification->statement_type)
        ->and($account->normal_balance)->toBe($classification->normal_balance);
});

test('account create submit actions return standard crud redirects', function (string $submitAction, string $routeName) {
    $actor = accountActor(['accounts.view', 'accounts.create', 'accounts.edit', 'accounts.clone', 'accounts.account_code.control']);

    $response = $this->actingAs($actor)
        ->postJson(route('admin.accounting.accounts.store'), accountPayload([
            'account_code' => fake()->unique()->numerify('19###'),
            'submit_action' => $submitAction,
        ]))
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('submit_action', $submitAction);

    $account = Account::query()->where('doc_num', $response->json('data.doc_num'))->firstOrFail();

    expect($response->json('redirect'))->toBe(route($routeName, $routeName === 'admin.accounting.accounts.index' ? [] : [$account->doc_num]));
})->with([
    'save view' => ['save_view', 'admin.accounting.accounts.show'],
    'save edit' => ['save_edit', 'admin.accounting.accounts.edit'],
    'save back' => ['save_back', 'admin.accounting.accounts.index'],
    'save clone' => ['save_clone', 'admin.accounting.accounts.clone'],
]);

test('account create normal save behaves as save and new', function () {
    $actor = accountActor(['accounts.view', 'accounts.create', 'accounts.edit', 'accounts.account_code.control']);

    $response = $this->actingAs($actor)
        ->postJson(route('admin.accounting.accounts.store'), accountPayload([
            'account_code' => '1908',
        ]))
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('submit_action', 'save_new')
        ->assertJsonPath('reset_form', true);

    expect($response->json('redirect'))->toBeNull()
        ->and(Account::query()->where('account_code', '1908')->exists())->toBeTrue();

    $this->actingAs($actor)
        ->get(route('admin.accounting.accounts.create'))
        ->assertOk()
        ->assertDontSee('1908', false)
        ->assertDontSee('حساب اختبار', false);
});

test('account create validation failure preserves old input', function () {
    $actor = accountActor(['accounts.create', 'accounts.account_code.control']);

    $this->actingAs($actor)
        ->post(route('admin.accounting.accounts.store'), accountPayload([
            'account_code' => '1907',
            'name' => '',
        ]))
        ->assertSessionHasErrors(['name'])
        ->assertSessionHasInput('account_code', '1907');
});

test('account create save_new keeps the fresh create flow', function () {
    $actor = accountActor(['accounts.view', 'accounts.create', 'accounts.edit', 'accounts.account_code.control']);

    $response = $this->actingAs($actor)
        ->postJson(route('admin.accounting.accounts.store'), accountPayload([
            'account_code' => '1909',
            'submit_action' => 'save_new',
        ]))
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('submit_action', 'save_new')
        ->assertJsonPath('reset_form', true);

    expect(Account::query()->where('account_code', '1909')->exists())->toBeTrue()
        ->and($response->json('redirect'))->toBeNull();
});

test('account view displays audit information without internal user ids', function () {
    $actor = accountActor(['accounts.view', 'accounts.create', 'accounts.edit', 'accounts.delete', 'accounts.view_trashed', 'accounts.account_code.control']);
    $account = app(AccountService::class)->create(accountPayload([
        'account_code' => '1911',
        'name' => 'Audit Account',
    ]));

    $this->actingAs($actor)
        ->putJson(route('admin.accounting.accounts.update', $account->doc_num), accountPayload([
            'account_code' => $account->account_code,
            'name' => 'Updated Audit Account',
        ]))
        ->assertOk();

    $account->refresh();

    $view = $this->actingAs($actor)
        ->get(route('admin.accounting.accounts.show', $account->doc_num))
        ->assertOk()
        ->assertSee(__('common.fields.created_by'))
        ->assertSee(__('common.fields.created_at'))
        ->assertSee(__('common.fields.updated_by'))
        ->assertSee(__('common.fields.updated_at'))
        ->assertSee($actor->name)
        ->assertSee($actor->doc_num)
        ->assertDontSee('>'.$actor->getKey().'<', false);

    expect($view->getContent())->toContain('aria-label="'.__('common.sections.audit_information').'"');

    $this->actingAs($actor)
        ->deleteJson(route('admin.accounting.accounts.destroy', $account->doc_num))
        ->assertOk();

    $this->actingAs($actor)
        ->get(route('admin.accounting.accounts.show', $account->doc_num))
        ->assertOk()
        ->assertSee(__('common.fields.deleted_by'))
        ->assertSee(__('common.fields.deleted_at'))
        ->assertSee($actor->name)
        ->assertSee($actor->doc_num)
        ->assertDontSee('>'.$actor->getKey().'<', false);
});

test('account edit no-change and action visibility follow standard crud behavior', function () {
    $actor = accountActor(['accounts.view', 'accounts.create', 'accounts.edit', 'accounts.clone', 'accounts.delete', 'accounts.account_code.control']);
    $account = app(AccountService::class)->create(accountPayload(['account_code' => '1910']));

    $this->actingAs($actor)
        ->get(route('admin.accounting.accounts.create'))
        ->assertOk()
        ->assertDontSee(__('common.actions.save_and_new'));

    $this->actingAs($actor)
        ->get(route('admin.accounting.accounts.edit', $account->doc_num))
        ->assertOk()
        ->assertDontSee(__('common.actions.save_and_new'))
        ->assertDontSee(__('common.actions.save_and_edit'));

    $this->actingAs($actor)
        ->get(route('admin.accounting.accounts.show', $account->doc_num))
        ->assertOk()
        ->assertDontSee(__('common.actions.save_data'));

    $this->actingAs($actor)
        ->putJson(route('admin.accounting.accounts.update', $account->doc_num), [
            ...accountPayload([
                'account_code' => $account->account_code,
                'name' => $account->name,
                'account_type' => $account->account_type,
                'statement_type' => $account->statement_type,
                'normal_balance' => $account->normal_balance,
                'status' => $account->status,
                'is_postable' => '1',
                'submit_action' => 'save_edit',
            ]),
        ])
        ->assertOk()
        ->assertJsonPath('success', false)
        ->assertJsonPath('type', 'no_changes')
        ->assertJsonPath('submit_action', 'save');

    $this->actingAs($actor)
        ->putJson(route('admin.accounting.accounts.update', $account->doc_num), [
            ...accountPayload([
                'account_code' => $account->account_code,
                'name' => 'حساب اختبار بعد التحديث',
                'account_type' => $account->account_type,
                'statement_type' => $account->statement_type,
                'normal_balance' => $account->normal_balance,
                'status' => $account->status,
                'is_postable' => '1',
                'submit_action' => 'save_new',
            ]),
        ])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('submit_action', 'save');
});

test('system root account delete is blocked', function () {
    $this->seed(DefaultChartOfAccountsSeeder::class);
    $actor = accountActor(['accounts.delete']);
    $root = Account::query()->where('account_code', '1')->firstOrFail();

    $this->actingAs($actor)
        ->deleteJson(route('admin.accounting.accounts.destroy', $root->doc_num))
        ->assertStatus(422)
        ->assertJsonPath('success', false);
});
