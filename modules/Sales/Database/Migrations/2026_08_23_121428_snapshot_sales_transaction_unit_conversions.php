<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_order_lines', function (Blueprint $table): void {
            $table->decimal('conversion_factor', 20, 8)->default(1)->after('unit_id');
            $table->decimal('base_quantity', 20, 8)->default(0)->after('quantity');
            $table->decimal('reserved_base_quantity', 20, 8)->default(0)->after('reserved_quantity');
            $table->decimal('production_requested_base_quantity', 20, 8)->default(0)->after('production_requested_quantity');
            $table->decimal('produced_base_quantity', 20, 8)->default(0)->after('produced_quantity');
            $table->decimal('delivered_base_quantity', 20, 8)->default(0)->after('delivered_quantity');
            $table->decimal('invoiced_base_quantity', 20, 8)->default(0)->after('invoiced_quantity');
            $table->decimal('returned_base_quantity', 20, 8)->default(0)->after('returned_quantity');
        });

        Schema::table('inventory_reservations', function (Blueprint $table): void {
            $table->foreignId('transaction_unit_id')->nullable()->after('unit_id')->constrained('item_units')->restrictOnDelete();
            $table->decimal('conversion_factor', 20, 8)->default(1)->after('transaction_unit_id');
            $table->decimal('transaction_quantity', 20, 8)->default(0)->after('conversion_factor');
        });

        Schema::table('production_order_lines', function (Blueprint $table): void {
            $table->decimal('conversion_factor', 20, 8)->default(1)->after('unit_id');
            $table->decimal('base_quantity', 20, 8)->default(0)->after('quantity');
        });

        Schema::table('inventory_document_lines', function (Blueprint $table): void {
            $table->foreignId('transaction_unit_id')->nullable()->after('unit_id')->constrained('item_units')->restrictOnDelete();
            $table->decimal('conversion_factor', 20, 8)->default(1)->after('transaction_unit_id');
            $table->decimal('transaction_quantity', 20, 8)->default(0)->after('conversion_factor');
            $table->decimal('base_quantity', 20, 8)->default(0)->after('quantity');
        });

        Schema::table('customer_invoice_lines', function (Blueprint $table): void {
            $table->decimal('conversion_factor', 20, 8)->default(1)->after('unit_id');
            $table->decimal('base_quantity', 20, 8)->default(0)->after('quantity');
        });

        Schema::table('customer_invoices', function (Blueprint $table): void {
            $table->unsignedInteger('posting_revision')->default(0)->after('posting_status');
        });

        Schema::table('sales_return_lines', function (Blueprint $table): void {
            $table->decimal('conversion_factor', 20, 8)->default(1)->after('unit_id');
            $table->decimal('base_quantity', 20, 8)->default(0)->after('quantity');
            $table->decimal('saleable_base_quantity', 20, 8)->default(0)->after('saleable_quantity');
            $table->decimal('quarantine_base_quantity', 20, 8)->default(0)->after('quarantine_quantity');
            $table->decimal('rework_base_quantity', 20, 8)->default(0)->after('rework_quantity');
            $table->decimal('scrap_base_quantity', 20, 8)->default(0)->after('scrap_quantity');
        });

        Schema::table('customer_receipts', function (Blueprint $table): void {
            $table->date('cheque_due_date')->nullable()->after('reference_no');
            $table->string('external_bank_name')->nullable()->after('cheque_due_date');
            $table->foreignId('cash_voucher_id')->nullable()->after('bank_account_id')->constrained('cash_vouchers')->restrictOnDelete();
            $table->foreignId('cheque_id')->nullable()->after('cash_voucher_id')->constrained('cheques')->restrictOnDelete();
        });

        $this->backfillConversionSnapshots();
    }

    public function down(): void
    {
        Schema::table('customer_receipts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('cheque_id');
            $table->dropConstrainedForeignId('cash_voucher_id');
            $table->dropColumn(['cheque_due_date', 'external_bank_name']);
        });

        Schema::table('sales_return_lines', function (Blueprint $table): void {
            $table->dropColumn(['conversion_factor', 'base_quantity', 'saleable_base_quantity', 'quarantine_base_quantity', 'rework_base_quantity', 'scrap_base_quantity']);
        });

        Schema::table('customer_invoice_lines', function (Blueprint $table): void {
            $table->dropColumn(['conversion_factor', 'base_quantity']);
        });

        Schema::table('customer_invoices', function (Blueprint $table): void {
            $table->dropColumn('posting_revision');
        });

        Schema::table('inventory_document_lines', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('transaction_unit_id');
            $table->dropColumn(['conversion_factor', 'transaction_quantity', 'base_quantity']);
        });

        Schema::table('production_order_lines', function (Blueprint $table): void {
            $table->dropColumn(['conversion_factor', 'base_quantity']);
        });

        Schema::table('inventory_reservations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('transaction_unit_id');
            $table->dropColumn(['conversion_factor', 'transaction_quantity']);
        });

        Schema::table('sales_order_lines', function (Blueprint $table): void {
            $table->dropColumn([
                'conversion_factor', 'base_quantity', 'reserved_base_quantity',
                'production_requested_base_quantity', 'produced_base_quantity',
                'delivered_base_quantity', 'invoiced_base_quantity', 'returned_base_quantity',
            ]);
        });
    }

    private function backfillConversionSnapshots(): void
    {
        foreach (['sales_order_lines', 'production_order_lines', 'customer_invoice_lines', 'sales_return_lines'] as $table) {
            DB::table($table)->update(['base_quantity' => DB::raw('quantity')]);
        }

        DB::table('sales_order_lines')->update([
            'reserved_base_quantity' => DB::raw('reserved_quantity'),
            'production_requested_base_quantity' => DB::raw('production_requested_quantity'),
            'produced_base_quantity' => DB::raw('produced_quantity'),
            'delivered_base_quantity' => DB::raw('delivered_quantity'),
            'invoiced_base_quantity' => DB::raw('invoiced_quantity'),
            'returned_base_quantity' => DB::raw('returned_quantity'),
        ]);
        DB::table('inventory_reservations')->update([
            'transaction_unit_id' => DB::raw('unit_id'),
            'transaction_quantity' => DB::raw('quantity'),
        ]);
        DB::table('inventory_document_lines')->update([
            'transaction_unit_id' => DB::raw('unit_id'),
            'transaction_quantity' => DB::raw('quantity'),
            'base_quantity' => DB::raw('quantity'),
        ]);
        DB::table('sales_return_lines')->update([
            'saleable_base_quantity' => DB::raw('saleable_quantity'),
            'quarantine_base_quantity' => DB::raw('quarantine_quantity'),
            'rework_base_quantity' => DB::raw('rework_quantity'),
            'scrap_base_quantity' => DB::raw('scrap_quantity'),
        ]);
    }
};
