<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('unpriced_inventory_receipts') || Schema::hasColumn('unpriced_inventory_receipts', 'branch_store_id')) {
            return;
        }

        Schema::table('unpriced_inventory_receipts', function (Blueprint $table): void {
            $table->foreignId('branch_store_id')
                ->nullable()
                ->after('branch_hall_id')
                ->constrained('branch_stores')
                ->nullOnDelete();

            $table->index('branch_store_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('unpriced_inventory_receipts') || ! Schema::hasColumn('unpriced_inventory_receipts', 'branch_store_id')) {
            return;
        }

        Schema::table('unpriced_inventory_receipts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('branch_store_id');
        });
    }
};
