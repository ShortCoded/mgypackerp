<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\AccountClassification;
use Modules\Core\Models\Company;
use Tests\TestCase;

uses(TestCase::class, DatabaseTransactions::class);

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Requires the dedicated disposable PostgreSQL migration acceptance database.');
    }

    $database = (string) DB::selectOne('select current_database() as name')->name;
    if ($database !== 'mgypack_acceptance_receipt_20261001') {
        $this->markTestSkipped('Requires the dedicated disposable PostgreSQL migration acceptance database.');
    }

    expect(AccountClassification::query()->count())->toBe(1)
        ->and(AccountClassification::query()->where('code', AccountClassification::Expenses)->count())->toBe(1)
        ->and(Account::query()->count())->toBe(0);
});

function postgresExpenseMigration(): Migration
{
    return require database_path('migrations/2026_08_26_120642_normalize_expense_account_classifications.php');
}

function postgresExpenseSequence(): string
{
    $name = DB::selectOne('select pg_get_serial_sequence(?, ?) as name', ['account_classifications', 'id'])?->name;
    expect($name)->toBeString()->not->toBeEmpty();

    return $name;
}

/** @return array{last_value: int, is_called: bool} */
function postgresExpenseSequenceState(): array
{
    $name = DB::getQueryGrammar()->wrapTable(postgresExpenseSequence());
    $row = DB::selectOne("select last_value, is_called from {$name}");

    return ['last_value' => (int) $row->last_value, 'is_called' => (bool) $row->is_called];
}

function postgresSetExpenseSequence(int $value, bool $isCalled): void
{
    $called = $isCalled ? 'true' : 'false';
    DB::selectOne("select setval(?::regclass, ?::bigint, {$called}) as value", [postgresExpenseSequence(), $value]);
}

function postgresInsertExpenseClassification(int $id): void
{
    DB::table('account_classifications')->insert([
        'id' => $id,
        'doc_number' => $id,
        'doc_num' => "PG-SEQUENCE-CLASS-{$id}",
        'code' => "pg_preserved_classification_{$id}",
        'name' => "Preserved {$id}",
        'name_en' => "Preserved {$id}",
        'account_type' => Account::TypeExpense,
        'statement_type' => Account::StatementIncomeStatement,
        'normal_balance' => Account::BalanceDebit,
        'is_system' => false,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function postgresExpenseAccount(Company $company, int $number, string $code, int $classificationId, ?Account $parent = null): Account
{
    return Account::query()->create([
        'doc_number' => $number,
        'doc_num' => "PG-SEQUENCE-ACCOUNT-{$number}",
        'company_id' => $company->getKey(),
        'account_code' => $code,
        'name' => "Expense {$code}",
        'name_en' => "Expense {$code}",
        'parent_id' => $parent?->getKey(),
        'level' => $parent ? (int) $parent->level + 1 : 1,
        'account_classification_id' => $classificationId,
        'account_type' => Account::TypeExpense,
        'statement_type' => Account::StatementIncomeStatement,
        'normal_balance' => Account::BalanceDebit,
        'is_group' => true,
        'is_postable' => false,
        'status' => 'active',
    ]);
}

test('postgres migration repairs a stale classification sequence and reclassifies only the expense tree', function (): void {
    $originalSequence = postgresExpenseSequenceState();

    try {
        AccountClassification::query()->where('code', AccountClassification::Expenses)->firstOrFail()->forceDelete();
        foreach (range(1, 4) as $id) {
            postgresInsertExpenseClassification($id);
        }

        $company = Company::query()->create([
            'doc_number' => 993001,
            'doc_num' => 'PG-SEQUENCE-COMPANY-993001',
            'name' => 'Synthetic sequence company',
            'status' => 'active',
        ]);
        $root = postgresExpenseAccount($company, 993101, '5', 1);
        $child = postgresExpenseAccount($company, 993102, '51', 2, $root);
        $grandchild = postgresExpenseAccount($company, 993103, '511', 3, $child);
        $outside = postgresExpenseAccount($company, 993104, '4', 4);
        $preserved = DB::table('account_classifications')->whereIn('id', range(1, 4))->orderBy('id')->pluck('code', 'id')->all();

        postgresSetExpenseSequence(1, false);
        $migration = postgresExpenseMigration();
        $migration->up();
        $migration->up();

        $expenses = AccountClassification::query()->where('code', AccountClassification::Expenses)->sole();
        expect((int) $expenses->getKey())->toBe(5)
            ->and(AccountClassification::query()->count())->toBe(5)
            ->and(DB::table('account_classifications')->whereIn('id', range(1, 4))->orderBy('id')->pluck('code', 'id')->all())->toBe($preserved)
            ->and(postgresExpenseSequenceState())->toBe(['last_value' => 5, 'is_called' => true])
            ->and((int) $root->fresh()->account_classification_id)->toBe(5)
            ->and((int) $child->fresh()->account_classification_id)->toBe(5)
            ->and((int) $grandchild->fresh()->account_classification_id)->toBe(5)
            ->and((int) $outside->fresh()->account_classification_id)->toBe(4);
    } finally {
        postgresSetExpenseSequence($originalSequence['last_value'], $originalSequence['is_called']);
    }
});

test('postgres migration reuses and repairs the existing expense classification', function (): void {
    $originalSequence = postgresExpenseSequenceState();

    try {
        $expenses = AccountClassification::query()->where('code', AccountClassification::Expenses)->firstOrFail();
        $expenses->forceFill([
            'name' => 'Old name',
            'name_en' => null,
            'account_type' => Account::TypeAsset,
            'statement_type' => Account::StatementFinancialPosition,
            'normal_balance' => Account::BalanceCredit,
            'is_system' => false,
            'status' => 'inactive',
        ])->save();
        foreach (range(2, 4) as $id) {
            postgresInsertExpenseClassification($id);
        }

        postgresSetExpenseSequence(1, false);
        $migration = postgresExpenseMigration();
        $migration->up();
        $migration->up();

        $reused = AccountClassification::query()->where('code', AccountClassification::Expenses)->sole();
        expect((int) $reused->getKey())->toBe((int) $expenses->getKey())
            ->and(AccountClassification::query()->count())->toBe(4)
            ->and($reused->name)->toBe('مصروفات')
            ->and($reused->name_en)->toBe('Expenses')
            ->and($reused->account_type)->toBe(Account::TypeExpense)
            ->and($reused->statement_type)->toBe(Account::StatementIncomeStatement)
            ->and($reused->normal_balance)->toBe(Account::BalanceDebit)
            ->and($reused->is_system)->toBeTrue()
            ->and($reused->status)->toBe('active')
            ->and(postgresExpenseSequenceState())->toBe(['last_value' => 4, 'is_called' => true]);
    } finally {
        postgresSetExpenseSequence($originalSequence['last_value'], $originalSequence['is_called']);
    }
});
