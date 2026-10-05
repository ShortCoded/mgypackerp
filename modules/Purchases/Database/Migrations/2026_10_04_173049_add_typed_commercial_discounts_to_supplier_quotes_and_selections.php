<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_quotations', function (Blueprint $table): void {
            $table->string('header_discount_type', 20)->nullable();
            $table->decimal('header_discount_value', 20, 4)->nullable();
            $table->decimal('header_discount_amount', 20, 4)->default(0);
        });
        foreach (['supplier_quotation_lines', 'supplier_selection_lines'] as $name) {
            Schema::table($name, function (Blueprint $table) use ($name): void {
                $table->string('discount_type', 20)->nullable();
                $table->decimal('discount_value', 20, 4)->nullable();
                $table->decimal('subtotal_amount', 20, 4)->nullable();
                $table->decimal('header_discount_amount', 20, 4)->default(0);
                if ($name === 'supplier_selection_lines') {
                    $table->string('header_discount_type', 20)->nullable();
                    $table->decimal('header_discount_value', 20, 4)->nullable();
                    $table->json('source_discount_snapshot')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        Schema::table('supplier_selection_lines', fn (Blueprint $table) => $table->dropColumn(['discount_type', 'discount_value', 'subtotal_amount', 'header_discount_amount', 'header_discount_type', 'header_discount_value', 'source_discount_snapshot']));
        Schema::table('supplier_quotation_lines', fn (Blueprint $table) => $table->dropColumn(['discount_type', 'discount_value', 'subtotal_amount', 'header_discount_amount']));
        Schema::table('supplier_quotations', fn (Blueprint $table) => $table->dropColumn(['header_discount_type', 'header_discount_value', 'header_discount_amount']));
    }
};
