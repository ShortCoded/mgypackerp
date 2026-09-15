<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_components', function (Blueprint $table): void {
            $table->foreignId('production_stage_id')
                ->nullable()
                ->after('unit_id')
                ->constrained('production_stages')
                ->nullOnDelete();
            $table->index(['product_id', 'production_stage_id'], 'product_components_product_stage_index');
        });
    }

    public function down(): void
    {
        Schema::table('product_components', function (Blueprint $table): void {
            $table->dropIndex('product_components_product_stage_index');
            $table->dropConstrainedForeignId('production_stage_id');
        });
    }
};
