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
        Schema::table('products', function (Blueprint $table): void {
            $table->boolean('tracks_serials')->default(false);
        });
        Schema::create('inventory_serial_identities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('serial_number', 100);
            $table->string('normalized_serial', 100);
            $table->foreignId('current_receipt_layer_id')->nullable()->constrained('inventory_receipt_layers')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'product_id', 'normalized_serial'], 'inventory_serial_identity_unique');
            $table->unique('current_receipt_layer_id', 'inventory_serial_current_layer_unique');
        });
        foreach (['inventory_document_lines', 'inventory_transactions', 'inventory_receipt_layers'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->foreignId('inventory_serial_identity_id')->nullable()->constrained('inventory_serial_identities')->restrictOnDelete();
            });
        }
        foreach (['inventory_transactions', 'unpriced_inventory_receipt_lines', 'goods_receipt_inspection_lines'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->json('serial_numbers')->nullable());
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('inventory_serial_identities')->exists()
            || DB::table('products')->where('tracks_serials', true)->exists()) {
            throw new RuntimeException('Inventory serial identity and movement lineage must be preserved.');
        }
        foreach (['inventory_document_lines', 'inventory_transactions', 'inventory_receipt_layers'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropConstrainedForeignId('inventory_serial_identity_id');
            });
        }
        foreach (['inventory_transactions', 'unpriced_inventory_receipt_lines', 'goods_receipt_inspection_lines'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('serial_numbers'));
        }
        Schema::drop('inventory_serial_identities');
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('tracks_serials'));
    }
};
