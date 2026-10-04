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
        Schema::table('purchase_return_lines', function (Blueprint $table): void {
            $table->json('serial_receipt_layer_ids')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('purchase_return_lines')->whereNotNull('serial_receipt_layer_ids')->exists()) {
            throw new RuntimeException('Purchase return serial selections must be preserved.');
        }
        Schema::table('purchase_return_lines', function (Blueprint $table): void {
            $table->dropColumn('serial_receipt_layer_ids');
        });
    }
};
