<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->dropBankNameNotNull();
        $this->rebuildBankAccountUniqueIndex(activeOnly: true);
        $this->markMainBanksAccountAsGroup();
    }

    public function down(): void
    {
        $this->rebuildBankAccountUniqueIndex(activeOnly: false);
    }

    private function dropBankNameNotNull(): void
    {
        match (DB::getDriverName()) {
            'pgsql' => DB::statement('ALTER TABLE bank_accounts ALTER COLUMN bank_name DROP NOT NULL'),
            default => Schema::table('bank_accounts', function (Blueprint $table): void {
                $table->string('bank_name')->nullable()->change();
            }),
        };
    }

    private function markMainBanksAccountAsGroup(): void
    {
        $bankClassificationId = DB::table('account_classifications')->where('code', 'bank')->value('id');

        if (! $bankClassificationId) {
            return;
        }

        DB::table('accounts')
            ->where('account_code', '1112')
            ->where('account_classification_id', $bankClassificationId)
            ->update([
                'is_group' => true,
                'is_postable' => false,
                'updated_at' => now(),
            ]);
    }

    private function rebuildBankAccountUniqueIndex(bool $activeOnly): void
    {
        if (! in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            return;
        }

        $grammar = DB::getQueryGrammar();
        $index = $grammar->wrap('bank_accounts_account_id_unique_active');
        $table = $grammar->wrapTable('bank_accounts');
        $accountId = $grammar->wrap('account_id');
        $deletedAt = $grammar->wrap('deleted_at');
        $status = $grammar->wrap('status');
        $activeClause = $activeOnly ? " AND {$status} = 'active'" : '';

        DB::statement("DROP INDEX IF EXISTS {$index}");
        DB::statement("CREATE UNIQUE INDEX {$index} ON {$table} ({$accountId}) WHERE {$deletedAt} IS NULL AND {$accountId} IS NOT NULL{$activeClause}");
    }
};
