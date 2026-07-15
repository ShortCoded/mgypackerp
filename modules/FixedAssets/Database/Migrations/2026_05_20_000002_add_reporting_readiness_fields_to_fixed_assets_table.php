<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('fixed_assets')) {
            return;
        }

        Schema::table('fixed_assets', function (Blueprint $table): void {
            if (! Schema::hasColumn('fixed_assets', 'entry_type')) {
                $table->string('entry_type')->default('new_asset')->after('asset_name')->index();
            }

            if (! Schema::hasColumn('fixed_assets', 'previous_depreciation_until_date')) {
                $table->date('previous_depreciation_until_date')->nullable()->after('previous_depreciation');
            }

            if (! Schema::hasColumn('fixed_assets', 'depreciation_start_date')) {
                $table->date('depreciation_start_date')->nullable()->after('previous_depreciation_until_date')->index();
            }

            if (! Schema::hasColumn('fixed_assets', 'salvage_value')) {
                $table->decimal('salvage_value', 18, 4)->default(0)->after('purchase_value');
            }

            if (! Schema::hasColumn('fixed_assets', 'depreciation_method')) {
                $table->string('depreciation_method')->nullable()->default('straight_line')->after('is_depreciable')->index();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('fixed_assets')) {
            return;
        }

        Schema::table('fixed_assets', function (Blueprint $table): void {
            foreach ([
                'depreciation_method',
                'salvage_value',
                'depreciation_start_date',
                'previous_depreciation_until_date',
                'entry_type',
            ] as $column) {
                if (Schema::hasColumn('fixed_assets', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
