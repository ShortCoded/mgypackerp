<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('fixed_assets', 'legacy_recognition')) {
            return;
        }
        Schema::table('fixed_assets', function (Blueprint $table): void {
            $table->json('legacy_recognition')->nullable();
        });
        Schema::table('fixed_asset_category_mappings', function (Blueprint $table): void {
            foreach (['accumulated_depreciation_account_id', 'depreciation_expense_account_id', 'disposal_gain_account_id', 'disposal_loss_account_id'] as $column) {
                $table->unsignedBigInteger($column)->nullable()->change();
            }
        });
        $capturedAt = now()->toDateTimeString();
        $maximumId = DB::table('fixed_assets')->max('id');
        if ($maximumId === null) {
            return;
        }
        DB::table('fixed_assets')->where('id', '<=', $maximumId)->where('status', '!=', 'draft')
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')->from('fixed_asset_movements')->whereColumn('fixed_asset_id', 'fixed_assets.id')
                    ->whereIn('movement_type', ['capitalization', 'opening', 'addition']);
            })->orderBy('id')->chunkById(200, function ($assets) use ($capturedAt): void {
                foreach ($assets as $asset) {
                    $snapshot = ['captured_at' => $capturedAt, 'asset_id' => $asset->id, 'company_id' => $asset->company_id];
                    foreach (['purchase_value', 'base_acquisition_value', 'previous_depreciation', 'previous_depreciation_until_date', 'exchange_rate', 'salvage_value', 'useful_life', 'asset_date', 'depreciation_start_date', 'account_id', 'branch_id', 'cost_center_id', 'period_id'] as $field) {
                        $snapshot[$field] = $asset->{$field};
                    }
                    DB::table('fixed_assets')->where('id', $asset->id)->update(['legacy_recognition' => json_encode($snapshot, JSON_THROW_ON_ERROR)]);
                }
            });
    }

    /** Baselines are durable rollout evidence; rollback must not reopen historical capitalization. */
    public function down(): void {}
};
