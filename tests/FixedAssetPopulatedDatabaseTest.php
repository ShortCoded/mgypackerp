<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\JournalEntry;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\OperatingContextService;
use Modules\FixedAssets\Models\FixedAsset;
use Modules\FixedAssets\Models\FixedAssetCategoryMapping;
use Modules\FixedAssets\Services\FixedAssetCostMovementService;
use Modules\FixedAssets\Services\FixedAssetDepreciationService;
use Modules\FixedAssets\Services\FixedAssetLedgerService;
use Modules\FixedAssets\Services\FixedAssetReportService;
use Modules\HR\Models\HrEmployee;
use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class);
require_once __DIR__.'/FixedAssetCycleSupport.php';

test('release acceptance preserves populated PostgreSQL history through both asset journeys', function (): void {
    if (DB::getDriverName() !== 'pgsql' || ! str_starts_with(DB::connection()->getDatabaseName(), 'fa_release_copy_')) {
        $this->markTestSkipped('Requires an explicitly isolated populated PostgreSQL release copy.');
    }
    expect(FixedAsset::count())->toBeGreaterThan(0);
    $historicalIds = DB::table('journal_entries')->pluck('id');
    $historical = DB::table('journal_entries')->whereIn('id', $historicalIds)->orderBy('id')->get()->toJson();
    $lines = DB::table('journal_entry_lines')->whereIn('journal_entry_id', $historicalIds)->orderBy('id')->get()->toJson();
    DB::beginTransaction();
    try {
        coreFixedAssetActor(['accounts.view', 'fixed_assets.create', 'fixed_assets.view', 'fixed_assets.activate', 'fixed_assets.reports', 'fixed_assets.depreciation.preview', 'fixed_assets.depreciation.post', 'fixed_assets.depreciation.reverse', 'fixed_assets.improvement.post', 'fixed_assets.transfer', 'fixed_assets.custody.post', 'fixed_assets.dispose', 'fixed_assets.disposal.reverse']);
        $context = coreFixedAssetContext(populated: true);
        coreCompleteExistingCycle($context);
        coreCompleteNewCycle($context);
        expect(DB::table('journal_entries')->whereIn('id', $historicalIds)->orderBy('id')->get()->toJson())->toBe($historical)
            ->and(DB::table('journal_entry_lines')->whereIn('journal_entry_id', $historicalIds)->orderBy('id')->get()->toJson())->toBe($lines);
        DB::commit();
    } catch (Throwable $exception) {
        DB::rollBack();
        throw $exception;
    }
});

test('release migrations preserve a populated database and repeated deployment is a no op', function (): void {
    if (DB::getDriverName() !== 'pgsql' || ! str_starts_with(DB::connection()->getDatabaseName(), 'fa_release_migrate_')) {
        $this->markTestSkipped('Requires a separate populated PostgreSQL migration rehearsal copy.');
    }
    expect(DB::table('fixed_asset_movements')->whereIn('movement_type', ['opening', 'capitalization', 'addition'])->count())->toBe(0);
    $path = 'modules/FixedAssets/Database/Migrations/2026_09_06_020102_extend_fixed_asset_movements_for_core_cycle.php';
    $migration = require base_path($path);
    DB::transaction(function () use ($migration): void {
        $migration->down();
        DB::table('migrations')->where('migration', '2026_09_06_020102_extend_fixed_asset_movements_for_core_cycle')->delete();
    });
    $tables = ['fixed_assets', 'fixed_asset_movements', 'fixed_asset_depreciations', 'fixed_asset_disposals', 'accounts', 'journal_entries', 'journal_entry_lines', 'opening_balances', 'opening_balance_lines'];
    $columns = [];
    $before = [];
    foreach ($tables as $table) {
        $columns[$table] = Schema::getColumnListing($table);
        $before[$table] = hash('sha256', DB::table($table)->orderBy('id')->get($columns[$table])->toJson());
    }
    for ($attempt = 0; $attempt < 2; $attempt++) {
        $this->artisan('migrate', ['--path' => 'modules/FixedAssets/Database/Migrations', '--force' => true, '--no-interaction' => true])->assertExitCode(0);
        foreach ($tables as $table) {
            expect(hash('sha256', DB::table($table)->orderBy('id')->get($columns[$table])->toJson()))->toBe($before[$table], $table);
        }
    }
    expect(DB::table('fixed_asset_movements')->where('movement_type', '!=', 'transfer')->count())->toBe(0);
});

