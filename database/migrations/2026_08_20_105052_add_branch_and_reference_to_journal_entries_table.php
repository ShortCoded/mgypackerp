<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->foreignId('branch_id')
                ->nullable()
                ->after('financial_period_id')
                ->constrained('branches')
                ->restrictOnDelete();
            $table->string('reference_no')->nullable()->after('source_doc_num')->index();

            $table->index(['company_id', 'branch_id', 'entry_date']);
        });

        $this->restoreActiveDocumentIndex();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'branch_id', 'entry_date']);
            $table->dropForeign(['branch_id']);
            $table->dropColumn(['branch_id', 'reference_no']);
        });

        $this->restoreActiveDocumentIndex();
    }

    private function restoreActiveDocumentIndex(): void
    {
        if (! in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            return;
        }

        $grammar = DB::getQueryGrammar();
        $index = $grammar->wrap('journal_entries_company_period_doc_num_unique_active');
        $table = $grammar->wrapTable('journal_entries');
        $companyId = $grammar->wrap('company_id');
        $financialPeriodId = $grammar->wrap('financial_period_id');
        $docNum = $grammar->wrap('doc_num');
        $deletedAt = $grammar->wrap('deleted_at');

        DB::statement("DROP INDEX IF EXISTS {$index}");
        DB::statement("CREATE UNIQUE INDEX {$index} ON {$table} ({$companyId}, {$financialPeriodId}, {$docNum}) WHERE {$deletedAt} IS NULL AND {$docNum} IS NOT NULL");
    }
};
