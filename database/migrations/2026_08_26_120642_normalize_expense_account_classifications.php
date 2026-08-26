<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\AccountClassification;
use Modules\Core\Models\Company;
use Modules\Core\Services\DocumentNumberService;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('account_classifications')) {
            return;
        }

        DB::transaction(function (): void {
            $this->synchronizePostgreSqlSequence('account_classifications', 'id');

            $classification = AccountClassification::query()
                ->where('code', AccountClassification::Expenses)
                ->first();

            if (! $classification instanceof AccountClassification) {
                $classification = AccountClassification::onlyTrashed()
                    ->where('code', AccountClassification::Expenses)
                    ->orderBy('id')
                    ->first();

                if ($classification instanceof AccountClassification && $this->canRestoreClassification($classification)) {
                    $classification->restore();
                } else {
                    $classification = null;
                }
            }

            if (! $classification instanceof AccountClassification) {
                $classification = AccountClassification::query()->create([
                    ...app(DocumentNumberService::class)->next('account_classifications', AccountClassification::class),
                    'code' => AccountClassification::Expenses,
                    'name' => 'مصروفات',
                    'name_en' => 'Expenses',
                    'account_type' => Account::TypeExpense,
                    'statement_type' => Account::StatementIncomeStatement,
                    'normal_balance' => Account::BalanceDebit,
                    'is_system' => true,
                    'status' => 'active',
                ]);
            } else {
                $classification->forceFill([
                    'name' => 'مصروفات',
                    'name_en' => 'Expenses',
                    'account_type' => Account::TypeExpense,
                    'statement_type' => Account::StatementIncomeStatement,
                    'normal_balance' => Account::BalanceDebit,
                    'is_system' => true,
                    'status' => 'active',
                ])->save();
            }

            Company::query()->orderBy('id')->pluck('id')->each(function (int $companyId) use ($classification): void {
                $root = Account::query()
                    ->where('company_id', $companyId)
                    ->whereNull('parent_id')
                    ->where('account_code', '5')
                    ->first();

                if (! $root instanceof Account) {
                    return;
                }

                $accountIds = [(int) $root->getKey()];
                $parentIds = $accountIds;

                while ($parentIds !== []) {
                    $childIds = Account::query()
                        ->where('company_id', $companyId)
                        ->whereIn('parent_id', $parentIds)
                        ->pluck('id')
                        ->map(fn (int|string $id): int => (int) $id)
                        ->all();
                    $parentIds = array_values(array_diff($childIds, $accountIds));
                    $accountIds = [...$accountIds, ...$parentIds];
                }

                Account::query()
                    ->where('company_id', $companyId)
                    ->whereIn('id', $accountIds)
                    ->update(['account_classification_id' => $classification->getKey()]);
            });
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // The prior per-expense classifications cannot be reconstructed reliably.
    }

    private function synchronizePostgreSqlSequence(string $table, string $column): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        DB::statement(sprintf('LOCK TABLE %s IN SHARE ROW EXCLUSIVE MODE', DB::getQueryGrammar()->wrapTable($table)));

        $sequence = DB::selectOne(
            'SELECT pg_get_serial_sequence(?, ?) AS sequence_name',
            [$table, $column],
        )?->sequence_name;
        $maximumId = DB::table($table)->max($column);

        if (! is_string($sequence) || $sequence === '' || $maximumId === null) {
            return;
        }

        $sequenceValue = DB::selectOne(
            <<<'SQL'
                SELECT sequences.last_value
                FROM pg_catalog.pg_sequences AS sequences
                INNER JOIN pg_catalog.pg_namespace AS namespaces
                    ON namespaces.nspname = sequences.schemaname
                INNER JOIN pg_catalog.pg_class AS classes
                    ON classes.relnamespace = namespaces.oid
                    AND classes.relname = sequences.sequencename
                WHERE classes.oid = ?::regclass
                SQL,
            [$sequence],
        )?->last_value;
        $safeSequenceValue = max((int) $maximumId, (int) ($sequenceValue ?? 0));

        DB::selectOne(
            'SELECT setval(?::regclass, ?::bigint, true) AS sequence_value',
            [$sequence, $safeSequenceValue],
        );
    }

    private function canRestoreClassification(AccountClassification $classification): bool
    {
        return ! AccountClassification::query()
            ->where(function ($query) use ($classification): void {
                $query->where('code', $classification->code);

                if ($classification->doc_num !== null) {
                    $query->orWhere('doc_num', $classification->doc_num);
                }

                if ($classification->doc_number !== null) {
                    $query->orWhere('doc_number', $classification->doc_number);
                }
            })
            ->exists();
    }
};