test('release realistic volume measures index card depreciation and reconciliation on PostgreSQL', function (): void {
    if (DB::getDriverName() !== 'pgsql' || ! str_starts_with(DB::connection()->getDatabaseName(), 'fa_release_copy_')) {
        $this->markTestSkipped('Requires the isolated populated release copy after acceptance fixtures.');
    }
    coreFixedAssetActor(['fixed_assets.create', 'fixed_assets.view', 'fixed_assets.activate', 'fixed_assets.reports', 'fixed_assets.depreciation.preview', 'fixed_assets.depreciation.post']);
    request()->setLaravelSession(app('session.store'));
    $template = FixedAsset::query()->where('asset_name', 'like', 'Lifecycle Asset %')->where('entry_type', 'new_asset')->latest('id')->firstOrFail();
    $context = ['company' => $template->company, 'branch' => $template->branch, 'sourceCostCenter' => $template->costCenter, 'period' => FinancialPeriod::findOrFail($template->period_id), 'currency' => $template->currency, 'category' => $template->assetGroupAccount, 'postingAccounts' => collect([4 => $template->creditAccount])];
    $session = ['operating_company_id' => $template->company_id, 'operating_company_doc_num' => $template->company->doc_num, 'operating_branch_id' => $template->branch_id, 'operating_branch_doc_num' => $template->branch->doc_num, 'operating_financial_period_id' => $context['period']->id, 'operating_financial_period_doc_num' => $context['period']->doc_num];
    foreach (['Company' => $context['company'], 'Branch' => $context['branch'], 'FinancialPeriod' => $context['period']] as $prefix => $model) {
        $session[constant(OperatingContextService::class.'::'.$prefix.'IdKey')] = $model->id;
        $session[constant(OperatingContextService::class.'::'.$prefix.'DocNumKey')] = $model->doc_num;
    }
    session($session);
    $this->withSession($session);
    DB::beginTransaction();
    try {
        $assets = collect();
        for ($index = 0; $index < 500; $index++) {
            $assets->push(coreRecognizedAsset($context, ['asset_name' => 'Volume Asset '.$index]));
        }
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });
        $metrics = [];
        $measure = function (string $name, Closure $action) use (&$queries, &$metrics): mixed {
            $queries = 0;
            $start = microtime(true);
            $result = $action();
            $metrics[$name] = ['milliseconds' => round((microtime(true) - $start) * 1000), 'queries' => $queries];

            return $result;
        };
        $measure('index_50_of_500', fn () => $this->getJson(route('admin.fixed-assets.assets.data', ['draw' => 1, 'start' => 0, 'length' => 50]))->assertOk());
        $measure('asset_360', fn () => $this->get(route('admin.fixed-assets.lifecycle.show', $template))->assertOk());
        $filters = ['financial_period_doc_num' => $context['period']->doc_num, 'posting_date' => $context['period']->from_date->copy()->endOfMonth()->toDateString(), 'asset_doc_nums' => $assets->pluck('doc_num')->all()];
        $preview = $measure('preview_500', fn () => app(FixedAssetDepreciationService::class)->preview($filters));
        $run = $measure('post_500', fn () => app(FixedAssetDepreciationService::class)->post($filters));
        expect($run->lines)->toHaveCount(500);
        $report = $measure('reconciliation_502', fn () => app(FixedAssetReportService::class)->report(['type' => 'reconciliation', 'asset_group_account_doc_num' => $context['category']->doc_num]));
        foreach ($report['rows'] as $row) {
            expect(bccomp($row['difference'], '0', 4))->toBe(0);
        }
        fwrite(STDOUT, "\n".json_encode($metrics)."\n");
    } finally {
        DB::rollBack();
    }
});

