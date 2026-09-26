<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        $grammar = DB::getQueryGrammar();
        $index = $grammar->wrap('inventory_opening_stock_lines_document_product_unique_active');
        $table = $grammar->wrapTable('inventory_opening_stock_lines');
        $deletedAt = $grammar->wrap('deleted_at');

        DB::statement('DROP INDEX IF EXISTS '.$index);
        DB::statement('CREATE UNIQUE INDEX '.$index.' ON '.$table.' ('.$grammar->wrap('opening_stock_id').', '.$grammar->wrap('product_id').') WHERE '.$deletedAt.' IS NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // The former unconditional index would reject retained soft-deleted lines.
    }
};
