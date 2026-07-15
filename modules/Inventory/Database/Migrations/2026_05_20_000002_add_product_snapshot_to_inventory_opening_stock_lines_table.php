<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_opening_stock_lines', function (Blueprint $table): void {
            $table->jsonb('product_snapshot')->nullable()->after('product_id');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_opening_stock_lines', function (Blueprint $table): void {
            $table->dropColumn('product_snapshot');
        });
    }
};
