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
        if (! Schema::hasTable('product_components') || ! Schema::hasColumn('product_components', 'quantity')) {
            return;
        }

        Schema::table('product_components', function (Blueprint $table): void {
            $table->decimal('quantity', 18, 8)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('product_components') || ! Schema::hasColumn('product_components', 'quantity')) {
            return;
        }

        Schema::table('product_components', function (Blueprint $table): void {
            $table->decimal('quantity', 15, 4)->change();
        });
    }
};
