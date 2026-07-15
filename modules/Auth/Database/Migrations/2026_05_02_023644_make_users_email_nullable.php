<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @var array<string, string>
     */
    private array $activeIndexes = [
        'username' => 'users_username_unique_active',
        'email' => 'users_email_unique_active',
        'phone' => 'users_phone_unique_active',
        'doc_number' => 'users_doc_number_unique_active',
        'doc_num' => 'users_doc_num_unique_active',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'email')) {
            return;
        }

        if (DB::getDriverName() === 'pgsql') {
            $this->alterPostgreSqlEmailNullable(nullable: true);

            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->string('email')->nullable()->change();
        });

        if (DB::getDriverName() === 'sqlite') {
            $this->refreshSqliteActiveIndexes();
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('users', 'email')) {
            return;
        }

        if (DB::table('users')->whereNull('email')->exists()) {
            return;
        }

        if (DB::getDriverName() === 'pgsql') {
            $this->alterPostgreSqlEmailNullable(nullable: false);

            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->string('email')->nullable(false)->change();
        });

        if (DB::getDriverName() === 'sqlite') {
            $this->refreshSqliteActiveIndexes();
        }
    }

    private function alterPostgreSqlEmailNullable(bool $nullable): void
    {
        $wrappedTable = DB::getQueryGrammar()->wrapTable('users');
        $wrappedColumn = DB::getQueryGrammar()->wrap('email');
        $operation = $nullable ? 'DROP NOT NULL' : 'SET NOT NULL';

        DB::statement("ALTER TABLE {$wrappedTable} ALTER COLUMN {$wrappedColumn} {$operation}");
    }

    private function refreshSqliteActiveIndexes(): void
    {
        if (! Schema::hasColumn('users', 'deleted_at')) {
            return;
        }

        foreach ($this->activeIndexes as $column => $index) {
            if (! Schema::hasColumn('users', $column)) {
                continue;
            }

            foreach ($this->sqliteIndexNames($column, $index) as $indexName) {
                $wrappedIndex = DB::getQueryGrammar()->wrap($indexName);

                DB::statement("DROP INDEX IF EXISTS {$wrappedIndex}");
            }

            $wrappedIndex = DB::getQueryGrammar()->wrap($index);
            $wrappedTable = DB::getQueryGrammar()->wrapTable('users');
            $wrappedColumn = DB::getQueryGrammar()->wrap($column);
            $wrappedDeletedAt = DB::getQueryGrammar()->wrap('deleted_at');

            DB::statement(
                "CREATE UNIQUE INDEX IF NOT EXISTS {$wrappedIndex} ON {$wrappedTable} ({$wrappedColumn}) WHERE {$wrappedDeletedAt} IS NULL"
            );
        }
    }

    /**
     * @return list<string>
     */
    private function sqliteIndexNames(string $column, string $activeIndex): array
    {
        $legacyIndexes = match ($column) {
            'email' => ['users_email_unique'],
            'username' => ['users_username_unique'],
            'phone' => ['users_phone_unique'],
            default => [],
        };

        return [$activeIndex, ...$legacyIndexes];
    }
};
