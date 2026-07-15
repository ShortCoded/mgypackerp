<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            if (Schema::hasColumn('products', 'item_decal_id')) {
                $table->index('item_decal_id', 'products_item_decal_id_index');
            }

            if (Schema::hasColumn('products', 'item_origin_country_id')) {
                $table->index('item_origin_country_id', 'products_item_origin_country_id_index');
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            if (Schema::hasColumn('products', 'item_origin_country_id')) {
                $table->dropIndex('products_item_origin_country_id_index');
            }

            if (Schema::hasColumn('products', 'item_decal_id')) {
                $table->dropIndex('products_item_decal_id_index');
            }
        });
    }
};