test('release print export and source links work against populated PostgreSQL', function (): void {
    if (DB::getDriverName() !== 'pgsql' || ! str_starts_with(DB::connection()->getDatabaseName(), 'fa_release_copy_')) {
        $this->markTestSkipped('Requires the isolated populated release copy.');
    }
    coreFixedAssetActor(['fixed_assets.view', 'fixed_assets.print', 'fixed_assets.reports', 'fixed_assets.export', 'accounts.view', 'journal_entries.view']);
    request()->setLaravelSession(app('session.store'));
    $asset = FixedAsset::query()->where('asset_name', 'like', 'Lifecycle Asset %')->where('entry_type', 'opening_asset')->latest('id')->firstOrFail();
    $session = [];
    foreach (['Company' => $asset->company, 'Branch' => $asset->branch, 'FinancialPeriod' => FinancialPeriod::findOrFail($asset->period_id)] as $prefix => $model) {
        $session[constant(OperatingContextService::class.'::'.$prefix.'IdKey')] = $model->id;
        $session[constant(OperatingContextService::class.'::'.$prefix.'DocNumKey')] = $model->doc_num;
    }
    session($session);
    $this->withSession($session);
    foreach (['admin.fixed-assets.prints.asset' => $asset, 'admin.fixed-assets.prints.movement' => $asset->movements()->firstOrFail(), 'admin.fixed-assets.prints.disposal' => $asset->disposals()->firstOrFail(), 'admin.fixed-assets.depreciation.print' => $asset->depreciations()->firstOrFail()->run] as $route => $document) {
        $response = $this->get(route($route, $document))->assertOk();
        expect($response->headers->get('Content-Type'))->toContain('application/pdf');
        expect(str_starts_with($response->getContent(), '%PDF-'))->toBeTrue();
    }
    $filters = ['type' => 'register', 'asset_doc_num' => $asset->doc_num];
    $this->get(route('admin.fixed-assets.reports.excel', $filters))->assertOk();
    $this->get(route('admin.fixed-assets.reports.pdf', $filters))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    foreach (app(FixedAssetLedgerService::class)->journals($asset) as $journal) {
        $this->get(route('admin.accounting.journal-entries.show', $journal->doc_num))->assertOk();
    }
    $this->get(route('admin.accounting.accounts.show', $asset->account->doc_num))->assertOk();
});

test('release concurrent retries create exactly one capital addition and journal', function (): void {
    if (DB::getDriverName() !== 'pgsql' || ! str_starts_with(DB::connection()->getDatabaseName(), 'fa_release_copy_')) {
        $this->markTestSkipped('Requires the isolated populated PostgreSQL release copy.');
    }
    $actor = coreFixedAssetActor(['fixed_assets.view', 'fixed_assets.improvement.post', 'fixed_assets.improvement.reverse']);
    request()->setLaravelSession(app('session.store'));
    $asset = FixedAsset::query()->where('asset_name', 'like', 'Lifecycle Asset %')->where('entry_type', 'new_asset')->latest('id')->firstOrFail();
    $session = [];
    foreach (['Company' => $asset->company, 'Branch' => $asset->branch, 'FinancialPeriod' => FinancialPeriod::findOrFail($asset->period_id)] as $prefix => $model) {
        $session[constant(OperatingContextService::class.'::'.$prefix.'IdKey')] = $model->id;
        $session[constant(OperatingContextService::class.'::'.$prefix.'DocNumKey')] = $model->doc_num;
    }
    session($session);
    $this->withSession($session);
    $data = ['submission_key' => (string) Str::uuid(), 'movement_date' => '2026-03-01', 'amount' => '1234', 'counter_account_doc_num' => $asset->creditAccount->doc_num, 'description' => 'Concurrent release retry'];
    $code = 'if (!str_starts_with(DB::connection()->getDatabaseName(), "fa_release_copy_")) { throw new RuntimeException("Unsafe database"); } auth()->loginUsingId('.$actor->id.'); request()->setUserResolver(fn()=>auth()->user()); request()->setLaravelSession(app("session.store")); session('.var_export($session, true).'); $asset=Modules\\FixedAssets\\Models\\FixedAsset::findOrFail('.$asset->id.'); $m=app(Modules\\FixedAssets\\Services\\FixedAssetCostMovementService::class)->addition($asset,'.var_export($data, true).'); echo json_encode(["movement"=>$m->id,"journal"=>$m->journal_entry_id]);';
    $workers = collect([1, 2])->map(fn () => new Process([PHP_BINARY, 'artisan', 'tinker', '--execute', $code], base_path(), timeout: 60));
    foreach ($workers as $worker) {
        $worker->start();
    }
    foreach ($workers as $worker) {
        $worker->wait();
        expect($worker->isSuccessful())->toBeTrue($worker->getErrorOutput().$worker->getOutput());
    }
    $results = $workers->map(fn ($worker) => json_decode(trim($worker->getOutput()), true, flags: JSON_THROW_ON_ERROR));
    expect($results[0])->toBe($results[1]);
    $movements = $asset->costMovements()->where('snapshot->submission_key', $data['submission_key'])->get();
    expect($movements)->toHaveCount(1)->and($movements->first()->journalEntry->is_posted)->toBeTrue();
    app(FixedAssetCostMovementService::class)->reverse($movements->first(), 'End concurrency verification');
});

