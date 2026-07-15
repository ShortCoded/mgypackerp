<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('products', 'item_color_id')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->foreignId('item_color_id')
                    ->nullable()
                    ->after('item_size_id')
                    ->constrained('item_colors')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('products', 'item_color_id')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('item_color_id');
            });
        }
    }
};
