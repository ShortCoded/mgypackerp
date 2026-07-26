<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('products') || ! Schema::hasColumn('products', 'cost_as_inventory')) {
            return;
        }

        Schema::table('products', function (Blueprint $table): void {
            $table->boolean('cost_as_inventory')->default(true)->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('products') || ! Schema::hasColumn('products', 'cost_as_inventory')) {
            return;
        }

        Schema::table('products', function (Blueprint $table): void {
            $table->boolean('cost_as_inventory')->default(false)->change();
        });
    }
};
