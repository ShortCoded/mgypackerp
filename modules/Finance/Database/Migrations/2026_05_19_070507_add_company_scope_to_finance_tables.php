<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addCompanyColumn('currencies');
        $this->addCompanyColumn('bank_accounts');
        $this->addCompanyColumn('cashboxes');

        $this->backfillCurrencies();
        $this->backfillBankAccounts();
        $this->backfillCashboxes();

        $this->requireCompany('currencies');
        $this->requireCompany('bank_accounts');
        $this->requireCompany('cashboxes');

        $this->dropOldUniqueIndexes();
        $this->createCompanyScopedIndexes();
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            foreach ($this->companyScopedIndexNames() as $index) {
                DB::statement('DROP INDEX IF EXISTS '.DB::getQueryGrammar()->wrap($index));
            }

            $this->createOldUniqueIndexes();
        }

        foreach (['cashboxes', 'bank_accounts', 'currencies'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'company_id')) {
                Schema::table($table, function (Blueprint $table): void {
                    $table->dropConstrainedForeignId('company_id');
                });
            }
        }
    }

    private function addCompanyColumn(string $tableName): void
    {
        if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'company_id')) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table): void {
            $table->foreignId('company_id')->nullable()->after('id')->constrained('companies')->restrictOnDelete();
        });
    }

    private function backfillCurrencies(): void
    {
        if (! Schema::hasTable('currencies') || ! Schema::hasColumn('currencies', 'company_id')) {
            return;
        }

        $defaultCompanyId = $this->defaultCompanyId('currencies');

        if ($defaultCompanyId !== null) {
            DB::table('currencies')->whereNull('company_id')->update(['company_id' => $defaultCompanyId]);
        }
    }

    private function backfillBankAccounts(): void
    {
        if (! Schema::hasTable('bank_accounts') || ! Schema::hasColumn('bank_accounts', 'company_id')) {
            return;
        }

        if (Schema::hasColumn('accounts', 'company_id')) {
            DB::table('bank_accounts')
                ->whereNull('bank_accounts.company_id')
                ->whereNotNull('account_id')
                ->update(['company_id' => DB::raw('(SELECT accounts.company_id FROM accounts WHERE accounts.id = bank_accounts.account_id)')]);

            if (Schema::hasColumn('bank_accounts', 'bank_id')) {
                DB::table('bank_accounts')
                    ->whereNull('bank_accounts.company_id')
                    ->whereNotNull('bank_id')
                    ->update(['company_id' => DB::raw('(SELECT accounts.company_id FROM accounts WHERE accounts.id = bank_accounts.bank_id)')]);
            }
        }

        $defaultCompanyId = $this->defaultCompanyId('bank_accounts');

        if ($defaultCompanyId !== null) {
            DB::table('bank_accounts')->whereNull('company_id')->update(['company_id' => $defaultCompanyId]);
        }
    }

    private function backfillCashboxes(): void
    {
        if (! Schema::hasTable('cashboxes') || ! Schema::hasColumn('cashboxes', 'company_id')) {
            return;
        }

        if (Schema::hasColumn('accounts', 'company_id')) {
            DB::table('cashboxes')
                ->whereNull('cashboxes.company_id')
                ->whereNotNull('account_id')
                ->update(['company_id' => DB::raw('(SELECT accounts.company_id FROM accounts WHERE accounts.id = cashboxes.account_id)')]);
        }

        if (Schema::hasColumn('branches', 'company_id')) {
            DB::table('cashboxes')
                ->whereNull('cashboxes.company_id')
                ->whereNotNull('branch_id')
                ->update(['company_id' => DB::raw('(SELECT branches.company_id FROM branches WHERE branches.id = cashboxes.branch_id)')]);
        }

        $defaultCompanyId = $this->defaultCompanyId('cashboxes');

        if ($defaultCompanyId !== null) {
            DB::table('cashboxes')->whereNull('company_id')->update(['company_id' => $defaultCompanyId]);
        }
    }

    private function requireCompany(string $tableName): void
    {
        if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'company_id')) {
            return;
        }

        if (DB::table($tableName)->whereNull('company_id')->exists()) {
            throw new RuntimeException("Cannot company-scope {$tableName}: existing rows could not be assigned to a company.");
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE '.DB::getQueryGrammar()->wrapTable($tableName).' ALTER COLUMN '.DB::getQueryGrammar()->wrap('company_id').' SET NOT NULL');
        }
    }

    private function defaultCompanyId(string $targetTable): ?int
    {
        $query = DB::table('companies');

        if (Schema::hasColumn('companies', 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        $companyId = $query
            ->when(Schema::hasColumn('companies', 'status'), fn ($query) => $query->where('status', 'active'))
            ->orderBy('id')
            ->value('id')
            ?? DB::table('companies')->orderBy('id')->value('id');

        if ($companyId === null && Schema::hasTable($targetTable) && DB::table($targetTable)->exists()) {
            throw new RuntimeException("Cannot company-scope {$targetTable}: no company exists for existing rows.");
        }

        return $companyId === null ? null : (int) $companyId;
    }

    private function dropOldUniqueIndexes(): void
    {
        if (! in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            return;
        }

        foreach ([
            'currencies_doc_number_unique_active',
            'currencies_doc_num_unique_active',
            'currencies_code_unique_active',
            'currencies_main_unique_active',
            'bank_accounts_doc_number_unique_active',
            'bank_accounts_doc_num_unique_active',
            'bank_accounts_account_id_unique_active',
            'cashboxes_doc_number_unique_active',
            'cashboxes_doc_num_unique_active',
            'cashboxes_account_id_unique_active',
        ] as $index) {
            DB::statement('DROP INDEX IF EXISTS '.DB::getQueryGrammar()->wrap($index));
        }
    }

    private function createCompanyScopedIndexes(): void
    {
        if (! in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            return;
        }

        $this->createCurrencyIndexes();
        $this->createDocumentIndexes('bank_accounts');
        $this->createDocumentIndexes('cashboxes');
        $this->createUniqueActiveIndex('bank_accounts', ['company_id', 'account_id'], 'bank_accounts_company_account_id_unique_active', 'account_id IS NOT NULL');
        $this->createUniqueActiveIndex('bank_accounts', ['company_id', 'account_number'], 'bank_accounts_company_account_number_unique_active', 'account_number IS NOT NULL');
        $this->createUniqueActiveIndex('bank_accounts', ['company_id', 'iban'], 'bank_accounts_company_iban_unique_active', 'iban IS NOT NULL');
        $this->createUniqueActiveIndex('cashboxes', ['company_id', 'account_id'], 'cashboxes_company_account_id_unique_active', 'account_id IS NOT NULL');

        foreach (['currencies', 'bank_accounts', 'cashboxes'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->index('company_id');
                $table->index(['company_id', 'status']);
            });
        }
    }

    private function createCurrencyIndexes(): void
    {
        $this->createDocumentIndexes('currencies');
        $this->createUniqueActiveIndex('currencies', ['company_id', 'code'], 'currencies_company_code_unique_active', 'code IS NOT NULL');

        $grammar = DB::getQueryGrammar();
        $table = $grammar->wrapTable('currencies');
        $trueValue = DB::getDriverName() === 'pgsql' ? 'true' : '1';

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS '.$grammar->wrap('currencies_company_main_unique_active').' ON '.$table.' ('.$grammar->wrap('company_id').') WHERE '.$grammar->wrap('deleted_at').' IS NULL AND '.$grammar->wrap('status')." = 'active' AND ".$grammar->wrap('is_main').' = '.$trueValue);
    }

    private function createDocumentIndexes(string $tableName): void
    {
        $this->createUniqueActiveIndex($tableName, ['company_id', 'doc_number'], "{$tableName}_company_doc_number_unique_active", 'doc_number IS NOT NULL');
        $this->createUniqueActiveIndex($tableName, ['company_id', 'doc_num'], "{$tableName}_company_doc_num_unique_active", 'doc_num IS NOT NULL');
    }

    /**
     * @param  list<string>  $columns
     */
    private function createUniqueActiveIndex(string $tableName, array $columns, string $indexName, ?string $extraPredicate = null): void
    {
        $grammar = DB::getQueryGrammar();
        $wrappedColumns = collect($columns)
            ->map(fn (string $column): string => $grammar->wrap($column))
            ->implode(', ');
        $predicate = $grammar->wrap('deleted_at').' IS NULL';

        if ($extraPredicate !== null) {
            $predicate .= ' AND '.$extraPredicate;
        }

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS '.$grammar->wrap($indexName).' ON '.$grammar->wrapTable($tableName)." ({$wrappedColumns}) WHERE {$predicate}");
    }

    /**
     * @return list<string>
     */
    private function companyScopedIndexNames(): array
    {
        return [
            'currencies_company_doc_number_unique_active',
            'currencies_company_doc_num_unique_active',
            'currencies_company_code_unique_active',
            'currencies_company_main_unique_active',
            'bank_accounts_company_doc_number_unique_active',
            'bank_accounts_company_doc_num_unique_active',
            'bank_accounts_company_account_id_unique_active',
            'bank_accounts_company_account_number_unique_active',
            'bank_accounts_company_iban_unique_active',
            'cashboxes_company_doc_number_unique_active',
            'cashboxes_company_doc_num_unique_active',
            'cashboxes_company_account_id_unique_active',
        ];
    }

    private function createOldUniqueIndexes(): void
    {
        $grammar = DB::getQueryGrammar();
        $deletedAt = $grammar->wrap('deleted_at');

        foreach ([
            'currencies' => ['doc_number', 'doc_num', 'code'],
            'bank_accounts' => ['doc_number', 'doc_num', 'account_id'],
            'cashboxes' => ['doc_number', 'doc_num', 'account_id'],
        ] as $tableName => $columns) {
            $table = $grammar->wrapTable($tableName);

            foreach ($columns as $column) {
                DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS '.$grammar->wrap("{$tableName}_{$column}_unique_active")." ON {$table} (".$grammar->wrap($column).") WHERE {$deletedAt} IS NULL AND ".$grammar->wrap($column).' IS NOT NULL');
            }
        }

        $trueValue = DB::getDriverName() === 'pgsql' ? 'true' : '1';

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS '.$grammar->wrap('currencies_main_unique_active').' ON '.$grammar->wrapTable('currencies').' ('.$grammar->wrap('is_main').') WHERE '.$deletedAt.' IS NULL AND '.$grammar->wrap('status')." = 'active' AND ".$grammar->wrap('is_main').' = '.$trueValue);
    }
};
