<?php

use App\Models\User;
use Database\Seeders\DefaultOperatingContextSeeder;
use Modules\Accounting\Database\Seeders\AccountClassificationsSeeder;
use Modules\Accounting\Database\Seeders\DefaultChartOfAccountsSeeder;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\CostCenter;
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Services\LedgerQueryService;
use Modules\Auth\Database\Seeders\PermissionSeeder;
use Modules\Auth\Models\Role;
use Modules\Core\Database\Seeders\CurrencySeeder;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\Currency;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\OperatingContextService;
use Modules\Purchases\Models\Supplier;
use Modules\Sales\Models\Customer;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * @return array{company: Company, branch: Branch, period: FinancialPeriod, currency: Currency}
 */
function journalEntryContext(): array
{
    test()->seed(DefaultOperatingContextSeeder::class);
    test()->seed(AccountClassificationsSeeder::class);
    test()->seed(DefaultChartOfAccountsSeeder::class);
    test()->seed(CurrencySeeder::class);

    $company = Company::query()->active()->orderBy('id')->firstOrFail();
    $branch = Branch::query()->where('company_id', $company->getKey())->active()->orderBy('id')->firstOrFail();
    $period = FinancialPeriod::query()->where('company_id', $company->getKey())->open()->orderBy('id')->firstOrFail();
    $currency = Currency::query()->forCompany($company->getKey())->active()->where('is_main', true)->firstOrFail();

    test()->withSession([
        OperatingContextService::CompanyIdKey => $company->getKey(),
        OperatingContextService::CompanyDocNumKey => $company->doc_num,
        OperatingContextService::BranchIdKey => $branch->getKey(),
        OperatingContextService::BranchDocNumKey => $branch->doc_num,
        OperatingContextService::FinancialPeriodIdKey => $period->getKey(),
        OperatingContextService::FinancialPeriodDocNumKey => $period->doc_num,
    ]);

    return compact('company', 'branch', 'period', 'currency');
}

function journalEntryActor(array $permissions): User
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $actor = User::factory()->create();
    $actor->givePermissionTo($permissions);

    return $actor;
}

/**
 * @return array{0: Account, 1: Account}
 */
function journalEntryAccounts(Company $company): array
{
    $accounts = Account::query()
        ->forCompany($company->getKey())
        ->active()
        ->where('is_group', false)
        ->where('is_postable', true)
        ->orderBy('account_code')
        ->limit(2)
        ->get();

    return [$accounts->get(0), $accounts->get(1)];
}

/**
 * @return array<string, mixed>
 */
function journalEntryPayload(Account $debit, Account $credit, array $overrides = []): array
{
    return [
        'entry_date' => '2026-04-15',
        'reference_no' => 'MANUAL-REF-1',
        'description' => 'Manual balanced entry',
        'notes' => null,
        'lines' => [
            [
                'account_doc_num' => $debit->doc_num,
                'debit_amount' => '250.0000',
                'credit_amount' => '0',
                'description' => 'Debit line',
            ],
            [
                'account_doc_num' => $credit->doc_num,
                'debit_amount' => '0',
                'credit_amount' => '250.0000',
                'description' => 'Credit line',
            ],
        ],
        ...$overrides,
    ];
}

