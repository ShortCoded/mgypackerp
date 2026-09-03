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
        $timestamp = now();

        DB::table('cost_centers')
            ->join('accounts', function ($join): void {
                $join->on('accounts.id', '=', 'cost_centers.default_account_id')
                    ->on('accounts.company_id', '=', 'cost_centers.company_id');
            })
            ->whereNotNull('cost_centers.default_account_id')
            ->select([
                'cost_centers.id as cost_center_id',
                'cost_centers.default_account_id as account_id',
            ])
            ->orderBy('cost_centers.id')
            ->chunk(500, function ($legacyLinks) use ($timestamp): void {
                DB::table('cost_center_accounts')->insertOrIgnore(
                    $legacyLinks->map(fn ($legacyLink): array => [
                        'cost_center_id' => $legacyLink->cost_center_id,
                        'account_id' => $legacyLink->account_id,
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ])->all()
                );
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // The following schema rollback restores the legacy column from the pivot.
    }
};
