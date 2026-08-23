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
        Schema::table('fixed_asset_disposals', function (Blueprint $table) {
            $table->string('asset_status_before')->nullable()->after('fixed_asset_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('fixed_asset_disposals', function (Blueprint $table) {
            $table->dropColumn('asset_status_before');
        });
    }
};
