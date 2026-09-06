<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_invoice_lines', function (Blueprint $table): void {
            $table->decimal('quantity', 22, 8)->change();
        });
    }

    public function down(): void
    {
        if (DB::table('purchase_invoice_lines')->whereRaw('quantity <> ROUND(quantity, 4)')->exists()) {
            throw new RuntimeException('Cannot reduce purchase quantity precision while stored lines use more than four decimal places.');
        }

        Schema::table('purchase_invoice_lines', function (Blueprint $table): void {
            $table->decimal('quantity', 18, 4)->change();
        });
    }
};
