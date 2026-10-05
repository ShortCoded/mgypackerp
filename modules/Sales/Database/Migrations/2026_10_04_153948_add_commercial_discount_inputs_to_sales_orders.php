<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['sales_orders', 'sales_order_lines'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->string('discount_type', 20)->nullable();
                $table->decimal('discount_value', 20, 4)->nullable();
                $table->decimal('header_discount_amount', 20, 4)->default(0);
            });
        }
    }

    public function down(): void
    {
        foreach (['sales_order_lines', 'sales_orders'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropColumn(['discount_type', 'discount_value', 'header_discount_amount']);
            });
        }
    }
};
