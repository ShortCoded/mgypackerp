<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (['sales_order_lines', 'customer_invoice_lines'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->decimal('tax_rate', 7, 4)->nullable();
                $table->string('tax_calculation_basis', 24)->default('legacy_amount');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['sales_order_lines', 'customer_invoice_lines'] as $name) {
            if (DB::table($name)->whereNotNull('tax_rate')->exists()
                || DB::table($name)->where('tax_calculation_basis', '<>', 'legacy_amount')->exists()) {
                throw new RuntimeException('Cannot discard an established VAT rate and allocation contract.');
            }
        }
        foreach (['sales_order_lines', 'customer_invoice_lines'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn(['tax_rate', 'tax_calculation_basis']));
        }
    }
};
