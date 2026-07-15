<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('inventory_opening_stock_lines', 'unit_id')) {
            return;
        }

        Schema::table('inventory_opening_stock_lines', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('unit_id');
        });
    }

    public function down(): void {}
};
