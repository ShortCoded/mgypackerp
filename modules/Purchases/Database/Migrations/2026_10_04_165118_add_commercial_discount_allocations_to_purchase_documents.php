<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->string('header_discount_type', 20)->nullable();
            $table->decimal('header_discount_value', 20, 4)->nullable();
            $table->decimal('header_discount_amount', 20, 4)->default(0);
        });
        foreach (['purchase_order_lines', 'purchase_invoice_lines'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->decimal('header_discount_amount', 20, 4)->default(0);
            });
        }
        Schema::table('purchase_invoice_lines', function (Blueprint $table): void {
            $table->json('source_discount_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_invoice_lines', function (Blueprint $table): void {
            $table->dropColumn(['source_discount_snapshot', 'header_discount_amount']);
        });
        Schema::table('purchase_order_lines', function (Blueprint $table): void {
            $table->dropColumn('header_discount_amount');
        });
        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->dropColumn(['header_discount_type', 'header_discount_value', 'header_discount_amount']);
        });
    }
};