test('release browser actor is provisioned only on the isolated database copy', function (): void {
    if (DB::getDriverName() !== 'pgsql' || ! str_starts_with(DB::connection()->getDatabaseName(), 'fa_release_copy_')) {
        $this->markTestSkipped('Requires the isolated populated release copy.');
    }
    $credentials = ['username' => 'fa_product_browser_'.strtolower(Str::random(6)), 'password' => Str::random(32)];
    $actor = User::where('username', $credentials['username'])->first()
        ?? coreFixedAssetActor(['fixed_assets.view', 'fixed_assets.create', 'fixed_assets.edit', 'fixed_assets.print', 'fixed_assets.reports', 'fixed_assets.export', 'fixed_assets.depreciation.preview', 'fixed_assets.depreciation.post', 'fixed_assets.improvement.post', 'fixed_assets.custody.post', 'fixed_assets.transfer', 'fixed_assets.dispose', 'fixed_assets.activate', 'fixed_assets.accounting.configure', 'journal_entries.view', 'accounts.view', 'file_manager.view', 'file_manager.download']);
    $actor->forceFill(['username' => $credentials['username'], 'password' => Hash::make($credentials['password'])])->save();
    $destination = getenv('FA_BROWSER_CREDENTIALS');
    expect($destination)->toBeString()->toStartWith('/tmp/fa-release/');
    file_put_contents($destination, json_encode($credentials, JSON_THROW_ON_ERROR));
    chmod($destination, 0600);
    expect($actor->hasRole('admin'))->toBeFalse()->and($actor->can('fixed_assets.view'))->toBeTrue()->and($actor->can('users.delete'))->toBeFalse();
});

