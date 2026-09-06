<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['sales_order_lines', 'customer_invoice_lines'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->decimal('quantity', 24, 8)->change();
            });
        }
        Schema::table('quotation_revision_lines', function (Blueprint $table): void {
            $table->decimal('quantity', 22, 8)->default(0)->change();
        });
    }

    public function down(): void
    {
        foreach (['sales_order_lines', 'customer_invoice_lines', 'quotation_revision_lines'] as $name) {
            if (DB::table($name)->whereRaw('quantity <> round(quantity, 4)')->exists()) {
                throw new RuntimeException('Sales quantities use eight decimal places; rollback would lose precision.');
            }
        }
        foreach (['sales_order_lines', 'customer_invoice_lines'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->decimal('quantity', 20, 4)->change();
            });
        }
        Schema::table('quotation_revision_lines', function (Blueprint $table): void {
            $table->decimal('quantity', 18, 4)->default(0)->change();
        });
    }
};
