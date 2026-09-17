<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<string, string> */
    private array $activeIndexes = [
        'username' => 'users_username_unique_active',
        'email' => 'users_email_unique_active',
        'phone' => 'users_phone_unique_active',
        'doc_number' => 'users_doc_number_unique_active',
        'doc_num' => 'users_doc_num_unique_active',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'deleted_at')) {
            return;
        }

        if (! in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            return;
        }

        $grammar = DB::getQueryGrammar();
        $wrappedTable = $grammar->wrapTable('users');
        $wrappedDeletedAt = $grammar->wrap('deleted_at');

        foreach ($this->activeIndexes as $column => $index) {
            if (! Schema::hasColumn('users', $column)) {
                continue;
            }

            $wrappedIndex = $grammar->wrap($index);
            $wrappedColumn = $grammar->wrap($column);

            DB::statement("DROP INDEX IF EXISTS {$wrappedIndex}");
            DB::statement(
                "CREATE UNIQUE INDEX {$wrappedIndex} ON {$wrappedTable} ({$wrappedColumn}) WHERE {$wrappedDeletedAt} IS NULL"
            );
        }
    }

    public function down(): void {}
};
