<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\AccountClassification;
use Modules\Accounting\Services\AccountClassificationRegistry;
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
        ->and($labelDigest)->toBe('1970d4d298b09d843d7112504053b5db8ec869d4a1c6101a4b755a435ef03f0c');
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
