<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['sales_orders', 'production_orders', 'inventory_documents', 'customer_invoices', 'customer_receipts', 'sales_returns'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->json('print_identity_snapshot')->nullable();
            });
        }

        Schema::table('customer_invoices', function (Blueprint $table): void {
            $table->string('electronic_invoice_status', 40)->default('not_configured')->index();
            $table->uuid('electronic_invoice_uuid')->nullable()->unique();
            $table->timestamp('electronic_invoice_submitted_at')->nullable();
            $table->json('electronic_invoice_response')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('customer_invoices', function (Blueprint $table): void {
            $table->dropUnique(['electronic_invoice_uuid']);
            $table->dropColumn(['electronic_invoice_status', 'electronic_invoice_uuid', 'electronic_invoice_submitted_at', 'electronic_invoice_response']);
        });

        foreach (['sales_orders', 'production_orders', 'inventory_documents', 'customer_invoices', 'customer_receipts', 'sales_returns'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropColumn('print_identity_snapshot');
            });
        }
    }
};