function journalPostedMovement(
    array $context,
    Account $subject,
    Account $counterpart,
    int $docNumber,
    string $date,
    string $debit,
    string $credit,
    string $exchangeRate = '1.000000',
): JournalEntry {
    $entry = JournalEntry::query()->create([
        'doc_number' => $docNumber,
        'doc_num' => 'JE-'.str_pad((string) $docNumber, 5, '0', STR_PAD_LEFT),
        'entry_date' => $date,
        'company_id' => $context['company']->getKey(),
        'financial_period_id' => $context['period']->getKey(),
        'branch_id' => $context['branch']->getKey(),
        'currency_id' => $context['currency']->getKey(),
        'exchange_rate' => $exchangeRate,
        'description' => "Movement {$docNumber}",
        'status' => JournalEntry::StatusPosted,
        'is_posted' => true,
        'is_system_generated' => false,
        'approved' => true,
        'posted_at' => now(),
    ]);
    $entry->lines()->create([
        'line_no' => 1,
        'account_id' => $subject->getKey(),
        'debit_amount' => $debit,
        'credit_amount' => $credit,
        'description' => "Subject {$docNumber}",
        'branch_id' => $context['branch']->getKey(),
    ]);
    $entry->lines()->create([
        'line_no' => 2,
        'account_id' => $counterpart->getKey(),
        'debit_amount' => $credit,
        'credit_amount' => $debit,
        'description' => "Counterpart {$docNumber}",
        'branch_id' => $context['branch']->getKey(),
    ]);

    return $entry;
}

test('journal and ledger permissions are discovered and menu items are visible', function (): void {
    $this->seed(PermissionSeeder::class);
    $admin = Role::query()->where('name', 'admin')->where('guard_name', 'web')->firstOrFail();
    $permissions = [
        'journal_entries.view',
        'journal_entries.create',
        'journal_entries.edit',
        'journal_entries.delete',
        'journal_entries.post',
        'journal_entries.view_trashed',
        'journal_entries.restore',
        'reports.account_ledger.view',
        'reports.account_ledger.export',
        'reports.customer_statement.view',
        'reports.customer_statement.export',
        'reports.supplier_statement.view',
        'reports.supplier_statement.export',
    ];

    foreach ($permissions as $permission) {
        expect(Permission::query()->where('name', $permission)->exists())->toBeTrue()
            ->and($admin->hasPermissionTo($permission))->toBeTrue();
    }

    $children = collect(require config_path('menu/accounting.php'))->first()['children'] ?? [];

    expect(collect($children)->pluck('label')->all())
        ->toContain('journal_entries', 'account_ledger', 'customer_statement', 'supplier_statement');
});

test('manual journal lifecycle is balanced draft to posted and posted entries are immutable', function (): void {
    $context = journalEntryContext();
    [$debit, $credit] = journalEntryAccounts($context['company']);
    $actor = journalEntryActor(['journal_entries.view', 'journal_entries.create', 'journal_entries.edit', 'journal_entries.post', 'journal_entries.delete']);
    $payload = journalEntryPayload($debit, $credit);

    $created = $this->actingAs($actor)
        ->postJson(route('admin.accounting.journal-entries.store'), $payload)
        ->assertOk()
        ->assertJsonPath('success', true)
        ->json('data.doc_num');
    $entry = JournalEntry::query()->where('doc_num', $created)->firstOrFail();

    expect($entry->status)->toBe(JournalEntry::StatusDraft)
        ->and($entry->is_posted)->toBeFalse()
        ->and($entry->is_system_generated)->toBeFalse()
        ->and($entry->lines()->count())->toBe(2)
        ->and($entry->lines()->sum('debit_amount'))->toEqual($entry->lines()->sum('credit_amount'));

    $this->actingAs($actor)
        ->putJson(route('admin.accounting.journal-entries.update', $entry->doc_num), $payload)
        ->assertOk()
        ->assertJsonPath('type', 'no_changes');

    $this->actingAs($actor)
        ->postJson(route('admin.accounting.journal-entries.post', $entry->doc_num))
        ->assertOk()
        ->assertJsonPath('success', true);

    $entry->refresh();
    expect($entry->status)->toBe(JournalEntry::StatusPosted)
        ->and($entry->is_posted)->toBeTrue()
        ->and($entry->posted_at)->not->toBeNull()
        ->and($entry->posted_by)->toBe($actor->getKey());

    $this->actingAs($actor)
        ->putJson(route('admin.accounting.journal-entries.update', $entry->doc_num), journalEntryPayload($debit, $credit, ['description' => 'Attempted change']))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['document']);

    $this->actingAs($actor)
        ->deleteJson(route('admin.accounting.journal-entries.destroy', $entry->doc_num))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['document']);
});

