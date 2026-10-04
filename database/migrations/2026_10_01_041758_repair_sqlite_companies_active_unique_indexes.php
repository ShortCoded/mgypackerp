<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite' || ! Schema::hasTable('companies')) {
            return;
        }

        $grammar = DB::getQueryGrammar();
        $table = $grammar->wrapTable('companies');
        $deletedAt = $grammar->wrap('deleted_at');
        $columns = [
            'doc_number', 'doc_num', 'name', 'email', 'commercial_register_number',
            'tax_card_number', 'vat_registration_number', 'national_id',
        ];

        foreach ($columns as $column) {
            if (! Schema::hasColumn('companies', $column)) {
                continue;
            }

            $index = $grammar->wrap('companies_'.$column.'_unique_active');
            $field = $grammar->wrap($column);
            $predicate = $deletedAt.' IS NULL';
            if (in_array($column, ['email', 'commercial_register_number', 'tax_card_number', 'vat_registration_number', 'national_id'], true)) {
                $predicate .= ' AND '.$field.' IS NOT NULL';
            }

            DB::statement('DROP INDEX IF EXISTS '.$index);
            DB::statement('CREATE UNIQUE INDEX '.$index.' ON '.$table.' ('.$field.') WHERE '.$predicate);
        }

        $main = $grammar->wrap('companies_one_active_main_unique');
        DB::statement('DROP INDEX IF EXISTS '.$main);
        DB::statement('CREATE UNIQUE INDEX '.$main.' ON '.$table.' ('.$grammar->wrap('is_main').') WHERE '
            .$grammar->wrap('is_main').' = 1 AND '.$grammar->wrap('status')." = 'active' AND ".$deletedAt.' IS NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Restoring the unconditional indexes would reject valid secondary and soft-deleted companies.
    }
};
