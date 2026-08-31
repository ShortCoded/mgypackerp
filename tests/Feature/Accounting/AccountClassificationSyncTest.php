<?php

use Database\Seeders\DefaultOperatingContextSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\AccountClassification;
use Modules\Accounting\Services\AccountClassificationRegistry;
use Modules\Core\Models\Company;
use Modules\Core\Services\DocumentNumberService;

function seedPreviousAccountClassifications(): array
{
    $registry = app(AccountClassificationRegistry::class);
    $definitions = collect($registry->definitions())->keyBy('code');

    foreach ($registry->originalCodes() as $code) {
        $definition = $definitions->get($code);
        $classification = AccountClassification::query()->where('code', $code)->first();

        if ($classification instanceof AccountClassification) {
            $classification->forceFill([
                ...$definition,
                ...$registry->previousSystemLabels($code),
            ])->save();

            continue;
        }

        AccountClassification::query()->create([
            ...app(DocumentNumberService::class)->next('account_classifications', AccountClassification::class),
            ...$definition,
            ...$registry->previousSystemLabels($code),
        ]);
    }

    return AccountClassification::query()
        ->whereIn('code', $registry->originalCodes())
        ->pluck('id', 'code')
        ->all();
}

function accountingTableSnapshots(): array
{
    return collect([
        'accounts',
        'journal_entries',
        'journal_entry_lines',
        'account_opening_balances',
        'opening_balances',
        'opening_balance_lines',
    ])->filter(fn (string $table): bool => Schema::hasTable($table))
        ->mapWithKeys(function (string $table): array {
            $snapshot = DB::table($table)
                ->selectRaw('COUNT(*) as row_count, COALESCE(SUM(id), 0) as id_checksum')
                ->first();

            return [$table => [(int) $snapshot->row_count, (int) $snapshot->id_checksum]];
        })
        ->all();
}

function protectedClassificationSnapshots(array $codes): array
{
    return AccountClassification::withTrashed()
        ->whereIn('code', $codes)
        ->orderBy('code')
        ->get([
            'id',
            'doc_number',
            'doc_num',
            'code',
            'account_type',
            'statement_type',
            'normal_balance',
            'is_system',
            'status',
            'notes',
            'created_by',
            'updated_by',
            'deleted_by',
            'restored_by',
            'restored_at',
            'created_at',
            'updated_at',
            'deleted_at',
        ])
        ->keyBy('code')
        ->toArray();
}

test('canonical registry exactly matches the normalized 135 label dictionary', function (): void {
    $registry = app(AccountClassificationRegistry::class);
    $definitions = collect($registry->definitions())->keyBy('code')->sortKeys();
    $labelDigest = hash('sha256', $definitions
        ->map(fn (array $definition, string $code): string => implode("\0", [$code, $definition['name'], $definition['name_en']]))
        ->implode("\n"));

    expect($definitions)->toHaveCount(135)
        ->and($definitions->keys()->unique())->toHaveCount(135)
        ->and($registry->originalCodes())->toHaveCount(24)
        ->and($registry->addedCodes())->toHaveCount(111)
        ->and($definitions->pluck('name')->filter(fn (string $name): bool => trim($name) !== ''))->toHaveCount(135)
        ->and($definitions->pluck('name_en')->filter(fn (string $name): bool => trim($name) !== ''))->toHaveCount(135)
        ->and($definitions->pluck('name')->unique())->toHaveCount(135)
        ->and($definitions->pluck('name_en')->unique())->toHaveCount(135)
        ->and($definitions->where('code', AccountClassification::FixedAssets))->toHaveCount(1)
        ->and($definitions->get(AccountClassification::FixedAssets))->toMatchArray([
            'name' => 'الأصول الثابتة',
            'name_en' => 'Fixed Assets',
            'account_type' => Account::TypeAsset,
            'statement_type' => Account::StatementFinancialPosition,
            'normal_balance' => Account::BalanceDebit,
            'is_system' => true,
            'status' => 'active',
        ])
        ->and($definitions->keys()->all())->toContain(
            'machinery_equipment',
            'molds_tooling',
            'vehicles',
            'it_office_equipment',
            'furniture_fixtures',
            'land',
            'buildings',
            'electrical_equipment',
        )
        ->and($definitions->get('semi_finished_goods_inventory'))->toMatchArray([
            'name' => 'مخزون منتجات نصف مصنعة',
            'name_en' => 'Semi-finished Goods Inventory',
            'account_type' => Account::TypeAsset,
            'statement_type' => Account::StatementFinancialPosition,
            'normal_balance' => Account::BalanceDebit,
        ])->and($definitions->get('indirect_labor_cost'))->toMatchArray([
            'name' => 'تكلفة العمالة الصناعية غير المباشرة',
            'name_en' => 'Indirect Manufacturing Labor Cost',
            'account_type' => Account::TypeExpense,
            'statement_type' => Account::StatementIncomeStatement,
            'normal_balance' => Account::BalanceDebit,
        ])
        ->and($labelDigest)->toBe('504ace034305677ebeee864a9a28cff24ab1c6d6c500d8eadf50766180502059');
});