test('journal validation rejects unbalanced and double sided lines', function (): void {
    $context = journalEntryContext();
    [$debit, $credit] = journalEntryAccounts($context['company']);
    $actor = journalEntryActor(['journal_entries.create']);
    $payload = journalEntryPayload($debit, $credit);
    $payload['lines'][0]['credit_amount'] = '10.0000';
    $payload['lines'][1]['credit_amount'] = '200.0000';

    $this->actingAs($actor)
        ->postJson(route('admin.accounting.journal-entries.store'), $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['lines.0.debit_amount', 'lines']);

    expect(JournalEntry::query()->where('description', 'Manual balanced entry')->exists())->toBeFalse();
});

test('journal validation rejects parent and non postable accounts', function (): void {
    $context = journalEntryContext();
    [, $credit] = journalEntryAccounts($context['company']);
    $parent = Account::query()
        ->forCompany($context['company']->getKey())
        ->active()
        ->where('is_group', true)
        ->firstOrFail();
    $actor = journalEntryActor(['journal_entries.create']);
    $payload = journalEntryPayload($parent, $credit);

    $this->actingAs($actor)
        ->postJson(route('admin.accounting.journal-entries.store'), $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['lines.0.account_doc_num']);

    expect(JournalEntry::query()->where('description', 'Manual balanced entry')->exists())->toBeFalse();
});

test('posting revalidates draft accounts before creating ledger movements', function (): void {
    $context = journalEntryContext();
    [$debit, $credit] = journalEntryAccounts($context['company']);
    $actor = journalEntryActor(['journal_entries.create', 'journal_entries.post']);
    $docNum = $this->actingAs($actor)
        ->postJson(route('admin.accounting.journal-entries.store'), journalEntryPayload($debit, $credit))
        ->assertOk()
        ->json('data.doc_num');

    $debit->update(['status' => 'inactive']);

    $this->actingAs($actor)
        ->postJson(route('admin.accounting.journal-entries.post', $docNum))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['document']);

    $entry = JournalEntry::query()->where('doc_num', $docNum)->firstOrFail();
    expect($entry->status)->toBe(JournalEntry::StatusDraft)
        ->and($entry->is_posted)->toBeFalse();
});

test('journal lines persist gl account and cost center independently of the cost center default', function (): void {
    $context = journalEntryContext();
    [$debit, $credit] = journalEntryAccounts($context['company']);
    $defaultAccount = Account::query()
        ->forCompany($context['company']->getKey())
        ->active()
        ->where('is_group', false)
        ->where('is_postable', true)
        ->whereKeyNot([$debit->getKey(), $credit->getKey()])
        ->orderBy('account_code')
        ->firstOrFail();
    $costCenter = CostCenter::query()->create([
        'company_id' => $context['company']->getKey(),
        'default_account_id' => $defaultAccount->getKey(),
        'doc_number' => 88001,
        'doc_num' => 'CC-88001',
        'cost_center_code' => '88001',
        'name' => 'Production Line 1',
        'is_group' => false,
        'status' => 'active',
    ]);
    $actor = journalEntryActor(['journal_entries.create', 'journal_entries.post']);
    $payload = journalEntryPayload($debit, $credit);
    $payload['lines'][0]['cost_center_doc_num'] = $costCenter->doc_num;
    $payload['lines'][1]['cost_center_doc_num'] = $costCenter->doc_num;

    $entryDocNum = $this->actingAs($actor)
        ->postJson(route('admin.accounting.journal-entries.store'), $payload)
        ->assertOk()
        ->json('data.doc_num');
    $entry = JournalEntry::query()->where('doc_num', $entryDocNum)->firstOrFail();
    $linesBeforeDefaultChange = $entry->lines()->orderBy('line_no')->get();

    expect($linesBeforeDefaultChange[0]->account_id)->toBe($debit->getKey())
        ->and($linesBeforeDefaultChange[0]->account_id)->not->toBe($defaultAccount->getKey())
        ->and($linesBeforeDefaultChange[0]->cost_center_id)->toBe($costCenter->getKey())
        ->and($linesBeforeDefaultChange[1]->account_id)->toBe($credit->getKey())
        ->and($linesBeforeDefaultChange[1]->cost_center_id)->toBe($costCenter->getKey());

    $this->actingAs($actor)
        ->postJson(route('admin.accounting.journal-entries.post', $entry->doc_num))
        ->assertOk()
        ->assertJsonPath('success', true);

    $costCenter->update(['default_account_id' => $credit->getKey()]);
    $linesAfterDefaultChange = $entry->lines()->orderBy('line_no')->get();

    expect($linesAfterDefaultChange->pluck('account_id')->all())->toBe([$debit->getKey(), $credit->getKey()])
        ->and($linesAfterDefaultChange->pluck('cost_center_id')->all())->toBe([$costCenter->getKey(), $costCenter->getKey()])
        ->and($entry->refresh()->is_posted)->toBeTrue();
});

