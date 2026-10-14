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
        foreach (['purchase_order_lines', 'purchase_invoice_lines', 'purchase_return_lines'] as $table) {
            Schema::table($table, function (Blueprint $schema): void {
                $schema->decimal('unit_price', 29, 15)->default(0)->change();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new RuntimeException('Purchase unit-price precision cannot be narrowed without a reviewed data migration.');
    }
};
