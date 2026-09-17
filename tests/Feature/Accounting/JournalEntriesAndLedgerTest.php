<?php

use App\Models\User;
use Database\Seeders\DefaultOperatingContextSeeder;
use Modules\Accounting\Database\Seeders\AccountClassificationsSeeder;
use Modules\Accounting\Database\Seeders\DefaultChartOfAccountsSeeder;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\CostCenter;
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Services\LedgerQueryService;
use Modules\Accounting\Services\TrialBalanceQueryService;
use Modules\Auth\Database\Seeders\PermissionSeeder;
use Modules\Auth\Models\Role;
use Modules\Core\Database\Seeders\CurrencySeeder;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\Currency;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\DateFormatService;
use Modules\Core\Services\OperatingContextService;
use Modules\Purchases\Models\Supplier;
use Modules\Sales\Models\Customer;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\Process\Process;

function accountingPdfText(string $content): string
{
    $path = tempnam(sys_get_temp_dir(), 'accounting-pdf-');
    file_put_contents($path, $content);

    try {
        $process = new Process(['pdftotext', '-layout', $path, '-']);
        $process->mustRun();

        return $process->getOutput();
    } finally {
        @unlink($path);
    }
}

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
        'financial_periods.close',
        'financial_periods.reopen',
        'reports.account_ledger.view',
        'reports.account_ledger.export',
        'reports.trial_balance.view',
        'reports.trial_balance.export',
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
        ->toContain('journal_entries', 'account_ledger', 'trial_balance', 'customer_statement', 'supplier_statement');
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