test('active journal route never resolves a deleted duplicate and restore collision is rejected', function (): void {
    $context = journalEntryContext();
    $actor = journalEntryActor(['journal_entries.view', 'journal_entries.view_trashed', 'journal_entries.restore']);
    $values = [
        'doc_number' => 99001,
        'doc_num' => 'JE-99001',
        'entry_date' => '2026-03-01',
        'company_id' => $context['company']->getKey(),
        'financial_period_id' => $context['period']->getKey(),
        'branch_id' => $context['branch']->getKey(),
        'currency_id' => $context['currency']->getKey(),
        'exchange_rate' => 1,
        'status' => JournalEntry::StatusDraft,
        'is_system_generated' => false,
        'is_posted' => false,
    ];
    $deleted = JournalEntry::query()->create([...$values, 'description' => 'Deleted duplicate']);
    $deleted->delete();
    expect($deleted->fresh()->trashed())->toBeTrue();
    $active = JournalEntry::query()->create([...$values, 'description' => 'Active duplicate']);

    $this->actingAs($actor)
        ->get(route('admin.accounting.journal-entries.show', $active->doc_num))
        ->assertOk()
        ->assertSee('Active duplicate')
        ->assertDontSee('Deleted duplicate');
    $this->actingAs($actor)
        ->get(route('admin.accounting.journal-entries.trashed.show', $deleted->doc_num))
        ->assertOk()
        ->assertSee('Deleted duplicate')
        ->assertDontSee('Active duplicate');
    $this->actingAs($actor)
        ->patchJson(route('admin.accounting.journal-entries.restore', $deleted->doc_num))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['document']);

    expect($deleted->refresh()->trashed())->toBeTrue();
});

test('canonical ledger calculates opening movement and ending debit deterministically', function (): void {
    $context = journalEntryContext();
    [$subject, $counterpart] = journalEntryAccounts($context['company']);
    $actor = journalEntryActor(['journal_entries.view', 'reports.account_ledger.view', 'reports.account_ledger.export']);
    journalPostedMovement($context, $subject, $counterpart, 99101, '2026-01-01', '1000.0000', '0.0000');
    journalPostedMovement($context, $subject, $counterpart, 99102, '2026-01-10', '300.0000', '0.0000');
    journalPostedMovement($context, $subject, $counterpart, 99103, '2026-01-15', '0.0000', '200.0000');

    $result = app(LedgerQueryService::class)->accountLedger([
        'company_id' => $context['company']->getKey(),
        'financial_period_id' => $context['period']->getKey(),
        'account_id' => $subject->getKey(),
        'from_date' => '2026-01-02',
        'to_date' => '2026-01-31',
        'branch_id' => null,
        'cost_center_id' => null,
    ]);

    expect($result['opening'])->toBe(['debit' => '1000.0000', 'credit' => '0.0000'])
        ->and($result['period'])->toBe(['debit' => '300.0000', 'credit' => '200.0000'])
        ->and($result['ending'])->toBe(['debit' => '1100.0000', 'credit' => '0.0000'])
        ->and(collect($result['movements'])->pluck('doc_num')->all())->toBe(['JE-99102', 'JE-99103'])
        ->and($result['movements'][1]['running_debit'])->toBe('1100.0000');

    $this->actingAs($actor)
        ->get(route('admin.accounting.reports.account-ledger', [
            'run' => 1,
            'account_doc_num' => $subject->doc_num,
            'from_date' => '2026-01-02',
            'to_date' => '2026-01-31',
        ]))
        ->assertOk()
        ->assertSee(__('ledger_reports.types.account_ledger'))
        ->assertSee('1,000')
        ->assertSee('1,100')
        ->assertSee('JE-99102')
        ->assertSee('JE-99103');

    $this->actingAs($actor)
        ->get(route('admin.accounting.reports.account-ledger.export.csv', [
            'run' => 1,
            'account_doc_num' => $subject->doc_num,
            'from_date' => '2026-01-02',
            'to_date' => '2026-01-31',
        ]))
        ->assertOk()
        ->assertDownload('account-ledger.csv');
});

