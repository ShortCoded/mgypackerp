<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['sales_orders', 'customer_invoices'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->string('withholding_basis', 30)->nullable();
                $table->decimal('withholding_rate', 7, 4)->default(0);
                $table->decimal('withholding_basis_amount', 20, 4)->nullable();
                $table->decimal('withholding_amount', 20, 4)->default(0);
                $table->decimal('net_payable_amount', 20, 4)->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['customer_invoices', 'sales_orders'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropColumn(['withholding_basis', 'withholding_rate', 'withholding_basis_amount', 'withholding_amount', 'net_payable_amount']);
            });
        }
    }
};