test('canonical labels do not alter classification accounting metadata', function (): void {
    $registry = app(AccountClassificationRegistry::class);
    $reflection = new ReflectionMethod($registry, 'previousDefinitions');
    $previousDefinitions = collect($reflection->invoke($registry))->keyBy('code');
    $canonicalDefinitions = collect($registry->definitions())->keyBy('code');
    $protectedFields = ['account_type', 'statement_type', 'normal_balance', 'is_system', 'status'];

    expect($canonicalDefinitions->keys()->all())->toBe($previousDefinitions->keys()->all());

    foreach ($canonicalDefinitions as $code => $definition) {
        expect(collect($definition)->only($protectedFields)->all())
            ->toBe(collect($previousDefinitions->get($code))->only($protectedFields)->all());
    }
});

test('dry run reports exact label changes and performs no writes', function (): void {
    seedPreviousAccountClassifications();
    $before = AccountClassification::withTrashed()->orderBy('id')->get()->toArray();
    $protectedTablesBefore = accountingTableSnapshots();

    $this->artisan('account-classifications:sync', ['--dry-run' => true])
        ->expectsOutputToContain('inserted=111, unchanged=1, label_updates=23, label_conflicts=0, deleted=0')
        ->expectsOutputToContain('Dry run complete; no database writes were performed.')
        ->assertSuccessful();

    expect(AccountClassification::withTrashed()->orderBy('id')->get()->toArray())->toBe($before)
        ->and(accountingTableSnapshots())->toBe($protectedTablesBefore);
});

test('label comparison treats each bilingual pair atomically and never overwrites conflicts', function (): void {
    seedPreviousAccountClassifications();
    $registry = app(AccountClassificationRegistry::class);
    $definitions = collect($registry->definitions())->keyBy('code');

    AccountClassification::query()->where('code', 'cash')->update(['name' => 'تسمية مخصصة']);
    AccountClassification::query()->where('code', 'bank')->update(['name_en' => 'Custom Bank Label']);
    AccountClassification::query()->where('code', 'accounts_receivable')->update([
        'name' => $definitions->get('accounts_receivable')['name'],
    ]);
    AccountClassification::query()->where('code', 'inventory')->update(['name_en' => '']);

    $before = AccountClassification::withTrashed()->orderBy('id')->get()->toArray();
    $plan = $registry->plan();

    expect($plan)->toMatchArray([
        'inserted' => 111,
        'unchanged' => 1,
        'label_updates' => 19,
        'label_conflicts' => 4,
        'deleted' => 0,
    ])->and($plan['label_conflict_codes'])->toBe([
        'cash',
        'bank',
        'accounts_receivable',
        'inventory',
    ]);

    $this->artisan('account-classifications:sync', ['--dry-run' => true])
        ->expectsOutputToContain('inserted=111, unchanged=1, label_updates=19, label_conflicts=4, deleted=0')
        ->expectsOutputToContain('label_conflict_codes=cash,bank,accounts_receivable,inventory')
        ->assertFailed();

    expect(fn () => $registry->synchronize())
        ->toThrow(DomainException::class, 'Account classification conflicts must be resolved before synchronization.');

    expect(AccountClassification::withTrashed()->orderBy('id')->get()->toArray())->toBe($before)
        ->and(AccountClassification::query()->count())->toBe(24);
});