test('journal lines persist gl account and cost center independently of linked cost center accounts', function (): void {
    $context = journalEntryContext();
    [$debit, $credit] = journalEntryAccounts($context['company']);
    $linkedAccount = Account::query()
        ->forCompany($context['company']->getKey())
        ->active()
        ->where('is_group', false)
        ->where('is_postable', true)
        ->whereKeyNot([$debit->getKey(), $credit->getKey()])
        ->orderBy('account_code')
        ->firstOrFail();
    $costCenter = CostCenter::query()->create([
        'company_id' => $context['company']->getKey(),
        'doc_number' => 88001,
        'doc_num' => 'CC-88001',
        'cost_center_code' => '88001',
        'name' => 'Production Line 1',
        'is_group' => false,
        'status' => 'active',
    ]);
    $costCenter->accounts()->attach($linkedAccount);
    $actor = journalEntryActor(['journal_entries.create', 'journal_entries.post']);
    $payload = journalEntryPayload($debit, $credit);
    $payload['lines'][0]['cost_center_doc_num'] = $costCenter->doc_num;
    $payload['lines'][1]['cost_center_doc_num'] = $costCenter->doc_num;

    $entryDocNum = $this->actingAs($actor)
        ->postJson(route('admin.accounting.journal-entries.store'), $payload)
        ->assertOk()
        ->json('data.doc_num');
    $entry = JournalEntry::query()->where('doc_num', $entryDocNum)->firstOrFail();
    $linesBeforeLinkChange = $entry->lines()->orderBy('line_no')->get();

    expect($linesBeforeLinkChange[0]->account_id)->toBe($debit->getKey())
        ->and($linesBeforeLinkChange[0]->account_id)->not->toBe($linkedAccount->getKey())
        ->and($linesBeforeLinkChange[0]->cost_center_id)->toBe($costCenter->getKey())
        ->and($linesBeforeLinkChange[1]->account_id)->toBe($credit->getKey())
        ->and($linesBeforeLinkChange[1]->cost_center_id)->toBe($costCenter->getKey());

    $this->actingAs($actor)
        ->postJson(route('admin.accounting.journal-entries.post', $entry->doc_num))
        ->assertOk()
        ->assertJsonPath('success', true);

    $costCenter->accounts()->sync([$credit->getKey()]);
    $linesAfterLinkChange = $entry->lines()->orderBy('line_no')->get();

    expect($linesAfterLinkChange->pluck('account_id')->all())->toBe([$debit->getKey(), $credit->getKey()])
        ->and($linesAfterLinkChange->pluck('cost_center_id')->all())->toBe([$costCenter->getKey(), $costCenter->getKey()])
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
        ->and(collect($result['opening_movements'])->pluck('doc_num')->all())->toBe(['JE-99101'])
        ->and($result['opening_movements'][0]['running_debit'])->toBe('1000.0000')
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

test('trial balance uses posted journals across periods without double counting hierarchy totals', function (): void {
    $context = journalEntryContext();
    $actor = journalEntryActor([
        'reports.trial_balance.view',
        'reports.trial_balance.export',
        'reports.account_ledger.view',
    ]);
    $priorPeriod = FinancialPeriod::query()->create([
        'doc_number' => 99901,
        'doc_num' => 'FP-TB-PRIOR',
        'company_id' => $context['company']->getKey(),
        'name' => 'Trial balance prior year',
        'from_date' => '2025-01-01',
        'to_date' => '2025-12-31',
        'is_closed' => true,
    ]);
    $group = Account::query()->create([
        'doc_number' => 99901,
        'doc_num' => 'ACC-TB-GROUP',
        'company_id' => $context['company']->getKey(),
        'account_code' => '991-TB',
        'name' => 'Trial Balance Group',
        'account_type' => Account::TypeAsset,
        'statement_type' => Account::StatementFinancialPosition,
        'normal_balance' => Account::BalanceDebit,
        'level' => 1,
        'is_group' => true,
        'is_postable' => false,
        'status' => 'active',
    ]);
    $debitAccount = Account::query()->create([
        'doc_number' => 99902,
        'doc_num' => 'ACC-TB-DEBIT',
        'company_id' => $context['company']->getKey(),
        'account_code' => '991-TB-1',
        'name' => 'Trial Balance Debit',
        'parent_id' => $group->getKey(),
        'account_type' => Account::TypeAsset,
        'statement_type' => Account::StatementFinancialPosition,
        'normal_balance' => Account::BalanceDebit,
        'level' => 2,
        'is_group' => false,
        'is_postable' => true,
        'status' => 'active',
    ]);
    $creditAccount = Account::query()->create([
        'doc_number' => 99903,
        'doc_num' => 'ACC-TB-CREDIT',
        'company_id' => $context['company']->getKey(),
        'account_code' => '991-TB-2',
        'name' => 'Trial Balance Credit',
        'parent_id' => $group->getKey(),
        'account_type' => Account::TypeLiability,
        'statement_type' => Account::StatementFinancialPosition,
        'normal_balance' => Account::BalanceCredit,
        'level' => 2,
        'is_group' => false,
        'is_postable' => true,
        'status' => 'active',
    ]);
    $zeroAccount = Account::query()->create([
        'doc_number' => 99904,
        'doc_num' => 'ACC-TB-ZERO',
        'company_id' => $context['company']->getKey(),
        'account_code' => '992-TB',
        'name' => 'Trial Balance Zero',
        'account_type' => Account::TypeExpense,
        'statement_type' => Account::StatementIncomeStatement,
        'normal_balance' => Account::BalanceDebit,
        'level' => 1,
        'is_group' => false,
        'is_postable' => true,
        'status' => 'active',
    ]);

    $priorContext = $context;
    $priorContext['period'] = $priorPeriod;
    journalPostedMovement($priorContext, $debitAccount, $creditAccount, 99911, '2025-12-31', '100.0000', '0.0000');
    journalPostedMovement($context, $debitAccount, $creditAccount, 99912, '2026-03-01', '0.0000', '30.0000');
    journalPostedMovement($context, $debitAccount, $creditAccount, 99913, '2026-03-02', '30.0000', '0.0000');
    journalPostedMovement($context, $debitAccount, $creditAccount, 99914, '2026-03-03', '999.0000', '0.0000')
        ->update(['status' => JournalEntry::StatusDraft, 'is_posted' => false]);
    $debitAccount->update(['status' => 'inactive']);

    $filters = [
        'company_id' => $context['company']->getKey(),
        'from_date' => '2026-01-01',
        'to_date' => '2026-12-31',
        'branch_id' => null,
        'cost_center_id' => null,
        'include_zero' => false,
    ];
    $result = app(TrialBalanceQueryService::class)->report($filters);
    $rows = collect($result['rows'])->keyBy('doc_num');

    expect($result['is_balanced'])->toBeTrue()
        ->and($result['totals'])->toBe([
            'opening_debit' => '100.0000',
            'opening_credit' => '100.0000',
            'period_debit' => '60.0000',
            'period_credit' => '60.0000',
            'ending_debit' => '100.0000',
            'ending_credit' => '100.0000',
        ])
        ->and($rows->get('ACC-TB-DEBIT')['opening_debit'])->toBe('100.0000')
        ->and($rows->get('ACC-TB-DEBIT')['period_debit'])->toBe('30.0000')
        ->and($rows->get('ACC-TB-DEBIT')['period_credit'])->toBe('30.0000')
        ->and($rows->get('ACC-TB-DEBIT')['ending_debit'])->toBe('100.0000')
        ->and($rows->get('ACC-TB-DEBIT')['is_inactive'])->toBeTrue()
        ->and($rows->get('ACC-TB-GROUP')['period_debit'])->toBe('60.0000')
        ->and($rows->get('ACC-TB-GROUP')['period_credit'])->toBe('60.0000')
        ->and($rows->has($zeroAccount->doc_num))->toBeFalse();

    $withZero = app(TrialBalanceQueryService::class)->report([
        ...$filters,
        'include_zero' => true,
    ]);
    expect(collect($withZero['rows'])->pluck('doc_num'))->toContain($zeroAccount->doc_num);

    $query = [
        'run' => 1,
        'from_date' => '2026-01-01',
        'to_date' => '2026-12-31',
    ];

    $this->actingAs($actor)
        ->get(route('admin.accounting.reports.trial-balance', $query))
        ->assertOk()
        ->assertSee(__('trial_balance.title'))
        ->assertSee('Trial Balance Debit')
        ->assertSee(__('trial_balance.status.inactive'))
        ->assertDontSee('Trial Balance Zero');

    $this->actingAs($actor)
        ->get(route('admin.accounting.reports.trial-balance.export.csv', $query))
        ->assertOk()
        ->assertDownload('trial-balance.csv');

    $pdf = $this->actingAs($actor)
        ->get(route('admin.accounting.reports.trial-balance.export.pdf', $query))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    expect(strlen($pdf->getContent()))->toBeGreaterThan(1000);
});

test('financial period close transfers the result once and controlled reopen reverses it', function (): void {
    $context = journalEntryContext();
    $actor = journalEntryActor([
        'financial_periods.view',
        'financial_periods.edit',
        'financial_periods.close',
        'financial_periods.reopen',
        'journal_entries.view',
    ]);
    $revenue = Account::query()
        ->forCompany($context['company']->getKey())
        ->where('account_code', '411')
        ->firstOrFail();
    $expense = Account::query()
        ->forCompany($context['company']->getKey())
        ->where('account_code', '528')
        ->firstOrFail();
    $retainedEarnings = Account::query()
        ->forCompany($context['company']->getKey())
        ->where('account_code', '34')
        ->firstOrFail();
    $asset = Account::query()
        ->forCompany($context['company']->getKey())
        ->active()
        ->where('account_type', Account::TypeAsset)
        ->where('is_group', false)
        ->where('is_postable', true)
        ->firstOrFail();

    journalPostedMovement($context, $revenue, $asset, 99921, '2026-06-01', '0.0000', '244.0000');
    journalPostedMovement($context, $expense, $asset, 99922, '2026-06-02', '147.2000', '0.0000');
    $draft = journalPostedMovement($context, $expense, $asset, 99923, '2026-06-03', '10.0000', '0.0000');
    $draft->update(['status' => JournalEntry::StatusDraft, 'is_posted' => false]);

    $this->actingAs($actor)
        ->post(route('admin.financial-periods.close', $context['period']->doc_num))
        ->assertRedirect()
        ->assertSessionHasErrors('period_close');
    expect($context['period']->refresh()->is_closed)->toBeFalse();

    $draft->update(['status' => JournalEntry::StatusCancelled]);

    $this->actingAs($actor)
        ->post(route('admin.financial-periods.close', $context['period']->doc_num))
        ->assertRedirect(route('admin.financial-periods.show', $context['period']->doc_num))
        ->assertSessionHas('success');

    $closing = JournalEntry::query()
        ->with('lines')
        ->where('financial_period_id', $context['period']->getKey())
        ->where('source_type', 'period_closing')
        ->firstOrFail();
    $closingLines = $closing->lines->keyBy('account_id');

    expect($context['period']->refresh()->is_closed)->toBeTrue()
        ->and($closing->is_posted)->toBeTrue()
        ->and($closing->entry_date->toDateString())->toBe($context['period']->to_date->toDateString())
        ->and($closingLines->get($revenue->getKey())->debit_amount)->toBe('244.0000')
        ->and($closingLines->get($expense->getKey())->credit_amount)->toBe('147.2000')
        ->and($closingLines->get($retainedEarnings->getKey())->credit_amount)->toBe('96.8000');

    $this->actingAs($actor)
        ->post(route('admin.financial-periods.close', $context['period']->doc_num))
        ->assertRedirect();
    expect(JournalEntry::query()->where('source_type', 'period_closing')->count())->toBe(1);

    $this->actingAs($actor)
        ->putJson(route('admin.financial-periods.update', $context['period']->doc_num), [
            'name' => $context['period']->name,
            'from_date' => $context['period']->from_date->toDateString(),
            'to_date' => $context['period']->to_date->toDateString(),
            'is_closed' => false,
            'notes' => $context['period']->notes,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('is_closed');

    $this->actingAs($actor)
        ->post(route('admin.financial-periods.reopen', $context['period']->doc_num))
        ->assertRedirect(route('admin.financial-periods.show', $context['period']->doc_num));

    $closing->refresh();
    $reversal = JournalEntry::query()->findOrFail($closing->reversed_entry_id);
    expect($context['period']->refresh()->is_closed)->toBeFalse()
        ->and($reversal->source_type)->toBe('period_closing_reversal')
        ->and($reversal->is_posted)->toBeTrue();

    $this->actingAs($actor)
        ->post(route('admin.financial-periods.close', $context['period']->doc_num))
        ->assertRedirect(route('admin.financial-periods.show', $context['period']->doc_num));

    expect($context['period']->refresh()->is_closed)->toBeTrue()
        ->and(JournalEntry::query()
            ->where('financial_period_id', $context['period']->getKey())
            ->whereIn('source_type', ['period_closing', 'period_closing_2'])
            ->count())->toBe(2)
        ->and(JournalEntry::query()
            ->where('financial_period_id', $context['period']->getKey())
            ->where('source_type', 'period_closing_reversal')
            ->count())->toBe(1);
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
    $customerMovement = journalPostedMovement($context, $customerAccountA, $counterpart, 99211, '2026-02-10', '120.0000', '0.0000');
    $customerMovement->lines()->where('account_id', $customerAccountA->getKey())->update(['description' => 'Customer receivable']);
    journalPostedMovement($context, $customerAccountB, $counterpart, 99212, '2026-02-11', '870.0000', '0.0000');
    $actor->forceFill(['locale' => 'ar'])->save();
    app()->setLocale('ar');

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
        ->assertSee(__('ledger_reports.movement_descriptions.customer_receivable'))
        ->assertDontSee('Customer receivable')
        ->assertDontSee('JE-99212');
});

test('customer statement uses the shared report controls and exports pdf excel and csv', function (): void {
    app()->setLocale('en');
    $context = journalEntryContext();
    [, $counterpart] = journalEntryAccounts($context['company']);
    $actor = journalEntryActor(['journal_entries.view', 'reports.customer_statement.view', 'reports.customer_statement.export']);
    $actor->forceFill(['locale' => 'en'])->save();
    $customerAccount = Account::query()->create([
        'doc_number' => 99221,
        'doc_num' => 'ACC-99221',
        'company_id' => $context['company']->getKey(),
        'account_code' => 'CUST-99221',
        'name' => 'Export Customer Account',
        'account_type' => Account::TypeAsset,
        'statement_type' => Account::StatementFinancialPosition,
        'normal_balance' => Account::BalanceDebit,
        'is_group' => false,
        'is_postable' => true,
        'status' => 'active',
    ]);
    $customer = Customer::query()->create([
        'doc_number' => 99221,
        'doc_num' => 'CUS-99221',
        'company_id' => $context['company']->getKey(),
        'account_id' => $customerAccount->getKey(),
        'name' => 'Export Customer',
        'status' => 'active',
    ]);
    journalPostedMovement($context, $customerAccount, $counterpart, 99220, '2026-01-15', '75.0000', '0.0000');
    journalPostedMovement($context, $customerAccount, $counterpart, 99222, '2026-02-10', '320.0000', '0.0000');
    $dates = app(DateFormatService::class);
    $filters = [
        'run' => 1,
        'customer_doc_num' => $customer->doc_num,
        'from_date' => $dates->formatDate('2026-02-01'),
        'to_date' => $dates->formatDate('2026-02-28'),
    ];

    $page = $this->actingAs($actor)
        ->get(route('admin.accounting.reports.customer-statement', $filters))
        ->assertOk()
        ->assertSee('admin-report-page', false)
        ->assertSee('js-date-picker js-report-filter-control', false)
        ->assertSee('js-select2-ajax js-report-filter-control', false)
        ->assertSee('data-minimum-input-length="0"', false)
        ->assertSee(__('reports.export_pdf'))
        ->assertSee(__('reports.export_excel'))
        ->assertSee(__('reports.export_csv'))
        ->assertSee(__('ledger_reports.columns.balance'))
        ->assertSee(__('ledger_reports.messages.partner_posted_source_only'))
        ->assertSee(__('ledger_reports.summary.prior'))
        ->assertSee(__('ledger_reports.summary.prior_details'))
        ->assertSee('JE-99220')
        ->assertSee('75')
        ->assertDontSee('id="branch_doc_num"', false)
        ->assertDontSee('id="cost_center_doc_num"', false)
        ->assertDontSee('>Source type<', false)
        ->assertDontSee('>Cost center<', false)
        ->assertDontSee($customerAccount->codeNameLabel())
        ->assertDontSee('Customer Invoice, Payment and Credit History')
        ->assertDontSee('window.print()', false)
        ->assertDontSee('ledger-reports.js', false);

    expect($page->getContent())->toContain('data-url="'.route('admin.accounting.journal-entries.select2.customers').'"');

    $this->get(route('admin.accounting.reports.customer-statement.export.excel', $filters))
        ->assertOk()
        ->assertDownload('customer-statement.xlsx');
    $this->get(route('admin.accounting.reports.customer-statement.export.csv', $filters))
        ->assertOk()
        ->assertDownload('customer-statement.csv');
    $pdf = $this->get(route('admin.accounting.reports.customer-statement.export.pdf', $filters))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    $pdfText = accountingPdfText($pdf->getContent());

    expect(strlen($pdf->getContent()))->toBeGreaterThan(1000)
        ->and($pdfText)->toContain('Customer Statement')
        ->and($pdfText)->toContain('Balance')
        ->and($pdfText)->toContain('Balance before period')
        ->and($pdfText)->toContain('JE-99220')
        ->and($pdfText)->not->toContain('Source type')
        ->and($pdfText)->not->toContain('Cost center')
        ->and($pdfText)->not->toContain('Export Customer Account')
        ->and($pdfText)->not->toContain('Customer Invoice, Payment and Credit History');
});

test('supplier statement uses the shared party layout and includes the prior balance detail', function (): void {
    app()->setLocale('en');
    $context = journalEntryContext();
    [, $counterpart] = journalEntryAccounts($context['company']);
    $actor = journalEntryActor(['journal_entries.view', 'reports.supplier_statement.view', 'reports.supplier_statement.export']);
    $actor->forceFill(['locale' => 'en'])->save();
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
    journalPostedMovement($context, $supplierAccountA, $counterpart, 99310, '2026-02-10', '0.0000', '80.0000');
    $localizedSupplierMovement = journalPostedMovement($context, $supplierAccountA, $counterpart, 99311, '2026-03-10', '0.0000', '250.0000');
    $localizedSupplierMovement->lines()
        ->where('account_id', $supplierAccountA->getKey())
        ->update(['description' => 'مستحقات المورد عن فاتورة مشتريات']);
    journalPostedMovement($context, $supplierAccountB, $counterpart, 99312, '2026-03-11', '0.0000', '910.0000');

    $filters = [
        'run' => 1,
        'supplier_doc_num' => $supplierA->doc_num,
        'from_date' => '2026-03-01',
        'to_date' => '2026-03-31',
    ];
    $page = $this->actingAs($actor)
        ->get(route('admin.accounting.reports.supplier-statement', $filters))
        ->assertOk()
        ->assertSee('Supplier A')
        ->assertSee('js-select2-ajax js-report-filter-control', false)
        ->assertSee(__('ledger_reports.messages.partner_posted_source_only'))
        ->assertSee(__('ledger_reports.summary.prior'))
        ->assertSee(__('ledger_reports.summary.prior_details'))
        ->assertSee('JE-99310')
        ->assertSee('JE-99311')
        ->assertSee(__('ledger_reports.movement_descriptions.supplier_payable'))
        ->assertDontSee('مستحقات المورد عن فاتورة مشتريات')
        ->assertDontSee('JE-99312')
        ->assertDontSee('910.0000')
        ->assertDontSee('id="branch_doc_num"', false)
        ->assertDontSee('id="cost_center_doc_num"', false)
        ->assertDontSee($supplierAccountA->codeNameLabel());

    expect($page->getContent())->toContain('data-url="'.route('admin.accounting.journal-entries.select2.suppliers').'"');

    $this->get(route('admin.accounting.reports.supplier-statement.export.excel', $filters))
        ->assertOk()
        ->assertDownload('supplier-statement.xlsx');
    $this->get(route('admin.accounting.reports.supplier-statement.export.csv', $filters))
        ->assertOk()
        ->assertDownload('supplier-statement.csv');
    $pdf = $this->get(route('admin.accounting.reports.supplier-statement.export.pdf', $filters))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    expect(accountingPdfText($pdf->getContent()))
        ->toContain('Supplier Statement')
        ->toContain('Balance before period')
        ->toContain('JE-99310')
        ->toContain('Purchase invoice payable')
        ->not->toContain('مستحقات المورد عن فاتورة مشتريات')
        ->not->toContain('Supplier A Account');

    $actor->forceFill(['locale' => 'ar'])->save();
    app()->setLocale('ar');
    $arabicPdf = $this->get(route('admin.accounting.reports.supplier-statement.export.pdf', $filters))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
    $arabicPdfText = accountingPdfText($arabicPdf->getContent());

    if ($directory = getenv('LEDGER_PRINT_SAMPLES')) {
        file_put_contents($directory.'/supplier-statement-en.pdf', $pdf->getContent());
        file_put_contents($directory.'/supplier-statement-ar.pdf', $arabicPdf->getContent());
    }

    expect($arabicPdfText)
        ->toContain('SUP-99301')
        ->not->toContain('AM')
        ->not->toContain('PM');
});
