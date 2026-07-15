<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('fixed_assets')) {
            return;
        }

        Schema::table('fixed_assets', function (Blueprint $table): void {
            if (! Schema::hasColumn('fixed_assets', 'image_path')) {
                $table->string('image_path')->nullable()->after('asset_name');
            }

            if (! Schema::hasColumn('fixed_assets', 'expected_usage_units')) {
                $table->decimal('expected_usage_units', 18, 4)->nullable()->after('annual_depreciation_rate');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('fixed_assets')) {
            return;
        }

        Schema::table('fixed_assets', function (Blueprint $table): void {
            foreach (['expected_usage_units', 'image_path'] as $column) {
                if (Schema::hasColumn('fixed_assets', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