test('release UI created assets reconcile with the GL at current and historical dates', function (): void {
    if (DB::getDriverName() !== 'pgsql' || ! str_starts_with(DB::connection()->getDatabaseName(), 'fa_release_copy_')) {
        $this->markTestSkipped('Requires the isolated populated release copy and completed browser journeys.');
    }
    DB::beginTransaction();
    try {
        coreFixedAssetActor(['fixed_assets.view', 'fixed_assets.reports', 'journal_entries.view', 'accounts.view', 'fixed_assets.print', 'fixed_assets.export']);
        request()->setLaravelSession(app('session.store'));
        $newAsset = FixedAsset::where('asset_name', 'أصل تجربة المنتج الجديد')->firstOrFail();
        $opening = FixedAsset::where('asset_name', 'like', 'أصل قائم — مراجعة المنتج %')->latest('id')->firstOrFail();
        $session = [];
        foreach (['Company' => $newAsset->company, 'Branch' => Branch::where('company_id', $newAsset->company_id)->where('doc_num', 'Branch-00001')->firstOrFail(), 'FinancialPeriod' => FinancialPeriod::findOrFail($newAsset->period_id)] as $prefix => $model) {
            $session[constant(OperatingContextService::class.'::'.$prefix.'IdKey')] = $model->id;
            $session[constant(OperatingContextService::class.'::'.$prefix.'DocNumKey')] = $model->doc_num;
        }
        session($session);
        $this->withSession($session);
        expect($newAsset->costMovements()->where('movement_type', 'capitalization')->where('status', 'posted')->count())->toBe(1)
            ->and($opening->costMovements()->where('movement_type', 'opening')->where('status', 'posted')->count())->toBe(1)
            ->and($newAsset->movements()->where('movement_type', 'custody')->count())->toBe(2);
        $disposal = $newAsset->disposals()->latest('id')->firstOrFail();
        expect($disposal->disposal_expenses)->toBe('200.0000')->and($disposal->gain_amount)->toBe('825.4033');
        foreach ([$newAsset, $opening] as $asset) {
            foreach (['2026-01-31', '2026-02-28', '2026-03-04', '2026-03-05', now()->toDateString()] as $date) {
                foreach ([[], ['branch_doc_num' => 'Branch-00001'], ['branch_doc_num' => 'BR-99002'], ['cost_center_doc_num' => 'CC-99001'], ['cost_center_doc_num' => 'CC-99002'], ['financial_period_doc_num' => 'Period-00001']] as $dimensions) {
                    $report = app(FixedAssetReportService::class)->report(['type' => 'reconciliation', 'asset_doc_num' => $asset->doc_num, 'to_date' => $date, ...$dimensions]);
                    if ($dimensions === [] || isset($dimensions['financial_period_doc_num'])) {
                        expect($report['rows'])->not->toBeEmpty();
                    }
                    if ($report['rows']->isEmpty()) {
                        $balance = DB::table('journal_entry_lines')->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
                            ->where('journal_entries.is_posted', true)->whereNull('journal_entries.deleted_at')
                            ->where('journal_entry_lines.account_id', $asset->account_id)->whereDate('journal_entries.entry_date', '<=', $date)
                            ->when(isset($dimensions['branch_doc_num']), fn ($query) => $query->where('journal_entry_lines.branch_id', DB::table('branches')->where('company_id', $asset->company_id)->where('doc_num', $dimensions['branch_doc_num'])->value('id')))
                            ->when(isset($dimensions['cost_center_doc_num']), fn ($query) => $query->where('journal_entry_lines.cost_center_id', DB::table('cost_centers')->where('company_id', $asset->company_id)->where('doc_num', $dimensions['cost_center_doc_num'])->value('id')))
                            ->selectRaw('COALESCE(SUM((debit_amount - credit_amount) * exchange_rate), 0) AS balance')->value('balance');
                        expect(bccomp((string) $balance, '0', 4))->toBe(0, 'An empty dimension must have no asset GL balance.');
                    }
                    foreach ($report['rows'] as $row) {
                        expect(bccomp($row['difference'], '0', 4))->toBe(0, $asset->doc_num.' / '.$date.' / '.json_encode($dimensions).' / '.$row['account']);
                    }
                }
            }
            foreach (app(FixedAssetLedgerService::class)->journals($asset) as $journal) {
                expect($journal->is_posted)->toBeTrue();
                $this->get(route('admin.accounting.journal-entries.show', $journal))->assertOk();
            }
            $this->get(route('admin.fixed-assets.prints.asset', $asset))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        }
        $history = app(FixedAssetLedgerService::class)->history($newAsset);
        expect($history->where('type', 'depreciation_reversal'))->toHaveCount(1);
        $january = $history->filter(fn ($row) => $row['date']->toDateString() === '2026-01-31')->pluck('type')->all();
        expect($january)->toBe(['depreciation', 'depreciation_reversal', 'depreciation']);
    } finally {
        DB::rollBack();
    }
});

test('prerequisite rollout baselines existing assets without changing populated accounting history', function (): void {
    if (DB::getDriverName() !== 'pgsql' || ! str_starts_with(DB::connection()->getDatabaseName(), 'fa_release_copy_')) {
        $this->markTestSkipped('Requires the isolated populated PostgreSQL copy.');
    }
    $tables = ['fixed_assets', 'accounts', 'fixed_asset_movements', 'fixed_asset_depreciations', 'fixed_asset_disposals', 'journal_entries', 'journal_entry_lines', 'opening_balances', 'opening_balance_lines'];
    $columns = [];
    $before = [];
    foreach ($tables as $table) {
        $columns[$table] = array_values(array_diff(Schema::getColumnListing($table), ['legacy_recognition']));
        $before[$table] = hash('sha256', DB::table($table)->orderBy('id')->get($columns[$table])->toJson());
    }
    for ($attempt = 0; $attempt < 2; $attempt++) {
        $this->artisan('migrate', ['--path' => 'modules/FixedAssets/Database/Migrations', '--force' => true, '--no-interaction' => true])->assertExitCode(0);
        foreach ($tables as $table) {
            expect(hash('sha256', DB::table($table)->orderBy('id')->get($columns[$table])->toJson()))->toBe($before[$table], $table);
        }
    }
    expect(FixedAsset::whereNotNull('legacy_recognition')->count())->toBeGreaterThan(0)
        ->and(FixedAsset::where('status', 'draft')->whereNotNull('legacy_recognition')->count())->toBe(0);
    foreach (FixedAsset::whereNotNull('legacy_recognition')->get() as $asset) {
        expect($asset->hasPostedRecognition())->toBeTrue()
            ->and($asset->legacy_recognition['purchase_value'])->toBe($asset->purchase_value)
            ->and($asset->legacy_recognition['previous_depreciation'])->toBe($asset->previous_depreciation)
            ->and($asset->legacy_recognition['previous_depreciation_until_date'])->toBe($asset->previous_depreciation_until_date?->toDateString());
    }
});

