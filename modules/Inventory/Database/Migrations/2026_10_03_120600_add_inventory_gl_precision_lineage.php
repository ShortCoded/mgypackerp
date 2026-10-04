<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_value_adjustment_lines', function (Blueprint $table): void {
            $table->string('precision_key', 120)->nullable()->unique('inventory_gl_precision_source_unique');
        });
    }

    public function down(): void
    {
        if (DB::table('inventory_value_adjustment_lines')->whereNotNull('precision_key')->exists()) {
            throw new RuntimeException('Approved inventory accounting precision lineage must be preserved.');
        }
        Schema::table('inventory_value_adjustment_lines', function (Blueprint $table): void {
            $table->dropUnique('inventory_gl_precision_source_unique');
            $table->dropColumn('precision_key');
        });
    }
};
