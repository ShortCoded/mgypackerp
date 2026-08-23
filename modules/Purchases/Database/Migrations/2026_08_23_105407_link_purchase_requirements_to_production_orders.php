<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('production_orders') || ! Schema::hasTable('production_order_lines')) {
            return;
        }

        Schema::table('purchase_requisition_lines', function (Blueprint $table): void {
            $table->foreignId('production_order_id')->nullable()->constrained('production_orders')->nullOnDelete();
            $table->foreignId('production_order_line_id')->nullable()->constrained('production_order_lines')->nullOnDelete();
            $table->index(['production_order_id', 'production_order_line_id'], 'purchase_requisition_lines_production_source_index');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('purchase_requisition_lines', 'production_order_id')) {
            return;
        }

        Schema::table('purchase_requisition_lines', function (Blueprint $table): void {
            $table->dropIndex('purchase_requisition_lines_production_source_index');
            $table->dropConstrainedForeignId('production_order_line_id');
            $table->dropConstrainedForeignId('production_order_id');
        });
    }
};