test('prerequisite browser fixtures isolate a correctly classified chart without overrides', function (): void {
    if (DB::getDriverName() !== 'pgsql' || ! str_starts_with(DB::connection()->getDatabaseName(), 'fa_release_copy_')) {
        $this->markTestSkipped('Requires the isolated populated PostgreSQL copy.');
    }
    $browserCredentials = json_decode(file_get_contents('/tmp/fa-release/browser-credentials.json'), true);
    $legacyBrowserActor = User::where('username', $browserCredentials['username'])->firstOrFail();
    $legacyBranchIds = FixedAsset::where('company_id', 1)->whereIn('doc_num', ['FA-00003', 'FA-00006', 'FA-00007'])->pluck('branch_id')->unique()->all();
    foreach ($legacyBrowserActor->roles as $role) {
        if ($role->branch_access_restricted) {
            $role->branchAccessBranches()->syncWithoutDetaching($legacyBranchIds);
        }
    }
    $file = '/tmp/fa-release/prerequisites-browser-fixtures.json';
    if (file_exists($file)) {
        expect(json_decode(file_get_contents($file), true)['company'])->toBeString();

        return;
    }
    DB::transaction(function () use ($file): void {
        $source = FixedAsset::where('company_id', 1)->where('doc_num', 'FA-00018')->firstOrFail();
        $company = Company::factory()->create(['doc_num' => 'CO-99030', 'doc_number' => 99030, 'name' => 'FA Classified Chart Acceptance', 'status' => 'active', 'is_main' => false]);
        $branch = $source->branch->replicate(['doc_num', 'doc_number']);
        $branch->fill(['company_id' => $company->id, 'doc_num' => 'BR-99030', 'doc_number' => 99030, 'name' => 'FA Acceptance Branch'])->save();
        $period = FinancialPeriod::findOrFail($source->period_id)->replicate(['doc_num', 'doc_number']);
        $period->fill(['company_id' => $company->id, 'doc_num' => 'FP-99030', 'doc_number' => 99030, 'branch_id' => $branch->id, 'allows_opening_entries' => true, 'is_closed' => false])->save();
        $currency = $source->currency->replicate(['doc_num', 'doc_number']);
        $currency->fill(['company_id' => $company->id, 'doc_num' => 'CUR-99030', 'doc_number' => 99030, 'is_main' => true])->save();
        $copies = [];
        $copyAccount = function (Account $original) use (&$copyAccount, &$copies, $company): Account {
            if (isset($copies[$original->id])) {
                return $copies[$original->id];
            }
            $parent = $original->parent_id ? $copyAccount($original->parent) : null;
            $copy = $original->replicate();
            $copy->fill(['company_id' => $company->id, 'parent_id' => $parent?->id])->save();

            return $copies[$original->id] = $copy;
        };
        $category = $copyAccount($source->assetGroupAccount);
        $cash = $copyAccount($source->creditAccount);
        $equity = $copyAccount(Account::where('company_id', 1)->where('account_code', '31')->firstOrFail());
        $accumulated = $copyAccount(Account::where('company_id', 1)->where('account_code', '122')->firstOrFail());
        $expense = $copyAccount(Account::where('company_id', 1)->where('account_code', '98001')->firstOrFail());
        $expense->update(['parent_id' => $copyAccount(Account::where('company_id', 1)->where('account_code', '5')->firstOrFail())->id]);
        $credentials = json_decode(file_get_contents('/tmp/fa-release/browser-credentials.json'), true);
        $browserActor = User::where('username', $credentials['username'])->firstOrFail();
        foreach ($browserActor->roles as $role) {
            if ($role->branch_access_restricted) {
                $role->branchAccessBranches()->syncWithoutDetaching([$branch->id]);
            }
            if ($role->company_access_restricted) {
                $role->companyAccessCompanies()->syncWithoutDetaching([$company->id]);
            }
        }
        expect(FixedAssetCategoryMapping::where('company_id', $company->id)->count())->toBe(0)
            ->and(JournalEntry::where('company_id', $company->id)->count())->toBe(0);
        file_put_contents($file, json_encode(['company' => $company->doc_num, 'company_id' => $company->id, 'branch' => $branch->doc_num, 'period' => $period->doc_num, 'currency' => $currency->doc_num, 'category' => $category->doc_num, 'category_label' => $category->codeNameLabel(), 'cash_label' => $cash->codeNameLabel(), 'equity_label' => $equity->codeNameLabel(), 'accumulated' => $accumulated->id, 'expense' => $expense->id], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    });
});

test('prerequisite browser postings reconcile without overrides or legacy exemptions for new assets', function (): void {
    if (DB::getDriverName() !== 'pgsql' || ! str_starts_with(DB::connection()->getDatabaseName(), 'fa_release_copy_')) {
        $this->markTestSkipped('Requires the isolated populated PostgreSQL copy.');
    }
    $fixture = json_decode(file_get_contents('/tmp/fa-release/prerequisites-browser-fixtures.json'), true);
    $result = json_decode(file_get_contents('/tmp/fa-release/prerequisites-visual/results.json'), true);
    DB::beginTransaction();
    try {
        coreFixedAssetActor(['fixed_assets.view', 'fixed_assets.reports']);
        $asset = FixedAsset::where('company_id', $fixture['company_id'])->where('doc_num', $result['new_asset'])->firstOrFail();
        $opening = FixedAsset::where('company_id', $fixture['company_id'])->where('doc_num', $result['opening_asset'])->firstOrFail();
        $session = [];
        foreach (['Company' => $asset->company, 'Branch' => $asset->branch, 'FinancialPeriod' => FinancialPeriod::findOrFail($asset->period_id)] as $prefix => $model) {
            $session[constant(OperatingContextService::class.'::'.$prefix.'IdKey')] = $model->id;
            $session[constant(OperatingContextService::class.'::'.$prefix.'DocNumKey')] = $model->doc_num;
        }
        request()->setLaravelSession(app('session.store'));
        session($session);
        $this->withSession($session);
        expect($asset->hasLegacyRecognition())->toBeFalse()->and($opening->hasLegacyRecognition())->toBeFalse()
            ->and($asset->hasPostedRecognition())->toBeTrue()->and($opening->hasPostedRecognition())->toBeTrue()
            ->and(FixedAssetCategoryMapping::where('company_id', $asset->company_id)->count())->toBe(0)
            ->and(JournalEntry::where('company_id', $asset->company_id)->count())->toBe(3)
            ->and($asset->disposals()->count())->toBe(0)->and($opening->previous_depreciation)->toBe('2000.0000')
            ->and($opening->previous_depreciation_until_date->toDateString())->toBe('2025-12-31');
        foreach (['2026-01-01', '2026-01-31', now()->toDateString()] as $date) {
            $report = app(FixedAssetReportService::class)->report(['type' => 'reconciliation', 'to_date' => $date]);
            expect($report['rows'])->not->toBeEmpty();
            foreach ($report['rows'] as $row) {
                expect(bccomp($row['difference'], '0', 4))->toBe(0);
            }
        }
    } finally {
        DB::rollBack();
    }
});

test('usability browser employee fixtures stay in the isolated populated copy', function (): void {
    if (DB::getDriverName() !== 'pgsql' || ! str_starts_with(DB::connection()->getDatabaseName(), 'fa_release_copy_')) {
        $this->markTestSkipped('Requires the isolated release copy.');
    }
    $asset = FixedAsset::query()->where('company_id', 1)->where('doc_num', 'FA-00014')->firstOrFail();
    foreach ([1 => 'أحمد اختبار العهدة', 2 => 'منى اختبار العهدة'] as $number => $name) {
        HrEmployee::query()->firstOrCreate(['company_id' => 1, 'doc_num' => 'EMP-9950'.$number], ['branch_id' => $asset->branch_id, 'doc_number' => 99500 + $number, 'full_name' => $name, 'name' => $name, 'status' => 'active']);
    }
    expect(HrEmployee::query()->where('company_id', 1)->whereIn('doc_num', ['EMP-99501', 'EMP-99502'])->count())->toBe(2);
});