test('canonical ledger normalizes posted foreign currency amounts to the main currency', function (): void {
    $context = journalEntryContext();
    [$subject, $counterpart] = journalEntryAccounts($context['company']);
    journalPostedMovement($context, $subject, $counterpart, 99110, '2026-01-10', '50.0000', '0.0000', '2.000000');

    $result = app(LedgerQueryService::class)->accountLedger([
        'company_id' => $context['company']->getKey(),
        'financial_period_id' => $context['period']->getKey(),
        'account_id' => $subject->getKey(),
        'from_date' => '2026-01-01',
        'to_date' => '2026-01-31',
        'branch_id' => null,
        'cost_center_id' => null,
    ]);

    expect($result['period'])->toBe(['debit' => '100.0000', 'credit' => '0.0000'])
        ->and($result['ending'])->toBe(['debit' => '100.0000', 'credit' => '0.0000'])
        ->and($result['currency']['code'])->toBe($context['currency']->code);
});

test('account ledger PDF export uses the standard report renderer', function (): void {
    $context = journalEntryContext();
    [$subject, $counterpart] = journalEntryAccounts($context['company']);
    $actor = journalEntryActor(['reports.account_ledger.export']);
    journalPostedMovement($context, $subject, $counterpart, 99111, '2026-01-10', '75.0000', '0.0000');

    $response = $this->actingAs($actor)
        ->get(route('admin.accounting.reports.account-ledger.export.pdf', [
            'run' => 1,
            'account_doc_num' => $subject->doc_num,
            'from_date' => '2026-01-01',
            'to_date' => '2026-01-31',
        ]))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    expect(strlen($response->getContent()))->toBeGreaterThan(1000);
});

