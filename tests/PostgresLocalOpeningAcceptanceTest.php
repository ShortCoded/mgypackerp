<?php

use App\Models\User;
use App\Services\PostingAccountConfigurationAudit;
use App\Services\PostingAccountResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\AccountClassification;
use Modules\Accounting\Services\AccountService;
use Modules\Accounting\Services\ReconciliationCenterService;
use Modules\Core\Models\Branch;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Company;
use Modules\Core\Models\Currency;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Models\Product;
use Modules\Core\Services\DateFormatService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\OperationalNotificationService;
use Modules\Finance\Models\OpeningBalance;
use Modules\Finance\Services\OpeningBalanceApprovalService;
use Modules\Finance\Services\OpeningBalanceService;
use Modules\Finance\Services\OpeningInventoryValuationService;
use Modules\Inventory\Models\OpeningStock;
use Modules\Inventory\Services\InventoryGlReconciliationService;
use Modules\Inventory\Services\OpeningStockPricingService;
use Modules\Inventory\Services\OpeningStockService;
use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class);

const LocalOpeningAcceptanceDatabase = 'mgypack_legacy_repair_candidate_20261004';
const LocalOpeningAcceptanceManifest = '/tmp/mgypack-local-opening-acceptance-20261004.json';
const LocalOpeningAcceptanceBackup = '/tmp/mgypack-local-opening-before-20261004.dump';

beforeEach(function (): void {
    if (getenv('MGYPACK_LOCAL_OPENING_ACCEPTANCE') !== '1') {
        $this->markTestSkipped('Explicit guarded LOCAL DEV acceptance only.');
    }
    $identity = DB::selectOne('select current_database() as db, host(inet_server_addr()) as host, inet_server_port() as port');
    expect(DB::getDriverName())->toBe('pgsql')->and($identity->db)->toBe(LocalOpeningAcceptanceDatabase)
        ->and($identity->host)->toBe('127.0.0.1')->and($identity->port)->toBe(5432)
        ->and(app()->environment())->toBe('testing')->and(DB::transactionLevel())->toBe(0);
    config(['mail.default' => 'array', 'broadcasting.default' => 'null']);
    Mail::fake();
    Notification::fake();
    Queue::fake();
    Http::preventStrayRequests();
    Http::fake();
    app()->instance(OperationalNotificationService::class, Mockery::mock(OperationalNotificationService::class)
        ->shouldReceive('send')->zeroOrMoreTimes()->andReturnNull()->getMock());
});

function localOpeningOriginalRows(): array
{
    $result = [];
    foreach (['accounts', 'inventory_opening_stocks', 'inventory_opening_stock_lines', 'inventory_opening_stock_pricings',
        'inventory_opening_stock_pricing_lines', 'inventory_documents', 'inventory_document_lines', 'inventory_transactions',
        'journal_entries', 'journal_entry_lines', 'sales_orders', 'sales_order_lines', 'customer_invoices', 'customer_invoice_lines',
        'customer_invoice_deliveries', 'inventory_layer_allocations', 'inventory_receipt_layers'] as $table) {
        $result[$table] = DB::table($table)->orderBy('id')->get()->mapWithKeys(fn ($row): array => [
            $row->id => hash('sha256', json_encode($row, JSON_THROW_ON_ERROR)),
        ])->all();
    }

    return $result;
}

