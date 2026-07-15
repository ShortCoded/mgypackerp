<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('accounts')) {
            $this->addAccountsCompanyScope();
        }

        if (! Schema::hasTable('cost_centers')) {
            Schema::create('cost_centers', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
                $table->foreignId('parent_id')->nullable()->constrained('cost_centers')->nullOnDelete();
                $table->unsignedBigInteger('doc_number')->nullable();
                $table->string('doc_num')->nullable();
                $table->string('cost_center_code', 50);
                $table->string('name');
                $table->string('status')->default('active')->index();
                $table->text('notes')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('restored_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('restored_at')->nullable();
                $table->timestamps();
                $table->softDeletes()->index();

                $table->index('company_id', 'cost_centers_company_id_index');
                $table->index(['company_id', 'parent_id'], 'cost_centers_company_parent_index');
            });
        }

        $this->createActiveUniqueIndex('cost_centers', 'cost_centers_company_doc_number_unique_active', ['company_id', 'doc_number'], 'doc_number', companyScoped: true);
        $this->createActiveUniqueIndex('cost_centers', 'cost_centers_company_doc_num_unique_active', ['company_id', 'doc_num'], 'doc_num', companyScoped: true);
        $this->createActiveUniqueIndex('cost_centers', 'cost_centers_company_code_unique_active', ['company_id', 'cost_center_code'], 'cost_center_code', companyScoped: true);
    }

    public function down(): void
    {
        Schema::dropIfExists('cost_centers');

        if (! Schema::hasTable('accounts')) {
            return;
        }

        foreach ([
            'accounts_company_doc_num_unique_active',
            'accounts_company_doc_number_unique_active',
            'accounts_company_account_code_unique_active',
            'accounts_company_parent_index',
        ] as $index) {
            $this->dropIndexIfExists($index);
        }

        $this->createActiveUniqueIndex('accounts', 'accounts_doc_num_unique_active', ['doc_num'], 'doc_num');
        $this->createActiveUniqueIndex('accounts', 'accounts_doc_number_unique_active', ['doc_number'], 'doc_number');
        $this->createActiveUniqueIndex('accounts', 'accounts_account_code_unique_active', ['account_code'], 'account_code');

        if (Schema::hasColumn('accounts', 'company_id')) {
            Schema::table('accounts', function (Blueprint $table): void {
                $this->dropForeignIfExists('accounts', 'accounts_company_id_foreign');
                $this->dropIndexIfExists('accounts_company_id_index');
                $table->dropColumn('company_id');
            });
        }
    }

    private function addAccountsCompanyScope(): void
    {
        if (! Schema::hasColumn('accounts', 'company_id')) {
            Schema::table('accounts', function (Blueprint $table): void {
                $table->foreignId('company_id')
                    ->nullable()
                    ->after('id')
                    ->constrained('companies')
                    ->restrictOnDelete();
            });
        }

        $this->backfillAccountsCompany();

        $this->dropAccountGlobalUniqueIndexes();
        $this->createIndexIfMissing('accounts', 'accounts_company_id_index', ['company_id']);
        $this->createIndexIfMissing('accounts', 'accounts_company_parent_index', ['company_id', 'parent_id']);
        $this->createActiveUniqueIndex('accounts', 'accounts_company_doc_num_unique_active', ['company_id', 'doc_num'], 'doc_num', companyScoped: true);
        $this->createActiveUniqueIndex('accounts', 'accounts_company_doc_number_unique_active', ['company_id', 'doc_number'], 'doc_number', companyScoped: true);
        $this->createActiveUniqueIndex('accounts', 'accounts_company_account_code_unique_active', ['company_id', 'account_code'], 'account_code', companyScoped: true);
    }

    private function backfillAccountsCompany(): void
    {
        $companyId = $this->defaultCompanyId();

        if ($companyId === null) {
            return;
        }

        DB::table('accounts')
            ->whereNull('company_id')
            ->update(['company_id' => $companyId]);
    }

    private function dropAccountGlobalUniqueIndexes(): void
    {
        foreach ([
            'accounts_doc_num_unique_active',
            'accounts_doc_number_unique_active',
            'accounts_account_code_unique_active',
            'accounts_doc_num_unique',
            'accounts_doc_number_unique',
            'accounts_account_code_unique',
        ] as $index) {
            $this->dropIndexIfExists($index);
        }
    }

    /**
     * @param  list<string>  $columns
     */
    private function createActiveUniqueIndex(
        string $tableName,
        string $index,
        array $columns,
        string $nullableColumn,
        bool $companyScoped = false
    ): void {
        if (! Schema::hasTable($tableName)) {
            return;
        }

        foreach ($columns as $column) {
            if (! Schema::hasColumn($tableName, $column)) {
                return;
            }
        }

        $grammar = DB::getQueryGrammar();
        $wrappedIndex = $grammar->wrap($index);
        $wrappedTable = $grammar->wrapTable($tableName);
        $wrappedColumns = implode(', ', array_map(fn (string $column): string => $grammar->wrap($column), $columns));
        $predicate = $grammar->wrap('deleted_at').' IS NULL';

        if ($companyScoped) {
            $predicate .= ' AND '.$grammar->wrap('company_id').' IS NOT NULL';
        }

        if (in_array($nullableColumn, ['doc_number', 'doc_num', 'account_code', 'cost_center_code'], true)) {
            $predicate .= ' AND '.$grammar->wrap($nullableColumn).' IS NOT NULL';
        }

        match (DB::getDriverName()) {
            'pgsql', 'sqlite' => DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS {$wrappedIndex} ON {$wrappedTable} ({$wrappedColumns}) WHERE {$predicate}"),
            default => null,
        };
    }

    /**
     * @param  list<string>  $columns
     */
    private function createIndexIfMissing(string $tableName, string $index, array $columns): void
    {
        if (! Schema::hasTable($tableName)) {
            return;
        }

        foreach ($columns as $column) {
            if (! Schema::hasColumn($tableName, $column)) {
                return;
            }
        }

        $grammar = DB::getQueryGrammar();
        $wrappedIndex = $grammar->wrap($index);
        $wrappedTable = $grammar->wrapTable($tableName);
        $wrappedColumns = implode(', ', array_map(fn (string $column): string => $grammar->wrap($column), $columns));

        match (DB::getDriverName()) {
            'pgsql', 'sqlite' => DB::statement("CREATE INDEX IF NOT EXISTS {$wrappedIndex} ON {$wrappedTable} ({$wrappedColumns})"),
            default => null,
        };
    }

    private function dropIndexIfExists(string $index): void
    {
        $wrappedIndex = DB::getQueryGrammar()->wrap($index);

        match (DB::getDriverName()) {
            'pgsql', 'sqlite' => DB::statement("DROP INDEX IF EXISTS {$wrappedIndex}"),
            default => null,
        };
    }

    private function dropForeignIfExists(string $tableName, string $constraint): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $grammar = DB::getQueryGrammar();
        DB::statement(sprintf(
            'ALTER TABLE %s DROP CONSTRAINT IF EXISTS %s',
            $grammar->wrapTable($tableName),
            $grammar->wrap($constraint),
        ));
    }

    private function defaultCompanyId(): ?int
    {
        if (! Schema::hasTable('companies')) {
            return null;
        }

        $mainCompanyId = DB::table('companies')
            ->whereNull('deleted_at')
            ->where('status', 'active')
            ->where('is_main', true)
            ->orderBy('id')
            ->value('id');

        if ($mainCompanyId !== null) {
            return (int) $mainCompanyId;
        }

        $firstCompanyId = DB::table('companies')
            ->whereNull('deleted_at')
            ->where('status', 'active')
            ->orderBy('id')
            ->value('id');

        return $firstCompanyId === null ? null : (int) $firstCompanyId;
    }
};
