<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_document_lines', function (Blueprint $table): void {
            $table->foreignId('selected_receipt_layer_id')->nullable()->constrained('inventory_receipt_layers')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('inventory_document_lines')->whereNotNull('selected_receipt_layer_id')->exists()) {
            throw new RuntimeException('Selected receipt layer evidence must be preserved.');
        }
        Schema::table('inventory_document_lines', fn (Blueprint $table) => $table->dropConstrainedForeignId('selected_receipt_layer_id'));
    }
};
