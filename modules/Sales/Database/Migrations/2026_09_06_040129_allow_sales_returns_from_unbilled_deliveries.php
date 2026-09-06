<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_returns', function (Blueprint $table): void {
            $table->unsignedBigInteger('customer_invoice_id')->nullable()->change();
        });
        Schema::table('sales_return_lines', function (Blueprint $table): void {
            $table->unsignedBigInteger('customer_invoice_line_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::table('sales_returns')->whereNull('customer_invoice_id')->exists()) {
            throw new RuntimeException('Unbilled delivery returns must be retained; rollback would invalidate their source records.');
        }
        Schema::table('sales_return_lines', function (Blueprint $table): void {
            $table->unsignedBigInteger('customer_invoice_line_id')->nullable(false)->change();
        });
        Schema::table('sales_returns', function (Blueprint $table): void {
            $table->unsignedBigInteger('customer_invoice_id')->nullable(false)->change();
        });
    }
};
