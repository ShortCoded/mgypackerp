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
        Schema::table('cost_centers', function (Blueprint $table): void {
            $table->dropIndex('cost_centers_company_default_account_index');
            $table->dropConstrainedForeignId('default_account_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cost_centers', function (Blueprint $table): void {
            $table->foreignId('default_account_id')
                ->nullable()
                ->after('parent_id')
                ->constrained('accounts')
                ->restrictOnDelete();
            $table->index(['company_id', 'default_account_id'], 'cost_centers_company_default_account_index');
        });

        DB::table('cost_center_accounts')
            ->join('cost_centers', 'cost_centers.id', '=', 'cost_center_accounts.cost_center_id')
            ->join('accounts', function ($join): void {
                $join->on('accounts.id', '=', 'cost_center_accounts.account_id')
                    ->on('accounts.company_id', '=', 'cost_centers.company_id');
            })
            ->select(['cost_center_accounts.id', 'cost_center_accounts.cost_center_id', 'cost_center_accounts.account_id'])
            ->orderBy('cost_center_accounts.id')
            ->each(function ($link): void {
                DB::table('cost_centers')
                    ->where('id', $link->cost_center_id)
                    ->whereNull('default_account_id')
                    ->update(['default_account_id' => $link->account_id]);
            });
    }
};