test('service synchronization inserts missing rows and changes only safe labels in the test database', function (): void {
    $originalIds = seedPreviousAccountClassifications();
    $registry = app(AccountClassificationRegistry::class);
    $protectedClassificationsBefore = protectedClassificationSnapshots($registry->originalCodes());
    $protectedTablesBefore = accountingTableSnapshots();

    $result = $registry->synchronize();

    expect($result)->toMatchArray([
        'inserted' => 111,
        'unchanged' => 1,
        'label_updates' => 23,
        'label_conflicts' => 0,
        'deleted' => 0,
    ])->and(AccountClassification::query()->count())->toBe(135)
        ->and(AccountClassification::query()->distinct()->count('code'))->toBe(135)
        ->and(protectedClassificationSnapshots($registry->originalCodes()))->toBe($protectedClassificationsBefore)
        ->and(accountingTableSnapshots())->toBe($protectedTablesBefore);

    foreach ($originalIds as $code => $id) {
        $definition = $registry->definition($code);
        $classification = AccountClassification::query()->where('code', $code)->firstOrFail();

        expect($classification->getKey())->toBe($id)
            ->and($classification->name)->toBe($definition['name'])
            ->and($classification->name_en)->toBe($definition['name_en']);
    }

    expect($registry->synchronize())->toMatchArray([
        'inserted' => 0,
        'unchanged' => 135,
        'label_updates' => 0,
        'label_conflicts' => 0,
        'deleted' => 0,
    ])->and($registry->verification()['valid'])->toBeTrue()
        ->and(accountingTableSnapshots())->toBe($protectedTablesBefore);

    $this->artisan('account-classifications:verify')
        ->expectsOutputToContain('current=135, current_with_trashed=135, canonical=135')
        ->expectsOutputToContain('label_update_codes=0')
        ->expectsOutputToContain('label_conflict_codes=0')
        ->assertSuccessful();
});

test('fixed assets synchronization preserves its row and every existing account reference without duplicates', function (): void {
    $this->seed(DefaultOperatingContextSeeder::class);
    seedPreviousAccountClassifications();

    $registry = app(AccountClassificationRegistry::class);
    $company = Company::query()->orderBy('id')->firstOrFail();
    $classification = AccountClassification::query()
        ->where('code', AccountClassification::FixedAssets)
        ->firstOrFail();
    $classificationId = (int) $classification->getKey();
    $account = Account::query()->create([
        ...app(DocumentNumberService::class)->nextForCompany('accounts', Account::class, (int) $company->getKey()),
        'company_id' => $company->getKey(),
        'account_code' => '121-regression-reference',
        'name' => 'Fixed Assets Reference',
        'parent_id' => null,
        'level' => 1,
        'account_classification_id' => $classificationId,
        'account_type' => Account::TypeAsset,
        'statement_type' => Account::StatementFinancialPosition,
        'normal_balance' => Account::BalanceDebit,
        'is_group' => true,
        'is_postable' => false,
        'status' => 'active',
    ]);

    $registry->synchronize();
    $registry->synchronize();

    $synchronizedClassification = AccountClassification::query()
        ->where('code', AccountClassification::FixedAssets)
        ->firstOrFail();

    expect(AccountClassification::query()->where('code', AccountClassification::FixedAssets)->count())->toBe(1)
        ->and((int) $synchronizedClassification->getKey())->toBe($classificationId)
        ->and((int) $account->fresh()->account_classification_id)->toBe($classificationId)
        ->and($synchronizedClassification->only(['name', 'name_en', 'status']))->toBe([
            'name' => 'الأصول الثابتة',
            'name_en' => 'Fixed Assets',
            'status' => 'active',
        ]);
});
