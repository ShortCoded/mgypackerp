<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_invoice_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_invoice_id')->constrained('customer_invoices')->cascadeOnDelete();
            $table->foreignId('inventory_document_id')->constrained('inventory_documents')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['customer_invoice_id', 'inventory_document_id'], 'customer_invoice_delivery_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_invoice_deliveries');
    }
};
