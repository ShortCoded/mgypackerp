<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('bank_accounts', 'bank_id')) {
            Schema::table('bank_accounts', function (Blueprint $table): void {
                $table->foreignId('bank_id')
                    ->nullable()
                    ->after('account_id')
                    ->constrained('accounts')
                    ->restrictOnDelete();
            });
        }

        $this->backfillBankIdsFromPostableChildren();
    }

    public function down(): void
    {
        if (Schema::hasColumn('bank_accounts', 'bank_id')) {
            Schema::table('bank_accounts', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('bank_id');
            });
        }
    }

    private function backfillBankIdsFromPostableChildren(): void
    {
        if (! Schema::hasColumn('bank_accounts', 'bank_id')) {
            return;
        }

        DB::table('bank_accounts')
            ->join('accounts', 'accounts.id', '=', 'bank_accounts.account_id')
            ->whereNull('bank_accounts.bank_id')
            ->whereNotNull('accounts.parent_id')
            ->where('accounts.is_group', false)
            ->where('accounts.is_postable', true)
            ->select(['bank_accounts.id', 'accounts.parent_id'])
            ->orderBy('bank_accounts.id')
            ->chunk(200, function ($rows): void {
                foreach ($rows as $row) {
                    DB::table('bank_accounts')
                        ->where('id', $row->id)
                        ->update(['bank_id' => $row->parent_id]);
                }
            });
    }
};
