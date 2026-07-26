<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('packaging_material_finished_product', function (Blueprint $table) {
            $table->id();
            $table->foreignId('packaging_material_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('finished_product_id')->constrained('products')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(
                ['packaging_material_id', 'finished_product_id'],
                'packaging_material_finished_product_unique',
            );
            $table->index(['finished_product_id', 'packaging_material_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('packaging_material_finished_product');
    }
};