function localOpeningSaveManifest(array $data): void
{
    file_put_contents(LocalOpeningAcceptanceManifest, json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    chmod(LocalOpeningAcceptanceManifest, 0600);
}

test('local opening preflight takes a recoverable backup before any rehearsal data write', function (): void {
    expect(file_exists(LocalOpeningAcceptanceBackup))->toBeFalse()
        ->and(file_exists(LocalOpeningAcceptanceManifest))->toBeFalse();
    $config = config('database.connections.pgsql');
    (new Process(['pg_dump', '--no-password', '--host=127.0.0.1', '--port=5432', '--username='.$config['username'],
        '--format=custom', '--file='.LocalOpeningAcceptanceBackup, LocalOpeningAcceptanceDatabase],
        env: ['PGPASSWORD' => $config['password']]))->setTimeout(90)->mustRun();
    chmod(LocalOpeningAcceptanceBackup, 0600);
    $manifest = [
        'stage' => 'backed_up', 'database' => LocalOpeningAcceptanceDatabase, 'host' => '127.0.0.1', 'port' => 5432,
        'app_environment' => app()->environment(), 'backup' => LocalOpeningAcceptanceBackup,
        'backup_sha256' => hash_file('sha256', LocalOpeningAcceptanceBackup), 'original_rows' => localOpeningOriginalRows(),
        'outbound' => ['mail' => 'fake/array', 'notifications' => 'fake', 'operational_notifications' => 'mocked void send',
            'queues' => 'fake', 'http' => 'fake with stray-request prevention', 'broadcasting' => 'null'],
        'local_mutations' => [], 'customer_financial_approval' => false, 'customer_uat' => false, 'live_release' => false,
        'opening_sources' => DB::table('inventory_transactions as t')->join('products as p', 'p.id', '=', 't.product_id')
            ->where('t.company_id', 1)->where('t.financial_period_id', 1)->where('t.transaction_type', 'opening_stock')
            ->selectRaw('t.branch_id, p.item_classification, sum(t.total_cost) as valued_opening, count(*) as lines, sum(case when t.total_cost is null then 1 else 0 end) as unpriced')
            ->groupBy('t.branch_id', 'p.item_classification')->orderBy('t.branch_id')->get()->toArray(),
    ];
    localOpeningSaveManifest($manifest);
    expect(filesize(LocalOpeningAcceptanceBackup))->toBeGreaterThan(1000)
        ->and(localOpeningOriginalRows())->toBe($manifest['original_rows']);
    Http::assertNothingSent();
    Mail::assertNothingOutgoing();
    Notification::assertNothingSent();
    Queue::assertNothingPushed();
});

test('local opening migration rehearses restore up down reapply and repeat without changing original rows', function (): void {
    $manifest = json_decode(file_get_contents(LocalOpeningAcceptanceManifest), true, flags: JSON_THROW_ON_ERROR);
    expect($manifest['stage'])->toBe('backed_up')->and(hash_file('sha256', LocalOpeningAcceptanceBackup))->toBe($manifest['backup_sha256']);
    $recovery = 'mgypack_local_opening_recovery_20261004';
    expect(DB::table('pg_database')->where('datname', $recovery)->exists())->toBeFalse();
    DB::statement('CREATE DATABASE '.$recovery);
    $config = config('database.connections.pgsql');
    (new Process(['pg_restore', '--no-password', '--host=127.0.0.1', '--port=5432', '--username='.$config['username'],
        '--dbname='.$recovery, '--no-owner', '--no-privileges', LocalOpeningAcceptanceBackup], env: ['PGPASSWORD' => $config['password']]))
        ->setTimeout(90)->mustRun();
    config(['database.connections.local_opening_recovery' => [...$config, 'database' => $recovery]]);
    $connection = DB::connection('local_opening_recovery');
    expect($connection->selectOne('select current_database() as db')->db)->toBe($recovery);
    foreach ($manifest['original_rows'] as $table => $rows) {
        foreach ($connection->table($table)->orderBy('id')->get() as $row) {
            expect(hash('sha256', json_encode($row, JSON_THROW_ON_ERROR)))->toBe($rows[$row->id]);
        }
    }
    $path = 'database/migrations/2026_10_04_054413_add_inventory_valuation_snapshot_to_opening_balances.php';
    $this->artisan('migrate', ['--database' => 'local_opening_recovery', '--path' => $path, '--force' => true])->assertSuccessful();
    expect($connection->getSchemaBuilder()->hasColumn('opening_balances', 'inventory_valuation_snapshot'))->toBeTrue();
    $this->artisan('migrate:rollback', ['--database' => 'local_opening_recovery', '--path' => $path, '--step' => 1, '--force' => true])->assertSuccessful();
    expect($connection->getSchemaBuilder()->hasColumn('opening_balances', 'inventory_valuation_snapshot'))->toBeFalse();
    $this->artisan('migrate', ['--database' => 'local_opening_recovery', '--path' => $path, '--force' => true])->assertSuccessful();
    $this->artisan('migrate', ['--path' => $path, '--force' => true])->assertSuccessful();
    $this->artisan('migrate', ['--path' => $path, '--force' => true])->assertSuccessful();
    expect(localOpeningOriginalRows())->toBe($manifest['original_rows']);
    $manifest['migration'] = ['path' => $path, 'source_sha256' => hash_file('sha256', $path),
        'recovery_database' => $recovery, 'up_down_reapply' => 'PASS', 'candidate_repeat' => 'PASS', 'original_rows' => 'exact unchanged'];
    $manifest['stage'] = 'migrated';
    localOpeningSaveManifest($manifest);
    Http::assertNothingSent();
    Mail::assertNothingOutgoing();
    Notification::assertNothingSent();
    Queue::assertNothingPushed();
});

function localOpeningSetContext(object $test, int $branchId): array
{
    $company = Company::findOrFail(1);
    $period = FinancialPeriod::where('company_id', 1)->findOrFail(1);
    $branch = Branch::where('company_id', 1)->findOrFail($branchId);
    $actor = User::findOrFail(1);
    $context = [OperatingContextService::CompanyIdKey => $company->id,
        OperatingContextService::CompanyDocNumKey => $company->doc_num,
        OperatingContextService::BranchIdKey => $branch->id,
        OperatingContextService::BranchDocNumKey => $branch->doc_num,
        OperatingContextService::FinancialPeriodIdKey => $period->id,
        OperatingContextService::FinancialPeriodDocNumKey => $period->doc_num];
    $test->actingAs($actor)->withSession($context);
    request()->setLaravelSession(app('session.store'));
    request()->setUserResolver(fn () => $actor);

    return compact('company', 'period', 'branch', 'actor', 'context');
}

function localOpeningAssertOriginalRows(array $manifest): void
{
    $current = localOpeningOriginalRows();
    foreach ($manifest['original_rows'] as $table => $rows) {
        foreach ($rows as $id => $hash) {
            expect($current[$table][$id] ?? null)->toBe($hash);
        }
    }
}

test('local opening records provisional counterpart choices and posts documented original values through the real approval path', function (): void {
    $manifest = json_decode(file_get_contents(LocalOpeningAcceptanceManifest), true, flags: JSON_THROW_ON_ERROR);
    expect($manifest['stage'])->toBe('migrated');
    localOpeningAssertOriginalRows($manifest);
    DB::transaction(function () use (&$manifest): void {
        $f = localOpeningSetContext($this, 1);
        $resolver = app(PostingAccountResolver::class);
        $rowsBefore = app(InventoryGlReconciliationService::class)->reconcile(1, 1);
        $currency = Currency::where('company_id', 1)->where('is_main', true)->firstOrFail();
        $parent = Account::where('company_id', 1)->where('account_code', '3')->firstOrFail();
        $classification = AccountClassification::where('code', 'capital')->firstOrFail();
        $counterpart = Account::create(['company_id' => 1, 'doc_number' => 900001,
            'doc_num' => 'Account-900001', 'account_code' => '399990', 'name' => 'LOCAL DEV — مقابل افتتاح مخزون تجريبي غير معتمد للعميل',
            'name_en' => 'LOCAL DEV provisional inventory opening counterpart — not customer approved',
            'parent_id' => $parent->id, 'level' => $parent->level + 1, 'account_classification_id' => $classification->id,
            'account_type' => Account::TypeEquity,
            'statement_type' => Account::StatementFinancialPosition,
            'normal_balance' => 'credit', 'is_group' => false, 'is_postable' => true, 'is_system' => false, 'status' => 'active',
            'notes' => 'SYNTHETIC LOCAL DEV acceptance choice. No assertion about customer approved capital, opening date or financial values.']);
        $manifest['local_mutations'][] = ['operation' => 'create provisional counterpart', 'table' => 'accounts',
            'id' => $counterpart->id, 'company_id' => 1, 'code' => $counterpart->account_code, 'doc_num' => $counterpart->doc_num,
            'name' => $counterpart->name, 'classification' => $classification->code, 'customer_approved' => false];
        foreach ([PostingAccountResolver::AbnormalWasteLoss, PostingAccountResolver::WarehouseDamageLoss] as $code) {
            $loss = $resolver->resolve(1, $code, 'LOCAL DEV acceptance')->refresh();
            expect((int) $loss->company_id)->toBe(1);
            expect($loss->is_postable)->toBeTrue()->and($loss->status)->toBe('active');
            $manifest['provisional_loss_choices'][] = ['id' => $loss->id, 'company_id' => 1, 'classification' => $code,
                'account_code' => $loss->account_code, 'doc_num' => $loss->doc_num, 'name' => $loss->name,
                'mutation' => 'select existing canonical loss account provisionally for LOCAL DEV only; no original account changed; customer financial approval remains absent'];
        }
        foreach ([1, 3] as $branchId) {
            $f = localOpeningSetContext($this, $branchId);
            foreach ([PostingAccountResolver::RawMaterialInventory, PostingAccountResolver::PackagingMaterialInventory,
                PostingAccountResolver::FinishedGoodsInventory] as $code) {
                $account = $resolver->resolve(1, $code, 'LOCAL DEV opening');
                $preview = $this->getJson(route('admin.finance.opening-balances.inventory-valuation', ['account_doc_num' => $account->doc_num]))->assertOk()->json('data');
                expect($preview['can_post'])->toBeTrue();
                $response = $this->postJson(route('admin.finance.opening-balances.store'), [
                    'document_date' => $f['period']->from_date->format(app(DateFormatService::class)->dateFormat()),
                    'currency_doc_num' => $currency->doc_num, 'exchange_rate' => '1',
                    'description' => 'SYNTHETIC LOCAL DEV — existing priced opening stock valuation with provisional counterpart; customer approval absent',
                    'inventory_source_fingerprint' => $preview['source_fingerprint'],
                    'lines' => [['account_doc_num' => $account->doc_num, 'transaction_type' => 'credit', 'amount' => '1'],
                        ['account_doc_num' => $counterpart->doc_num, 'transaction_type' => 'debit', 'amount' => '999']]])->assertOk();
                $record = OpeningBalance::where('company_id', 1)->where('doc_num', $response->json('data.doc_num'))->firstOrFail();
                $this->postJson(route('admin.finance.opening-balances.approve', $record->doc_num))->assertOk();
                $record->refresh();
                expect($record->approved)->toBeTrue()->and($record->inventory_valuation_snapshot['amount'])->toBe($preview['amount']);
                $manifest['local_mutations'][] = ['operation' => 'canonical provisional LOCAL DEV opening journal',
                    'opening_balance_id' => $record->id, 'opening_balance_doc_num' => $record->doc_num,
                    'journal_entry_id' => $record->journal_entry_id, 'journal_doc_num' => $record->journalEntry->doc_num,
                    'company_id' => 1, 'period_id' => 1, 'branch_id' => $branchId, 'account_id' => $account->id,
                    'account_code' => $account->account_code, 'account_name' => $account->name, 'counterpart_id' => $counterpart->id,
                    'amount' => $preview['amount'], 'source_count' => $preview['source_count'], 'customer_approved' => false];
                $this->getJson(route('admin.finance.opening-balances.inventory-valuation', ['account_doc_num' => $account->doc_num]))
                    ->assertOk()->assertJsonPath('data.can_post', false);
            }
        }
        $after = app(InventoryGlReconciliationService::class)->reconcile(1, 1);
        $manifest['gl_reconciliation'] = ['before' => $rowsBefore, 'after' => $after];
        foreach ($after as $row) {
            expect(bccomp((string) $row['difference'], '0', 4))->toBe(0);
        }
        localOpeningAssertOriginalRows($manifest);
        $manifest['stage'] = 'local_configured_and_openings_posted';
    });
    localOpeningSaveManifest($manifest);
    Http::assertNothingSent();
    Mail::assertNothingOutgoing();
    Notification::assertNothingSent();
    Queue::assertNothingPushed();
});

test('local opening verifies exact branch totals configured losses duplicate rejection and original history preservation', function (): void {
    $manifest = json_decode(file_get_contents(LocalOpeningAcceptanceManifest), true, flags: JSON_THROW_ON_ERROR);
    expect($manifest['stage'])->toBe('local_configured_and_openings_posted');
    localOpeningAssertOriginalRows($manifest);
    $resolver = app(PostingAccountResolver::class);
    $lossChoices = [];
    foreach ([PostingAccountResolver::AbnormalWasteLoss, PostingAccountResolver::WarehouseDamageLoss] as $code) {
        $account = $resolver->resolve(1, $code, 'LOCAL DEV final verification')->refresh();
        $balance = DB::table('journal_entry_lines')->where('account_id', $account->id)->sum(DB::raw('debit_amount - credit_amount'));
        expect(bccomp((string) $balance, '0', 4))->toBe(0);
        $lossChoices[] = ['id' => $account->id, 'company_id' => 1, 'classification' => $code,
            'account_code' => $account->account_code, 'doc_num' => $account->doc_num, 'name' => $account->name,
            'existing_journal_lines' => DB::table('journal_entry_lines')->where('account_id', $account->id)->count(),
            'balance' => (string) $balance, 'customer_approved' => false, 'mutation' => 'none; existing canonical LOCAL DEV choice'];
    }
    $entries = collect($manifest['local_mutations'])->where('operation', 'canonical provisional LOCAL DEV opening journal');
    $counterpartId = (int) $entries->first()['counterpart_id'];
    $branchProof = [];
    foreach ([1 => '7026258.4000', 3 => '2993882.6400'] as $branchId => $expected) {
        $sum = $entries->where('branch_id', $branchId)->reduce(fn (string $sum, array $entry): string => bcadd($sum, $entry['amount'], 4), '0.0000');
        expect($sum)->toBe($expected);
        $rows = app(InventoryGlReconciliationService::class)->reconcile(1, 1, $branchId);
        foreach ($rows as $row) {
            expect($row['difference'])->toBe('0.0000');
        }
        $branchProof[$branchId] = ['opening_amount' => $sum, 'reconciliation' => $rows];
        localOpeningSetContext($this, $branchId);
        foreach ($entries->where('branch_id', $branchId) as $entry) {
            $record = OpeningBalance::findOrFail($entry['opening_balance_id']);
            $before = DB::table('journal_entries')->count();
            $duplicate = $this->postJson(route('admin.finance.opening-balances.approve', $record->doc_num));
            expect($duplicate->getStatusCode())->toBe(422, $duplicate->getContent());
            expect(DB::table('journal_entries')->count())->toBe($before);
            $debit = DB::table('journal_entry_lines')->where('journal_entry_id', $record->journal_entry_id)->sum('debit_amount');
            $credit = DB::table('journal_entry_lines')->where('journal_entry_id', $record->journal_entry_id)->sum('credit_amount');
            expect(bccomp((string) $debit, $entry['amount'], 4))->toBe(0)->and(bccomp((string) $credit, $entry['amount'], 4))->toBe(0);
        }
    }
    $balance = DB::table('journal_entry_lines')->where('account_id', $counterpartId)->sum('credit_amount');
    expect(bccomp((string) $balance, '10020141.0400', 4))->toBe(0);
    $manifest['provisional_loss_choices'] = $lossChoices;
    $manifest['branch_reconciliation'] = $branchProof;
    $manifest['scope'] = ['company_id' => 1, 'financial_period_id' => 1, 'branches' => [1, 3], 'currency' => 'EGP',
        'exchange_rate' => '1.000000', 'date' => FinancialPeriod::findOrFail(1)->from_date->toDateString(),
        'date_basis' => 'LOCAL DEV period start; not customer approved opening date', 'counterpart_credit' => '10020141.0400'];
    $manifest['failed_preliminary_attempts'] = ['all rolled back by DB transaction; original-row hashes verified before successful attempt'];
    $manifest['duplicates'] = 'all six HTTP repeated approvals rejected; exact journal count unchanged';
    $manifest['stage'] = 'accounting_reconciled';
    localOpeningSaveManifest($manifest);
    Http::assertNothingSent();
    Mail::assertNothingOutgoing();
    Notification::assertNothingSent();
    Queue::assertNothingPushed();
});

test('local opening proves failed transaction sequence gaps contain no orphan account or duplicate journal', function (): void {
    $manifest = json_decode(file_get_contents(LocalOpeningAcceptanceManifest), true, flags: JSON_THROW_ON_ERROR);
    expect($manifest['stage'])->toBe('accounting_reconciled');
    $matches = DB::table('accounts')->where(fn ($query) => $query->where('doc_num', 'Account-900001')->orWhere('account_code', '399990')
        ->orWhere('name', 'like', 'LOCAL DEV — مقابل افتتاح مخزون%'))->get(['id', 'company_id', 'account_code', 'doc_num', 'name']);
    expect($matches)->toHaveCount(1)->and((int) $matches->first()->id)->toBe(698)
        ->and(DB::table('accounts')->whereBetween('id', [695, 697])->count())->toBe(0);
    $createdJournals = collect($manifest['local_mutations'])->where('operation', 'canonical provisional LOCAL DEV opening journal')->pluck('journal_entry_id');
    expect(DB::table('journal_entries')->whereIn('id', $createdJournals)->count())->toBe(6)
        ->and(DB::table('opening_balances')->where('description', 'like', 'SYNTHETIC LOCAL DEV — existing priced opening%')->count())->toBe(6);
    localOpeningAssertOriginalRows($manifest);
    $manifest['failed_attempt_readback'] = ['matching_provisional_accounts' => $matches->toArray(),
        'ids_695_697_row_count' => 0, 'original_max_account_id' => max(array_keys($manifest['original_rows']['accounts'])),
        'explanation' => 'PostgreSQL sequences are not rolled back. Failed wrapped transactions consumed IDs695–697 but left no rows; sole successful counterpart is698.',
        'original_accounts_and_financial_rows' => 'all original row hashes preserved', 'canonical_opening_balances' => 6, 'canonical_journals' => 6];
    localOpeningSaveManifest($manifest);
});

test('local opening inspects the exact structural configuration of loss choices', function (): void {
    $manifest = json_decode(file_get_contents(LocalOpeningAcceptanceManifest), true, flags: JSON_THROW_ON_ERROR);
    foreach ($manifest['provisional_loss_choices'] as &$choice) {
        $account = Account::findOrFail($choice['id']);
        $choice['structure'] = $account->only(['account_type', 'statement_type', 'normal_balance', 'is_group', 'is_postable', 'status', 'parent_id']);
    }
    unset($choice);
    localOpeningSaveManifest($manifest);
    fwrite(STDOUT, json_encode($manifest['provisional_loss_choices'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE).PHP_EOL);
});

test('local opening prepares explicitly synthetic UI and concurrency inputs through opening approval and pricing', function (): void {
    $manifest = json_decode(file_get_contents(LocalOpeningAcceptanceManifest), true, flags: JSON_THROW_ON_ERROR);
    expect($manifest['stage'])->toBe('accounting_reconciled')->and($manifest['failed_attempt_readback']['ids_695_697_row_count'])->toBe(0);
    DB::transaction(function () use (&$manifest): void {
        $branch = Branch::create(['company_id' => 1, 'doc_number' => 900001,
            'doc_num' => 'SYNTHETIC-OPENING-BRANCH-900001', 'name' => 'SYNTHETIC LOCAL DEV opening UI acceptance',
            'type' => Branch::TypeWarehouse, 'status' => 'active']);
        $store = BranchStore::create(['branch_id' => $branch->id, 'name' => 'SYNTHETIC opening UI store', 'position' => 1]);
        $unit = ItemUnit::where('company_id', 1)->firstOrFail();
        $product = Product::create(['company_id' => 1, 'doc_number' => 900001, 'doc_num' => 'SYNTHETIC-OPENING-RAW-900001',
            'name' => 'SYNTHETIC local opening acceptance material', 'item_classification' => Product::ClassificationRawMaterial,
            'item_unit_id' => $unit->id, 'status' => 'active']);
        $f = localOpeningSetContext($this, $branch->id);
        $source = app(OpeningStockService::class)->create([
            'document_date' => $f['period']->from_date->toDateString(), 'branch_store_uuid' => $store->public_uuid,
            'notes' => 'SYNTHETIC LOCAL DEV UI/concurrency acceptance input, not customer history',
            'lines' => [['product_doc_num' => $product->doc_num, 'quantity' => '10', 'stock_status' => 'available']]])['record'];
        app(OpeningStockService::class)->approve($source);
        $line = $source->lines()->firstOrFail();
        $currency = Currency::where('company_id', 1)->where('is_main', true)->firstOrFail();
        $pricing = app(OpeningStockPricingService::class)->create([
            'document_date' => $f['period']->from_date->toDateString(), 'opening_stock_doc_num' => $source->doc_num,
            'currency_doc_num' => $currency->doc_num, 'exchange_rate' => '1', 'pricing_basis' => 'documented',
            'source_reference' => 'SYNTHETIC LOCAL DEV acceptance unit price1.75',
            'lines' => [['opening_stock_line_public_id' => $line->public_id, 'unit_price' => '1.75000000']]], request())['record'];
        $account = app(PostingAccountResolver::class)->resolve(1, PostingAccountResolver::RawMaterialInventory, 'SYNTHETIC UI');
        $preview = app(OpeningInventoryValuationService::class)->preview(1, 1, $branch->id, $account->doc_num);
        expect($preview['amount'])->toBe('17.5000')->and($preview['can_post'])->toBeTrue();
        $actor = User::factory()->create(['doc_number' => (int) User::withTrashed()->max('doc_number') + 1,
            'doc_num' => 'SYNTHETIC-OPENING-USER-900001', 'username' => 'synthetic-opening-20261004',
            'email' => 'synthetic-opening-20261004@example.test', 'name' => 'SYNTHETIC LOCAL DEV opening acceptance',
            'password' => Hash::make('SyntheticOpeningDev2026!')]);
        $actor->assignRole($f['actor']->roles->pluck('name')->all());
        $manifest['ui_fixture'] = ['synthetic' => true, 'user_id' => $actor->id, 'username' => $actor->username,
            'branch_id' => $branch->id, 'branch_doc_num' => $branch->doc_num, 'store_id' => $store->id,
            'product_id' => $product->id, 'source_id' => $source->id, 'source_doc_num' => $source->doc_num,
            'pricing_id' => $pricing->id, 'pricing_doc_num' => $pricing->doc_num, 'account_doc_num' => $account->doc_num,
            'counterpart_doc_num' => 'Account-900001', 'currency_doc_num' => $currency->doc_num, 'amount' => '17.5000',
            'company_doc_num' => $f['company']->doc_num, 'period_doc_num' => $f['period']->doc_num];
        $manifest['local_mutations'][] = ['operation' => 'explicitly synthetic UI/concurrency fixture', 'identifiers' => $manifest['ui_fixture'], 'customer_history' => false];
        localOpeningAssertOriginalRows($manifest);
    });
    $manifest['stage'] = 'synthetic_ui_fixture_prepared';
    localOpeningSaveManifest($manifest);
    Http::assertNothingSent();
    Mail::assertNothingOutgoing();
    Notification::assertNothingSent();
    Queue::assertNothingPushed();
});

test('local opening configures a proper provisional adjustment loss account without changing any financial history', function (): void {
    $manifest = json_decode(file_get_contents(LocalOpeningAcceptanceManifest), true, flags: JSON_THROW_ON_ERROR);
    expect($manifest['stage'])->toBe('synthetic_ui_fixture_prepared');
    localOpeningSetContext($this, 1);
    localOpeningAssertOriginalRows($manifest);
    DB::transaction(function () use (&$manifest): void {
        $resolver = app(PostingAccountResolver::class);
        $legacy = Account::findOrFail(669);
        expect($legacy->account_type)->toBe('asset')->and($legacy->statement_type)->toBe('financial_position');
        expect(fn () => $resolver->resolve(1, PostingAccountResolver::InventoryAdjustmentLoss, 'LOCAL DEV adjustment configuration'))
            ->toThrow(DomainException::class);
        $before = (array) DB::table('accounts')->where('id', $legacy->id)->first();
        $parent = Account::where('company_id', 1)->where('account_code', '5')->firstOrFail();
        $loss = app(AccountService::class)->create(['account_code' => '', 'parent_doc_num' => $parent->doc_num,
            'name' => 'SYNTHETIC LOCAL DEV خسائر تسويات المخزون', 'name_en' => 'SYNTHETIC LOCAL DEV inventory adjustment loss',
            'classification_code' => PostingAccountResolver::InventoryAdjustmentLoss,
            'is_group' => false, 'is_postable' => true, 'normal_balance' => 'debit', 'status' => 'active',
            'notes' => 'SYNTHETIC LOCAL DEV interim posting account only; not customer-approved accounting configuration.']);
        expect($resolver->resolve(1, PostingAccountResolver::InventoryAdjustmentLoss, 'verify local selection')->id)->toBe($loss->id)
            ->and($loss->account_type)->toBe('expense')->and($loss->statement_type)->toBe('income_statement');
        $manifest['local_mutations'][] = ['operation' => 'provisional LOCAL DEV loss configuration through AccountService',
            'preserved_incompatible_account_id' => $legacy->id, 'new_account' => $loss->only(['id', 'company_id', 'doc_num', 'account_code',
                'name', 'account_type', 'statement_type', 'normal_balance', 'is_group', 'is_postable', 'status', 'parent_id']),
            'classification' => PostingAccountResolver::InventoryAdjustmentLoss,
            'preexisting_journal_lines' => 0, 'customer_approved' => false];
        $manifest['provisional_loss_choices'] = [];
        foreach ([PostingAccountResolver::AbnormalWasteLoss, PostingAccountResolver::WarehouseDamageLoss,
            PostingAccountResolver::InventoryAdjustmentLoss] as $code) {
            $selected = $resolver->resolve(1, $code, 'LOCAL DEV loss choice')->refresh();
            expect($selected->account_type)->toBe('expense')->and($selected->statement_type)->toBe('income_statement')
                ->and($selected->is_postable)->toBeTrue()->and($selected->is_group)->toBeFalse()
                ->and(DB::table('journal_entry_lines')->where('account_id', $selected->id)->count())->toBe(0);
            $manifest['provisional_loss_choices'][] = ['classification' => $code,
                ...$selected->only(['id', 'company_id', 'doc_num', 'account_code', 'name', 'account_type', 'statement_type', 'normal_balance', 'parent_id']),
                'preexisting_journal_lines' => 0, 'preexisting_balance' => '0.0000', 'customer_approved' => false];
        }
        expect((array) DB::table('accounts')->where('id', $legacy->id)->first())->toBe($before);
        localOpeningAssertOriginalRows($manifest);
    });
    localOpeningSaveManifest($manifest);
    Http::assertNothingSent();
    Mail::assertNothingOutgoing();
    Notification::assertNothingSent();
    Queue::assertNothingPushed();
});

test('local opening verifies actual browser created draft and prospective account diagnostics', function (): void {
    $manifest = json_decode(file_get_contents(LocalOpeningAcceptanceManifest), true, flags: JSON_THROW_ON_ERROR);
    $fixture = $manifest['ui_fixture'];
    localOpeningSetContext($this, $fixture['branch_id']);
    $draft = OpeningBalance::where('company_id', 1)
        ->where('description', 'SYNTHETIC LOCAL DEV browser opening acceptance — not customer history')->sole();
    expect($draft->inventory_valuation_snapshot['amount'])->toBe('17.5000')->and($draft->is_closed)->toBeTrue()
        ->and($draft->approved)->toBeFalse()->and($draft->journal_entry_id)->toBeNull();
    expect($draft->lines()->orderBy('line_no')->get()->map(fn ($line) => bcadd((string) $line->debit_amount, (string) $line->credit_amount, 4))->all())->toBe(['17.5000', '17.5000']);
    $manifest['browser'] = ['draft_id' => $draft->id, 'draft_doc_num' => $draft->doc_num,
        'automatic_source_amount' => '17.5000', 'readonly_both_amounts' => true,
        'debit_credit_currency_locked_with_serialized_values' => true, 'real_login_user' => $fixture['user_id'],
        'real_ajax_select2' => 'PASS', 'normal_UI_save' => 'PASS', 'browser_console_errors' => 0];
    $manifest['prospective_account_audit'] = [];
    foreach (Company::query()->orderBy('id')->get() as $company) {
        $audit = app(PostingAccountConfigurationAudit::class)->forCompany($company->id);
        $manifest['prospective_account_audit'][] = ['company_id' => $company->id, 'company_doc_num' => $company->doc_num, ...$audit];
        if ($company->id === 1) {
            $row = collect($audit['rows'])->firstWhere('code', PostingAccountResolver::InventoryAdjustmentLoss);
            expect($row['status'])->toBe('ready')->and($row['accounts'])->toContain('53 /')
                ->and($row['incompatible_accounts'])->toContain('112211 /');
        }
    }
    localOpeningAssertOriginalRows($manifest);
    localOpeningSaveManifest($manifest);
    Http::assertNothingSent();
    Mail::assertNothingOutgoing();
    Notification::assertNothingSent();
    Queue::assertNothingPushed();
});

test('local opening two actual connections serialize duplicate approval and source population changes', function (): void {
    require_once __DIR__.'/LocalOpeningPostgresRaceSupport.php';
    $manifest = json_decode(file_get_contents(LocalOpeningAcceptanceManifest), true, flags: JSON_THROW_ON_ERROR);
    expect($manifest['stage'])->toBe('clean_ui_ready');
    $ui = $manifest['ui_fixture'];
    $f = localOpeningSetContext($this, $ui['branch_id']);
    $valuation = app(OpeningInventoryValuationService::class);
    $preview = $valuation->preview(1, 1, $ui['branch_id'], $ui['account_doc_num']);
    $data = ['document_date' => $f['period']->from_date->toDateString(), 'currency_doc_num' => $ui['currency_doc_num'], 'exchange_rate' => '1',
        'description' => 'SYNTHETIC LOCAL DEV duplicate approval loser', 'inventory_source_fingerprint' => $preview['snapshot']['fingerprint'],
        'lines' => [['account_doc_num' => $ui['account_doc_num'], 'transaction_type' => 'debit', 'amount' => '1'],
            ['account_doc_num' => $ui['counterpart_doc_num'], 'transaction_type' => 'credit', 'amount' => '1']]];
    $first = OpeningBalance::findOrFail($manifest['browser']['draft_id']);
    $second = app(OpeningBalanceService::class)->create($data)['record'];
    $before = DB::table('journal_entries')->count();
    $duplicate = localOpeningOrderedRace([['operation' => 'approve', 'id' => $first->id], ['operation' => 'approve', 'id' => $second->id]]);
    expect(array_column($duplicate['results'], 'result'))->toBe(['applied', 'blocked'])
        ->and(DB::table('journal_entries')->count())->toBe($before + 1)->and($first->fresh()->approved)->toBeTrue()
        ->and($second->fresh()->approved)->toBeFalse()->and($second->fresh()->journal_entry_id)->toBeNull();
    $manifest['concurrency']['duplicate'] = $duplicate;
    $manifest['browser']['approved_journal_doc_num'] = $first->fresh()->journalEntry->doc_num;
    $source = app(OpeningStockService::class)->create(['document_date' => $f['period']->from_date->toDateString(),
        'branch_store_uuid' => BranchStore::findOrFail($ui['store_id'])->public_uuid,
        'notes' => 'SYNTHETIC LOCAL DEV blocked source after GL finalization',
        'lines' => [['product_doc_num' => Product::findOrFail($ui['product_id'])->doc_num, 'quantity' => '1', 'stock_status' => 'available']]])['record'];
    $rootCount = DB::table('inventory_transactions')->count();
    expect(fn () => app(OpeningStockService::class)->approve($source))->toThrow(DomainException::class);
    expect($source->fresh()->approved)->toBeFalse()->and(DB::table('inventory_transactions')->count())->toBe($rootCount);
    $manifest['concurrency']['new_source_after_gl'] = ['source_id' => $source->id, 'result' => 'blocked', 'stock_and_journal_unchanged' => true];
    $manifest['stage'] = 'synthetic_duplicate_race_complete';
    localOpeningAssertOriginalRows($manifest);
    localOpeningSaveManifest($manifest);
    Http::assertNothingSent();
    Mail::assertNothingOutgoing();
    Notification::assertNothingSent();
    Queue::assertNothingPushed();
});

test('local opening configuration beyond the three requested mappings stays outside this addendum', function (): void {
    $this->markTestSkipped('Purchase price variance and inventory adjustment gain belong to customer configuration approval; no synthetic mapping is retained.');
});

/** @return array<string, mixed> */
function localOpeningPopulationFixture(object $test, int $number, array $ui): array
{
    return DB::transaction(function () use ($test, $number, $ui): array {
        $branch = Branch::create(['company_id' => 1, 'doc_number' => $number,
            'doc_num' => 'SYNTHETIC-OPENING-BRANCH-'.$number, 'name' => 'SYNTHETIC LOCAL DEV source population race '.$number,
            'type' => Branch::TypeWarehouse, 'status' => 'active']);
        $store = BranchStore::create(['branch_id' => $branch->id, 'name' => 'SYNTHETIC population race '.$number, 'position' => 1]);
        $f = localOpeningSetContext($test, $branch->id);
        $product = Product::findOrFail($ui['product_id']);
        $stocks = app(OpeningStockService::class);
        $payload = ['document_date' => $f['period']->from_date->toDateString(), 'branch_store_uuid' => $store->public_uuid,
            'notes' => 'SYNTHETIC source population acceptance, no customer history',
            'lines' => [['product_doc_num' => $product->doc_num, 'quantity' => '10', 'stock_status' => 'available']]];
        $source = $stocks->create($payload)['record'];
        $stocks->approve($source);
        app(OpeningStockPricingService::class)->create(['document_date' => $payload['document_date'],
            'opening_stock_doc_num' => $source->doc_num, 'currency_doc_num' => $ui['currency_doc_num'], 'exchange_rate' => '1',
            'pricing_basis' => 'documented', 'source_reference' => 'SYNTHETIC acceptance unit price1.75',
            'lines' => [['opening_stock_line_public_id' => $source->lines()->sole()->public_id, 'unit_price' => '1.75000000']]], request());
        $payload['lines'][0]['quantity'] = '1';
        $pending = $stocks->create($payload)['record'];
        $preview = app(OpeningInventoryValuationService::class)->preview(1, 1, $branch->id, $ui['account_doc_num']);
        $data = ['document_date' => $payload['document_date'], 'currency_doc_num' => $ui['currency_doc_num'], 'exchange_rate' => '1',
            'description' => 'SYNTHETIC source population draft '.$number, 'inventory_source_fingerprint' => $preview['snapshot']['fingerprint'],
            'lines' => [['account_doc_num' => $ui['account_doc_num'], 'transaction_type' => 'debit', 'amount' => '1'],
                ['account_doc_num' => $ui['counterpart_doc_num'], 'transaction_type' => 'credit', 'amount' => '1']]];
        $draft = app(OpeningBalanceService::class)->create($data)['record'];

        return ['branch_id' => $branch->id, 'draft_id' => $draft->id, 'pending_source_id' => $pending->id,
            'initial_priced_source_id' => $source->id, 'payload' => $data];
    });
}

test('local opening real source and GL races conserve both ordered outcomes and reconcile synthetic stock exactly', function (): void {
    require_once __DIR__.'/LocalOpeningPostgresRaceSupport.php';
    $manifest = json_decode(file_get_contents(LocalOpeningAcceptanceManifest), true, flags: JSON_THROW_ON_ERROR);
    expect($manifest['stage'])->toBe('synthetic_duplicate_race_complete');
    $ui = $manifest['ui_fixture'];
    foreach ([900002 => true, 900003 => false] as $number => $sourceFirst) {
        $f = localOpeningPopulationFixture($this, $number, $ui);
        $first = ['operation' => $sourceFirst ? 'source-approve' : 'approve', 'id' => $sourceFirst ? $f['pending_source_id'] : $f['draft_id']];
        $second = ['operation' => $sourceFirst ? 'approve' : 'source-approve', 'id' => $sourceFirst ? $f['draft_id'] : $f['pending_source_id']];
        $before = DB::table('journal_entries')->count();
        $race = localOpeningOrderedRace([$first, $second]);
        expect(array_column($race['results'], 'result'))->toBe(['applied', 'blocked']);
        $draft = OpeningBalance::findOrFail($f['draft_id']);
        $pending = OpeningStock::findOrFail($f['pending_source_id']);
        if ($sourceFirst) {
            expect($draft->approved)->toBeFalse()->and($draft->journal_entry_id)->toBeNull()
                ->and(DB::table('journal_entries')->count())->toBe($before)->and($pending->approved)->toBeTrue();
            app(OpeningStockPricingService::class)->create(['document_date' => $f['payload']['document_date'],
                'opening_stock_doc_num' => $pending->doc_num, 'currency_doc_num' => $ui['currency_doc_num'], 'exchange_rate' => '1',
                'pricing_basis' => 'documented', 'source_reference' => 'SYNTHETIC added source price1.75',
                'lines' => [['opening_stock_line_public_id' => $pending->lines()->sole()->public_id, 'unit_price' => '1.75000000']]], request());
            $preview = app(OpeningInventoryValuationService::class)->preview(1, 1, $f['branch_id'], $ui['account_doc_num']);
            expect($preview['amount'])->toBe('19.2500');
            $data = $f['payload'];
            $data['inventory_source_fingerprint'] = $preview['snapshot']['fingerprint'];
            $data['description'] = 'SYNTHETIC refreshed source population opening '.$number;
            $fresh = app(OpeningBalanceService::class)->create($data)['record'];
            $posted = app(OpeningBalanceApprovalService::class)->approve($fresh);
        } else {
            expect($draft->approved)->toBeTrue()->and($pending->approved)->toBeFalse()
                ->and(DB::table('inventory_transactions')->where('source_type', OpeningStock::class)->where('source_id', $pending->id)->count())->toBe(0)
                ->and(DB::table('journal_entries')->count())->toBe($before + 1);
            $posted = $draft;
        }
        $manifest['concurrency']['source_population_'.$number] = [...$f, 'race' => $race,
            'posted_opening_doc_num' => $posted->doc_num, 'posted_journal_doc_num' => $posted->journalEntry->doc_num,
            'amount' => $posted->inventory_valuation_snapshot['amount'], 'synthetic_only' => true];
    }
    $manifest['final_reconciliation'] = app(InventoryGlReconciliationService::class)->reconcile(1, 1);
    foreach ($manifest['final_reconciliation'] as $row) {
        expect(bccomp((string) $row['difference'], '0', 4))->toBe(0);
    }
    localOpeningAssertOriginalRows($manifest);
    $manifest['stage'] = 'local_acceptance_complete';
    localOpeningSaveManifest($manifest);
    Http::assertNothingSent();
    Mail::assertNothingOutgoing();
    Notification::assertNothingSent();
    Queue::assertNothingPushed();
});

test('local opening withdraws unused out of scope provisional choices and diagnoses the failed synthetic race', function (): void {
    $manifest = json_decode(file_get_contents(LocalOpeningAcceptanceManifest), true, flags: JSON_THROW_ON_ERROR);
    localOpeningSetContext($this, 1);
    DB::transaction(function () use (&$manifest): void {
        foreach ($manifest['local_mutations'] as $mutation) {
            if ($mutation['operation'] !== 'canonical provisional LOCAL DEV posting configuration') {
                continue;
            }
            $account = Account::findOrFail($mutation['id']);
            expect(in_array($mutation['classification'], [PostingAccountResolver::InventoryAdjustmentGain,
                PostingAccountResolver::PurchasePriceVariance], true))->toBeTrue();
            expect(DB::table('journal_entry_lines')->where('account_id', $account->id)->count())->toBe(0);
            app(AccountService::class)->delete($account);
            $manifest['withdrawn_provisional_configuration'][] = ['id' => $account->id, 'doc_num' => $account->doc_num,
                'code' => $account->account_code, 'classification' => $mutation['classification'], 'action' => 'canonical reversible soft delete',
                'reason' => 'Outside the requested three mappings; no financial usage. Preserve legacy customer configuration for approval.'];
        }
        $branch = Branch::where('doc_num', 'SYNTHETIC-OPENING-BRANCH-900002')->sole();
        $sources = OpeningStock::where('branch_id', $branch->id)->orderBy('id')->get();
        $draft = OpeningBalance::where('description', 'SYNTHETIC source population draft 900002')->sole();
        $manifest['failed_source_race'] = ['log' => '/tmp/mgypack-local-opening-source-races-20261004.log', 'cause' => 'period/company lock inversion',
            'branch_id' => $branch->id, 'sources' => $sources->map(fn ($source): array => $source->only(['id', 'doc_num', 'approved', 'journal_entry_id']))->all(),
            'draft' => $draft->only(['id', 'doc_num', 'approved', 'journal_entry_id']), 'synthetic_only' => true];
        localOpeningAssertOriginalRows($manifest);
    });
    localOpeningSaveManifest($manifest);
    Http::assertNothingSent();
    Mail::assertNothingOutgoing();
    Notification::assertNothingSent();
    Queue::assertNothingPushed();
});

test('local opening completes only the isolated failed race fixture without rewriting customer rows', function (): void {
    $manifest = json_decode(file_get_contents(LocalOpeningAcceptanceManifest), true, flags: JSON_THROW_ON_ERROR);
    $failed = $manifest['failed_source_race'];
    $ui = $manifest['ui_fixture'];
    $f = localOpeningSetContext($this, $failed['branch_id']);
    DB::transaction(function () use (&$manifest, $failed, $ui, $f): void {
        $sources = OpeningStock::where('branch_id', $failed['branch_id'])->orderBy('id')->get();
        $pending = $sources->last();
        expect($pending->notes)->toContain('SYNTHETIC');
        if (! $pending->approved) {
            app(OpeningStockService::class)->approve($pending);
        }
        $line = $pending->lines()->sole();
        expect(DB::table('inventory_opening_stock_pricing_lines')->where('opening_stock_line_id', $line->id)->whereNull('deleted_at')->exists())->toBeFalse();
        $pricing = app(OpeningStockPricingService::class)->create([
            'document_date' => $f['period']->from_date->toDateString(), 'opening_stock_doc_num' => $pending->doc_num,
            'currency_doc_num' => $ui['currency_doc_num'], 'exchange_rate' => '1', 'pricing_basis' => 'documented',
            'source_reference' => 'SYNTHETIC failed race fixture recovery, price1.75',
            'lines' => [['opening_stock_line_public_id' => $line->public_id, 'unit_price' => '1.75000000']]], request())['record'];
        $preview = app(OpeningInventoryValuationService::class)->preview(1, 1, $failed['branch_id'], $ui['account_doc_num']);
        expect($preview['amount'])->toBe('19.2500');
        $data = ['document_date' => $f['period']->from_date->toDateString(), 'currency_doc_num' => $ui['currency_doc_num'], 'exchange_rate' => '1',
            'description' => 'SYNTHETIC failed race fixture canonical recovery', 'inventory_source_fingerprint' => $preview['snapshot']['fingerprint'],
            'lines' => [['account_doc_num' => $ui['account_doc_num'], 'transaction_type' => 'debit', 'amount' => '1'],
                ['account_doc_num' => $ui['counterpart_doc_num'], 'transaction_type' => 'credit', 'amount' => '1']]];
        $draft = app(OpeningBalanceService::class)->create($data)['record'];
        $posted = app(OpeningBalanceApprovalService::class)->approve($draft);
        $manifest['failed_source_race']['recovery'] = ['pricing_id' => $pricing->id, 'opening_doc_num' => $posted->doc_num,
            'journal_doc_num' => $posted->journalEntry->doc_num, 'amount' => '19.2500', 'no_customer_rows_changed' => true];
        localOpeningAssertOriginalRows($manifest);
    });
    localOpeningSaveManifest($manifest);
    Http::assertNothingSent();
    Mail::assertNothingOutgoing();
    Notification::assertNothingSent();
    Queue::assertNothingPushed();
});

test('local opening clean backup replay configures the requested expense before scoped opening approval', function (): void {
    $manifest = json_decode(file_get_contents(LocalOpeningAcceptanceManifest), true, flags: JSON_THROW_ON_ERROR);
    $replay = 'mgypack_opening_replay_20261004';
    expect(DB::table('pg_database')->where('datname', $replay)->exists())->toBeFalse();
    DB::statement('CREATE DATABASE '.$replay);
    $config = config('database.connections.pgsql');
    (new Process(['pg_restore', '--no-password', '--host=127.0.0.1', '--port=5432', '--username='.$config['username'],
        '--dbname='.$replay, '--no-owner', '--no-privileges', LocalOpeningAcceptanceBackup], env: ['PGPASSWORD' => $config['password']]))
        ->setTimeout(90)->mustRun();
    config(['database.connections.local_opening_replay' => [...$config, 'database' => $replay]]);
    $originalDefault = DB::getDefaultConnection();
    try {
        DB::setDefaultConnection('local_opening_replay');
        expect(DB::selectOne('select current_database() as db')->db)->toBe($replay);
        localOpeningAssertOriginalRows($manifest);
        $this->artisan('migrate', ['--database' => 'local_opening_replay', '--force' => true])->assertSuccessful();
        localOpeningSetContext($this, 1);
        $expenseParent = Account::where('company_id', 1)->where('account_code', '5')->sole();
        $loss = app(AccountService::class)->create(['account_code' => '', 'parent_doc_num' => $expenseParent->doc_num,
            'classification_code' => PostingAccountResolver::InventoryAdjustmentLoss,
            'name' => 'SYNTHETIC LOCAL DEV replay loss', 'normal_balance' => 'debit', 'is_group' => false, 'is_postable' => true, 'status' => 'active']);
        expect($loss->account_type)->toBe('expense')->and($loss->statement_type)->toBe('income_statement')
            ->and(app(PostingAccountResolver::class)->resolve(1, PostingAccountResolver::InventoryAdjustmentLoss, 'fresh replay')->id)->toBe($loss->id);
        $equityParent = Account::where('company_id', 1)->where('account_code', '3')->sole();
        $counterpart = app(AccountService::class)->create(['account_code' => '399990', 'parent_doc_num' => $equityParent->doc_num,
            'classification_code' => 'capital', 'name' => 'SYNTHETIC LOCAL DEV replay counterpart', 'normal_balance' => 'credit',
            'is_group' => false, 'is_postable' => true, 'status' => 'active']);
        $currency = Currency::where('company_id', 1)->where('is_main', true)->sole();
        $journals = [];
        foreach ([1, 3] as $branchId) {
            $f = localOpeningSetContext($this, $branchId);
            foreach ([PostingAccountResolver::RawMaterialInventory, PostingAccountResolver::PackagingMaterialInventory,
                PostingAccountResolver::FinishedGoodsInventory] as $classification) {
                $account = app(PostingAccountResolver::class)->resolve(1, $classification, 'fresh local replay');
                $preview = $this->getJson(route('admin.finance.opening-balances.inventory-valuation', ['account_doc_num' => $account->doc_num]))->assertOk()->json('data');
                $response = $this->postJson(route('admin.finance.opening-balances.store'), [
                    'document_date' => $f['period']->from_date->format(app(DateFormatService::class)->dateFormat()),
                    'currency_doc_num' => $currency->doc_num, 'exchange_rate' => '1', 'description' => 'SYNTHETIC LOCAL DEV clean replay opening',
                    'inventory_source_fingerprint' => $preview['source_fingerprint'],
                    'lines' => [['account_doc_num' => $account->doc_num, 'transaction_type' => 'debit', 'amount' => '1'],
                        ['account_doc_num' => $counterpart->doc_num, 'transaction_type' => 'credit', 'amount' => '1']]])->assertOk();
                $record = OpeningBalance::where('doc_num', $response->json('data.doc_num'))->sole();
                $this->postJson(route('admin.finance.opening-balances.approve', $record->doc_num))->assertOk();
                $this->postJson(route('admin.finance.opening-balances.approve', $record->doc_num))->assertStatus(422);
                $journals[] = ['branch_id' => $branchId, 'classification' => $classification, 'amount' => $preview['amount'],
                    'opening_id' => $record->id, 'opening_doc_num' => $record->doc_num, 'journal_id' => $record->fresh()->journal_entry_id];
            }
        }
        $rows = app(InventoryGlReconciliationService::class)->reconcile(1, 1);
        foreach ($rows as $row) {
            expect(bccomp((string) $row['difference'], '0', 4))->toBe(0);
        }
        localOpeningAssertOriginalRows($manifest);
        $manifest['clean_replay'] = ['database' => $replay, 'backup_sha256' => hash_file('sha256', LocalOpeningAcceptanceBackup),
            'migration_count' => DB::table('migrations')->count(), 'expense_configured_before_first_resolution' => true,
            'expense_account' => $loss->only(['id', 'account_code', 'doc_num', 'parent_id', 'account_type', 'statement_type', 'normal_balance']),
            'counterpart' => $counterpart->only(['id', 'account_code', 'doc_num']), 'journals' => $journals, 'reconciliation' => $rows,
            'original_rows' => 'exact unchanged', 'customer_approved' => false];
    } finally {
        DB::setDefaultConnection($originalDefault);
    }
    localOpeningSaveManifest($manifest);
    Http::assertNothingSent();
    Mail::assertNothingOutgoing();
    Notification::assertNothingSent();
    Queue::assertNothingPushed();
});

test('local opening reads canonical stock-account reconciliation separately from unrelated event mapping gaps', function (): void {
    $manifest = json_decode(file_get_contents(LocalOpeningAcceptanceManifest), true, flags: JSON_THROW_ON_ERROR);
    $period = FinancialPeriod::findOrFail(1);
    $results = [];
    foreach ([1, 3, 8, 9, 10, 11] as $branch) {
        if (! Branch::whereKey($branch)->exists()) {
            continue;
        }
        $report = app(ReconciliationCenterService::class)->report(1, 1, $branch,
            $period->from_date->toDateString(), $period->to_date->toDateString(), ReconciliationCenterService::Inventory);
        $results[$branch] = $report['results']->first();
        fwrite(STDOUT, json_encode(['branch' => $branch, 'status' => $results[$branch]['status'], 'summary' => $results[$branch]['summary']], JSON_THROW_ON_ERROR).PHP_EOL);
    }
    $manifest['canonical_inventory_reconciliation_probe'] = $results;
    localOpeningAssertOriginalRows($manifest);
    localOpeningSaveManifest($manifest);
});

test('local opening clean restored acceptance configures only required mappings and prepares a fresh UI source', function (): void {
    $previous = json_decode(file_get_contents(LocalOpeningAcceptanceManifest), true, flags: JSON_THROW_ON_ERROR);
    localOpeningAssertOriginalRows($previous);
    expect(hash_file('sha256', LocalOpeningAcceptanceBackup))->toBe($previous['backup_sha256']);
    $historyPath = '/tmp/mgypack-local-opening-before-clean-20261004.json';
    if (! file_exists($historyPath)) {
        file_put_contents($historyPath, json_encode($previous, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        chmod($historyPath, 0600);
    }
    $checkpointBackup = '/tmp/mgypack-local-opening-before-clean-20261004.dump';
    $config = config('database.connections.pgsql');
    if (! file_exists($checkpointBackup)) {
        (new Process(['pg_dump', '--no-password', '--host=127.0.0.1', '--port=5432', '--username='.$config['username'],
            '--format=custom', '--file='.$checkpointBackup, LocalOpeningAcceptanceDatabase], env: ['PGPASSWORD' => $config['password']]))->setTimeout(90)->mustRun();
        chmod($checkpointBackup, 0600);
    }
    $freshDatabase = 'mgypack_opening_clean_restore_20261004';
    $archivedDatabase = 'mgypack_opening_before_clean_20261004';
    expect(DB::table('pg_database')->whereIn('datname', [$freshDatabase, $archivedDatabase])->count())->toBe(0);
    DB::statement('CREATE DATABASE '.$freshDatabase);
    (new Process(['pg_restore', '--no-password', '--host=127.0.0.1', '--port=5432', '--username='.$config['username'],
        '--dbname='.$freshDatabase, '--no-owner', '--no-privileges', LocalOpeningAcceptanceBackup],
        env: ['PGPASSWORD' => $config['password']]))->setTimeout(90)->mustRun();
    config(['database.connections.local_opening_fresh' => [...$config, 'database' => $freshDatabase],
        'database.connections.local_opening_admin' => [...$config, 'database' => 'postgres']]);
    $fresh = DB::connection('local_opening_fresh');
    expect($fresh->selectOne('select current_database() as db')->db)->toBe($freshDatabase);
    foreach ($previous['original_rows'] as $table => $rows) {
        expect($fresh->table($table)->count())->toBe(count($rows));
        foreach ($fresh->table($table)->orderBy('id')->get() as $row) {
            expect(hash('sha256', json_encode($row, JSON_THROW_ON_ERROR)))->toBe($rows[$row->id]);
        }
    }
    $pid = DB::selectOne('select pg_backend_pid() as pid')->pid;
    expect(DB::table('pg_stat_activity')->where('datname', LocalOpeningAcceptanceDatabase)->where('pid', '<>', $pid)->count())->toBe(0);
    DB::disconnect();
    DB::disconnect('local_opening_fresh');
    $admin = DB::connection('local_opening_admin');
    expect($admin->selectOne('select current_database() as db, host(inet_server_addr()) as host, inet_server_port() as port')->host)->toBe('127.0.0.1');
    $admin->statement('ALTER DATABASE '.LocalOpeningAcceptanceDatabase.' RENAME TO '.$archivedDatabase);
    $admin->statement('ALTER DATABASE '.$freshDatabase.' RENAME TO '.LocalOpeningAcceptanceDatabase);
    DB::purge();
    DB::reconnect();
    expect(DB::selectOne('select current_database() as db')->db)->toBe(LocalOpeningAcceptanceDatabase);
    expect(localOpeningOriginalRows())->toBe($previous['original_rows']);
    $this->artisan('migrate', ['--force' => true])->assertSuccessful();
    $manifest = ['stage' => 'clean_restore_started', 'database' => LocalOpeningAcceptanceDatabase, 'host' => '127.0.0.1', 'port' => 5432,
        'original_rows' => $previous['original_rows'], 'backup' => LocalOpeningAcceptanceBackup, 'backup_sha256' => $previous['backup_sha256'],
        'migration' => $previous['migration'], 'historical_attempts' => $historyPath, 'checkpoint_backup' => $checkpointBackup,
        'checkpoint_backup_sha256' => hash_file('sha256', $checkpointBackup), 'preserved_preclean_database' => $archivedDatabase, 'local_mutations' => [], 'provisional_loss_choices' => [],
        'customer_financial_approval' => false, 'customer_uat' => false, 'live_release' => false, 'outbound' => $previous['outbound']];
    localOpeningSaveManifest($manifest);
    DB::transaction(function () use (&$manifest): void {
        localOpeningSetContext($this, 1);
        $resolver = app(PostingAccountResolver::class);
        foreach ([PostingAccountResolver::InventoryAdjustmentLoss, PostingAccountResolver::InventoryAdjustmentGain] as $code) {
            $classification = AccountClassification::where('code', $code)->active()->sole();
            $parent = Account::where('company_id', 1)->where('account_code', $classification->account_type === 'revenue' ? '4' : '5')->sole();
            expect($parent->account_type)->toBe($classification->account_type)->and($parent->statement_type)->toBe($classification->statement_type);
            $account = app(AccountService::class)->create(['account_code' => '', 'parent_doc_num' => $parent->doc_num,
                'classification_code' => $code, 'normal_balance' => $classification->normal_balance,
                'name' => 'SYNTHETIC LOCAL DEV '.$classification->name, 'name_en' => 'SYNTHETIC LOCAL DEV '.$code,
                'is_group' => false, 'is_postable' => true, 'status' => 'active',
                'notes' => 'Provisional LOCAL DEV only; original incompatible account preserved; no customer approval.']);
            expect($resolver->resolve(1, $code, 'clean local configuration')->id)->toBe($account->id)
                ->and(DB::table('journal_entry_lines')->where('account_id', $account->id)->count())->toBe(0);
            $manifest['local_mutations'][] = ['operation' => 'provisional canonical account creation before first resolution', 'classification' => $code,
                ...$account->only(['id', 'company_id', 'doc_num', 'account_code', 'name', 'parent_id', 'account_type', 'statement_type', 'normal_balance', 'is_postable', 'is_group', 'status']),
                'customer_approved' => false, 'prior_journal_lines' => 0, 'reason' => $code === PostingAccountResolver::InventoryAdjustmentGain ?
                    'Required by the actual InventoryGlReconciliationService; missing-map failure is retained in historical attempts.' : 'Requested valid adjustment-loss expense mapping.'];
        }
        foreach ([PostingAccountResolver::AbnormalWasteLoss, PostingAccountResolver::WarehouseDamageLoss,
            PostingAccountResolver::InventoryAdjustmentLoss] as $code) {
            $account = $resolver->resolve(1, $code, 'clean provisional loss choice')->refresh();
            expect($account->account_type)->toBe('expense')->and($account->statement_type)->toBe('income_statement')
                ->and($account->normal_balance)->toBe('debit')->and($account->is_postable)->toBeTrue()->and($account->is_group)->toBeFalse()
                ->and(DB::table('journal_entry_lines')->where('account_id', $account->id)->count())->toBe(0);
            $manifest['provisional_loss_choices'][] = ['classification' => $code,
                ...$account->only(['id', 'company_id', 'doc_num', 'account_code', 'name', 'parent_id', 'account_type', 'statement_type', 'normal_balance']),
                'prior_journal_lines' => 0, 'customer_approved' => false,
                'parent_basis' => $account->parent_id ? 'canonical expense-tree child' : 'existing explicitly synthetic direct expense leaf; parent is optional in AccountService; retained unchanged'];
        }
        $parent = Account::where('company_id', 1)->where('account_code', '3')->sole();
        $counterpart = app(AccountService::class)->create(['doc_number' => 900001, 'account_code' => '399990',
            'parent_doc_num' => $parent->doc_num, 'classification_code' => 'capital', 'normal_balance' => 'credit',
            'name' => 'SYNTHETIC LOCAL DEV مقابل افتتاح مخزون غير معتمد للعميل', 'name_en' => 'SYNTHETIC LOCAL DEV provisional opening counterpart',
            'is_group' => false, 'is_postable' => true, 'status' => 'active', 'notes' => 'LOCAL DEV rehearsal only, no customer financial approval.']);
        $manifest['counterpart'] = [...$counterpart->only(['id', 'company_id', 'doc_num', 'account_code', 'name', 'parent_id', 'account_type', 'statement_type', 'normal_balance']), 'customer_approved' => false];
        $currency = Currency::where('company_id', 1)->where('is_main', true)->sole();
        $manifest['opening_before'] = app(InventoryGlReconciliationService::class)->reconcile(1, 1);
        foreach ([1, 3] as $branchId) {
            $f = localOpeningSetContext($this, $branchId);
            foreach ([PostingAccountResolver::RawMaterialInventory, PostingAccountResolver::PackagingMaterialInventory,
                PostingAccountResolver::FinishedGoodsInventory] as $code) {
                $account = $resolver->resolve(1, $code, 'clean LOCAL DEV opening');
                $preview = $this->getJson(route('admin.finance.opening-balances.inventory-valuation', ['account_doc_num' => $account->doc_num]))->assertOk()->json('data');
                expect($preview['can_post'])->toBeTrue();
                $response = $this->postJson(route('admin.finance.opening-balances.store'), [
                    'document_date' => $f['period']->from_date->format(app(DateFormatService::class)->dateFormat()),
                    'currency_doc_num' => $currency->doc_num, 'exchange_rate' => '1', 'description' => 'SYNTHETIC LOCAL DEV clean priced opening rehearsal',
                    'inventory_source_fingerprint' => $preview['source_fingerprint'],
                    'lines' => [['account_doc_num' => $account->doc_num, 'transaction_type' => 'credit', 'amount' => '999'],
                        ['account_doc_num' => $counterpart->doc_num, 'transaction_type' => 'debit', 'amount' => '1']]])->assertOk();
                $record = OpeningBalance::where('doc_num', $response->json('data.doc_num'))->sole();
                $this->postJson(route('admin.finance.opening-balances.approve', $record->doc_num))->assertOk();
                $this->postJson(route('admin.finance.opening-balances.approve', $record->doc_num))->assertStatus(422);
                $manifest['opening_journals'][] = ['branch_id' => $branchId, 'classification' => $code, 'account_id' => $account->id,
                    'account_code' => $account->account_code, 'amount' => $preview['amount'], 'source_count' => $preview['source_count'],
                    'opening_id' => $record->id, 'opening_doc_num' => $record->doc_num, 'journal_id' => $record->fresh()->journal_entry_id,
                    'journal_doc_num' => $record->fresh()->journalEntry->doc_num, 'customer_approved' => false];
            }
        }
        localOpeningAssertOriginalRows($manifest);
        $branch = Branch::create(['company_id' => 1, 'doc_number' => 900001, 'doc_num' => 'SYNTHETIC-OPENING-BRANCH-900001',
            'name' => 'SYNTHETIC LOCAL DEV opening UI acceptance', 'type' => Branch::TypeWarehouse, 'status' => 'active']);
        $store = BranchStore::create(['branch_id' => $branch->id, 'name' => 'SYNTHETIC opening UI store', 'position' => 1]);
        $unit = ItemUnit::where('company_id', 1)->firstOrFail();
        $product = Product::create(['company_id' => 1, 'doc_number' => 900001, 'doc_num' => 'SYNTHETIC-OPENING-RAW-900001',
            'name' => 'SYNTHETIC local opening acceptance material', 'item_classification' => Product::ClassificationRawMaterial,
            'item_unit_id' => $unit->id, 'status' => 'active']);
        $f = localOpeningSetContext($this, $branch->id);
        $source = app(OpeningStockService::class)->create(['document_date' => $f['period']->from_date->toDateString(),
            'branch_store_uuid' => $store->public_uuid, 'notes' => 'SYNTHETIC local UI acceptance source',
            'lines' => [['product_doc_num' => $product->doc_num, 'quantity' => '10', 'stock_status' => 'available']]])['record'];
        app(OpeningStockService::class)->approve($source);
        $pricing = app(OpeningStockPricingService::class)->create(['document_date' => $f['period']->from_date->toDateString(),
            'opening_stock_doc_num' => $source->doc_num, 'currency_doc_num' => $currency->doc_num, 'exchange_rate' => '1', 'pricing_basis' => 'documented',
            'source_reference' => 'SYNTHETIC local acceptance unit price1.75',
            'lines' => [['opening_stock_line_public_id' => $source->lines()->sole()->public_id, 'unit_price' => '1.75000000']]], request())['record'];
        $actor = User::factory()->create(['doc_number' => (int) User::withTrashed()->max('doc_number') + 1,
            'doc_num' => 'SYNTHETIC-OPENING-USER-900001', 'username' => 'synthetic-opening-20261004', 'email' => 'synthetic-opening-20261004@example.test',
            'name' => 'SYNTHETIC LOCAL DEV opening acceptance', 'password' => Hash::make('SyntheticOpeningDev2026!')]);
        $actor->assignRole($f['actor']->roles->pluck('name')->all());
        $account = $resolver->resolve(1, PostingAccountResolver::RawMaterialInventory, 'UI source');
        $manifest['ui_fixture'] = ['synthetic' => true, 'user_id' => $actor->id, 'username' => $actor->username, 'branch_id' => $branch->id,
            'branch_doc_num' => $branch->doc_num, 'store_id' => $store->id, 'product_id' => $product->id, 'source_id' => $source->id,
            'source_doc_num' => $source->doc_num, 'pricing_id' => $pricing->id, 'pricing_doc_num' => $pricing->doc_num,
            'account_doc_num' => $account->doc_num, 'counterpart_doc_num' => $counterpart->doc_num, 'currency_doc_num' => $currency->doc_num,
            'amount' => '17.5000', 'company_doc_num' => $f['company']->doc_num, 'period_doc_num' => $f['period']->doc_num];
    });
    $manifest['stage'] = 'clean_ui_ready';
    localOpeningSaveManifest($manifest);
    Http::assertNothingSent();
    Mail::assertNothingOutgoing();
    Notification::assertNothingSent();
    Queue::assertNothingPushed();
});

test('local opening final readback reconciles every accepted mutation and preserves original customer history', function (): void {
    $manifest = json_decode(file_get_contents(LocalOpeningAcceptanceManifest), true, flags: JSON_THROW_ON_ERROR);
    expect($manifest['stage'])->toBe('local_acceptance_complete');
    $manifest['final_reconciliation'] = app(InventoryGlReconciliationService::class)->reconcile(1, 1);
    $branches = [1, 3, $manifest['ui_fixture']['branch_id']];
    foreach ($manifest['concurrency'] as $key => $race) {
        if (str_starts_with($key, 'source_population_')) {
            $branches[] = $race['branch_id'];
        }
    }
    $manifest['final_branch_reconciliation'] = [];
    foreach ($branches as $branch) {
        $rows = app(InventoryGlReconciliationService::class)->reconcile(1, 1, $branch);
        foreach ($rows as $row) {
            expect(bccomp((string) $row['difference'], '0', 4))->toBe(0);
        }
        $manifest['final_branch_reconciliation'][$branch] = $rows;
    }
    foreach ($manifest['final_reconciliation'] as $row) {
        expect(bccomp((string) $row['difference'], '0', 4))->toBe(0);
    }
    $postings = OpeningBalance::where('company_id', 1)->whereNotNull('inventory_valuation_snapshot')->where('approved', true)->get();
    expect($postings)->toHaveCount(9);
    $counterpart = $manifest['counterpart'];
    $totals = DB::table('journal_entry_lines')->where('account_id', $counterpart['id'])
        ->selectRaw('sum(debit_amount) as debit, sum(credit_amount) as credit, count(distinct journal_entry_id) as journals')->first();
    expect(bccomp((string) $totals->debit, '0', 4))->toBe(0)->and(bcadd((string) $totals->credit, '0', 4))->toBe('10020195.2900')
        ->and((int) $totals->journals)->toBe(9);
    $manifest['final_totals'] = ['original_priced_sources_opening' => '10020141.0400', 'synthetic_ui_and_race_openings' => '54.2500',
        'counterpart_credit' => '10020195.2900', 'counterpart_debit' => '0.0000', 'new_opening_journals' => 9,
        'accepted_journals' => $postings->map(fn ($record): array => ['opening_doc_num' => $record->doc_num,
            'journal_doc_num' => $record->journalEntry->doc_num, 'branch_id' => $record->inventory_valuation_snapshot['branch_id'],
            'amount' => $record->inventory_valuation_snapshot['amount']])->all()];
    $manifest['prospective_account_audit'] = Company::orderBy('id')->get()->map(fn ($company): array => [
        'company_id' => $company->id, 'company_doc_num' => $company->doc_num,
        ...app(PostingAccountConfigurationAudit::class)->forCompany($company->id)])->all();
    $audit = $manifest['prospective_account_audit'][0];
    expect($audit['ok'])->toBeFalse()->and($audit['missing_count'])->toBe(1)->and($audit['incompatible_count'])->toBe(2)
        ->and(collect($audit['rows'])->where('status', 'account_missing')->pluck('code')->all())->toBe([PostingAccountResolver::PurchasePriceVariance]);
    expect(Account::where('company_id', 1)->whereHas('classification', fn ($q) => $q->where('code', PostingAccountResolver::PurchasePriceVariance))->count())->toBe(0);
    $config = config('database.connections.pgsql');
    config(['database.connections.local_opening_original' => [...$config, 'database' => 'mgypack_local_opening_recovery_20261004']]);
    $original = DB::connection('local_opening_original');
    expect($original->selectOne('select current_database() as db')->db)->toBe('mgypack_local_opening_recovery_20261004');
    $additional = [];
    foreach (['opening_balances', 'opening_balance_lines', 'account_opening_balances'] as $table) {
        foreach ($original->table($table)->orderBy('id')->get() as $row) {
            expect((array) DB::table($table)->where('id', $row->id)->first())->toBe((array) $row);
        }
        $additional[$table] = $original->table($table)->count();
    }
    localOpeningAssertOriginalRows($manifest);
    $manifest['original_history_proof'] = ['seventeen_table_original_row_hashes' => 'exact preserved', 'additional_financial_rows_from_restored_backup' => $additional,
        'legacy_accounts_668_669' => 'exact untouched; diagnostic incompatibility retained', 'no_retained_out_of_scope_ppv_mapping' => true];
    $manifest['migration']['current_source_sha256'] = hash_file('sha256', $manifest['migration']['path']);
    $manifest['clean_replay'] = ['result' => 'PASS', 'restored_backup_sha256' => $manifest['backup_sha256'], 'candidate_identity' => LocalOpeningAcceptanceDatabase,
        'host' => '127.0.0.1', 'port' => 5432, 'migrations' => DB::table('migrations')->count(), 'account_configuration_before_resolution' => true,
        'original_rows_preserved' => true, 'isolated_synthetic_acceptance' => true];
    $manifest['browser']['preview_screenshot'] = '/tmp/mgypack-opening-clean-auto-preview-20261004.jpg';
    localOpeningSaveManifest($manifest);
    Http::assertNothingSent();
    Mail::assertNothingOutgoing();
    Notification::assertNothingSent();
    Queue::assertNothingPushed();
});
