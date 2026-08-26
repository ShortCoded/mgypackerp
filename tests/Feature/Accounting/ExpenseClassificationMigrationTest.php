<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\AccountClassification;
use Modules\Core\Models\Company;

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('This migration regression requires PostgreSQL sequence semantics.');
    }
});

function expenseClassificationMigration(): Migration
{
    return require database_path('migrations/2026_08_26_120642_normalize_expense_account_classifications.php');
}

function expenseClassificationSequenceName(): string
{
    $sequence = DB::selectOne(
        'SELECT pg_get_serial_sequence(?, ?) AS sequence_name',
        ['account_classifications', 'id'],
    )?->sequence_name;

    expect($sequence)->toBeString()->not->toBeEmpty();

    return $sequence;
}

/**
 * @return array{last_value: int, is_called: bool}
 */
function expenseClassificationSequenceState(): array
{
    $sequence = DB::getQueryGrammar()->wrapTable(expenseClassificationSequenceName());
    $state = DB::selectOne("SELECT last_value, is_called FROM {$sequence}");

    return [
        'last_value' => (int) $state->last_value,
        'is_called' => (bool) $state->is_called,
    ];
}

function setExpenseClassificationSequence(int $value, bool $isCalled): void
{
    $isCalledSql = $isCalled ? 'true' : 'false';

    DB::selectOne(
        "SELECT setval(?::regclass, ?::bigint, {$isCalledSql}) AS sequence_value",
        [expenseClassificationSequenceName(), $value],
    );
}

function restoreExpenseClassificationSequence(): void
{
    $maximumId = (int) DB::table('account_classifications')->max('id');

    if ($maximumId > 0) {
        DB::selectOne(
            'SELECT setval(?::regclass, ?::bigint, true) AS sequence_value',
            [expenseClassificationSequenceName(), $maximumId],
        );
    }
}

function insertExpenseClassificationFixtures(int $firstId, int $lastId): void
{
    $timestamp = now();
    $rows = [];

    foreach (range($firstId, $lastId) as $id) {
        $rows[] = [
            'id' => $id,
            'doc_number' => $id,
            'doc_num' => "SEQUENCE-CLASS-{$id}",
            'code' => "preserved_classification_{$id}",
            'name' => "Preserved Classification {$id}",
            'name_en' => "Preserved Classification {$id}",
            'account_type' => Account::TypeExpense,
            'statement_type' => Account::StatementIncomeStatement,
            'normal_balance' => Account::BalanceDebit,
            'is_system' => false,
            'status' => 'active',
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ];
    }

    DB::table('account_classifications')->insert($rows);
}

function createExpenseMigrationAccount(
    Company $company,
    int $documentNumber,
    string $accountCode,
    int $classificationId,
    ?Account $parent = null,
): Account {
    return Account::query()->create([
        'doc_number' => $documentNumber,
        'doc_num' => "SEQUENCE-ACCOUNT-{$documentNumber}",
        'company_id' => $company->getKey(),
        'account_code' => $accountCode,
        'name' => "Expense Account {$accountCode}",
        'name_en' => "Expense Account {$accountCode}",
        'parent_id' => $parent?->getKey(),
        'level' => $parent instanceof Account ? (int) $parent->level + 1 : 1,
        'account_classification_id' => $classificationId,
        'account_type' => Account::TypeExpense,
        'statement_type' => Account::StatementIncomeStatement,
        'normal_balance' => Account::BalanceDebit,
        'is_group' => true,
        'is_postable' => false,
        'status' => 'active',
    ]);
}

test('expense classification migration repairs a stale PostgreSQL sequence and preserves existing records', function (): void {
    AccountClassification::query()->where('code', AccountClassification::Expenses)->firstOrFail()->forceDelete();
    insertExpenseClassificationFixtures(1, 24);

    $company = Company::query()->create([
        'doc_number' => 93001,
        'doc_num' => 'SEQUENCE-COMPANY-93001',
        'name' => 'Expense Sequence Company',
        'status' => 'active',
    ]);
    $root = createExpenseMigrationAccount($company, 93101, '5', 1);
    $child = createExpenseMigrationAccount($company, 93102, '51', 2, $root);
    $grandchild = createExpenseMigrationAccount($company, 93103, '511', 3, $child);
    $outside = createExpenseMigrationAccount($company, 93104, '4', 4);
    $preservedClassifications = DB::table('account_classifications')->orderBy('id')->pluck('code', 'id')->all();
    $accountIds = [$root->getKey(), $child->getKey(), $grandchild->getKey(), $outside->getKey()];

    try {
        setExpenseClassificationSequence(1, false);

        expect(expenseClassificationSequenceState())->toBe(['last_value' => 1, 'is_called' => false]);

        $migration = expenseClassificationMigration();
        $migration->up();
        $migration->up();

        $expenses = AccountClassification::query()->where('code', AccountClassification::Expenses)->firstOrFail();

        expect((int) $expenses->getKey())->toBe(25)
            ->and(AccountClassification::query()->count())->toBe(25)
            ->and(DB::table('account_classifications')->whereIn('id', range(1, 24))->orderBy('id')->pluck('code', 'id')->all())->toBe($preservedClassifications)
            ->and(expenseClassificationSequenceState())->toBe(['last_value' => 25, 'is_called' => true])
            ->and(Account::query()->whereIn('id', $accountIds)->orderBy('id')->pluck('id')->all())->toBe($accountIds)
            ->and((int) $root->fresh()->account_classification_id)->toBe(25)
            ->and((int) $child->fresh()->account_classification_id)->toBe(25)
            ->and((int) $grandchild->fresh()->account_classification_id)->toBe(25)
            ->and((int) $outside->fresh()->account_classification_id)->toBe(4);
    } finally {
        restoreExpenseClassificationSequence();
    }
});

test('expense classification migration reuses the existing canonical code without inserting a duplicate', function (): void {
    $expenses = AccountClassification::query()->where('code', AccountClassification::Expenses)->firstOrFail();
    $expenses->forceFill([
        'name' => 'Old Expense Name',
        'name_en' => null,
        'account_type' => Account::TypeAsset,
        'statement_type' => Account::StatementFinancialPosition,
        'normal_balance' => Account::BalanceCredit,
        'is_system' => false,
        'status' => 'inactive',
    ])->save();
    insertExpenseClassificationFixtures(2, 24);

    try {
        setExpenseClassificationSequence(1, false);
        expenseClassificationMigration()->up();

        $reused = AccountClassification::query()->where('code', AccountClassification::Expenses)->firstOrFail();

        expect((int) $reused->getKey())->toBe((int) $expenses->getKey())
            ->and(AccountClassification::query()->where('code', AccountClassification::Expenses)->count())->toBe(1)
            ->and(AccountClassification::query()->count())->toBe(24)
            ->and($reused->name)->toBe('مصروفات')
            ->and($reused->name_en)->toBe('Expenses')
            ->and($reused->account_type)->toBe(Account::TypeExpense)
            ->and($reused->statement_type)->toBe(Account::StatementIncomeStatement)
            ->and($reused->normal_balance)->toBe(Account::BalanceDebit)
            ->and($reused->is_system)->toBeTrue()
            ->and($reused->status)->toBe('active')
            ->and(expenseClassificationSequenceState())->toBe(['last_value' => 24, 'is_called' => true]);
    } finally {
        restoreExpenseClassificationSequence();
    }
});