test('customer statement uses only the selected customer linked account movements', function (): void {
    $context = journalEntryContext();
    [, $counterpart] = journalEntryAccounts($context['company']);
    $actor = journalEntryActor(['journal_entries.view', 'reports.customer_statement.view']);
    $customerAccountA = Account::query()->create([
        'doc_number' => 99201,
        'doc_num' => 'ACC-99201',
        'company_id' => $context['company']->getKey(),
        'account_code' => 'CUST-99201',
        'name' => 'Customer A Account',
        'account_type' => Account::TypeAsset,
        'statement_type' => Account::StatementFinancialPosition,
        'normal_balance' => Account::BalanceDebit,
        'is_group' => false,
        'is_postable' => true,
        'status' => 'active',
    ]);
    $customerAccountB = Account::query()->create([
        'doc_number' => 99202,
        'doc_num' => 'ACC-99202',
        'company_id' => $context['company']->getKey(),
        'account_code' => 'CUST-99202',
        'name' => 'Customer B Account',
        'account_type' => Account::TypeAsset,
        'statement_type' => Account::StatementFinancialPosition,
        'normal_balance' => Account::BalanceDebit,
        'is_group' => false,
        'is_postable' => true,
        'status' => 'active',
    ]);
    $customerA = Customer::query()->create([
        'doc_number' => 99201,
        'doc_num' => 'CUS-99201',
        'company_id' => $context['company']->getKey(),
        'account_id' => $customerAccountA->getKey(),
        'name' => 'Customer A',
        'status' => 'active',
    ]);
    Customer::query()->create([
        'doc_number' => 99202,
        'doc_num' => 'CUS-99202',
        'company_id' => $context['company']->getKey(),
        'account_id' => $customerAccountB->getKey(),
        'name' => 'Customer B',
        'status' => 'active',
    ]);
    journalPostedMovement($context, $customerAccountA, $counterpart, 99211, '2026-02-10', '120.0000', '0.0000');
    journalPostedMovement($context, $customerAccountB, $counterpart, 99212, '2026-02-11', '870.0000', '0.0000');

    $this->actingAs($actor)
        ->get(route('admin.accounting.reports.customer-statement', [
            'run' => 1,
            'customer_doc_num' => $customerA->doc_num,
            'from_date' => '2026-02-01',
            'to_date' => '2026-02-28',
        ]))
        ->assertOk()
        ->assertSee('Customer A')
        ->assertSee('JE-99211')
        ->assertDontSee('JE-99212')
        ->assertDontSee('870');
});

test('supplier statement uses only the selected supplier linked account movements', function (): void {
    $context = journalEntryContext();
    [, $counterpart] = journalEntryAccounts($context['company']);
    $actor = journalEntryActor(['journal_entries.view', 'reports.supplier_statement.view']);
    $supplierAccountA = Account::query()->create([
        'doc_number' => 99301,
        'doc_num' => 'ACC-99301',
        'company_id' => $context['company']->getKey(),
        'account_code' => 'SUP-99301',
        'name' => 'Supplier A Account',
        'account_type' => Account::TypeLiability,
        'statement_type' => Account::StatementFinancialPosition,
        'normal_balance' => Account::BalanceCredit,
        'is_group' => false,
        'is_postable' => true,
        'status' => 'active',
    ]);
    $supplierAccountB = Account::query()->create([
        'doc_number' => 99302,
        'doc_num' => 'ACC-99302',
        'company_id' => $context['company']->getKey(),
        'account_code' => 'SUP-99302',
        'name' => 'Supplier B Account',
        'account_type' => Account::TypeLiability,
        'statement_type' => Account::StatementFinancialPosition,
        'normal_balance' => Account::BalanceCredit,
        'is_group' => false,
        'is_postable' => true,
        'status' => 'active',
    ]);
    $supplierA = Supplier::query()->create([
        'doc_number' => 99301,
        'doc_num' => 'SUP-99301',
        'company_id' => $context['company']->getKey(),
        'account_id' => $supplierAccountA->getKey(),
        'name' => 'Supplier A',
        'status' => 'active',
    ]);
    Supplier::query()->create([
        'doc_number' => 99302,
        'doc_num' => 'SUP-99302',
        'company_id' => $context['company']->getKey(),
        'account_id' => $supplierAccountB->getKey(),
        'name' => 'Supplier B',
        'status' => 'active',
    ]);
    journalPostedMovement($context, $supplierAccountA, $counterpart, 99311, '2026-03-10', '0.0000', '250.0000');
    journalPostedMovement($context, $supplierAccountB, $counterpart, 99312, '2026-03-11', '0.0000', '910.0000');

    $this->actingAs($actor)
        ->get(route('admin.accounting.reports.supplier-statement', [
            'run' => 1,
            'supplier_doc_num' => $supplierA->doc_num,
            'from_date' => '2026-03-01',
            'to_date' => '2026-03-31',
        ]))
        ->assertOk()
        ->assertSee('Supplier A')
        ->assertSee('JE-99311')
        ->assertDontSee('JE-99312')
        ->assertDontSee('910');
});
