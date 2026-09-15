<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('quality_stock_holds', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('financial_period_id')->constrained('financial_periods')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('quality_inspection_id')->unique()->constrained('quality_inspections')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('branch_store_id')->constrained('branch_stores')->restrictOnDelete();
            $table->foreignId('warehouse_location_id')->nullable()->constrained('warehouse_locations')->restrictOnDelete();
            $table->string('batch_lot', 120)->nullable();
            $table->string('source_stock_status', 30);
            $table->string('held_stock_status', 30)->default('qc_hold');
            $table->decimal('base_quantity', 20, 8);
            $table->string('requested_disposition', 30)->nullable();
            $table->string('status', 30)->default('active')->index();
            $table->foreignId('hold_inventory_document_id')->nullable()->constrained('inventory_documents')->restrictOnDelete();
            $table->foreignId('disposition_inventory_document_id')->nullable()->constrained('inventory_documents')->restrictOnDelete();
            $table->foreignId('activated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('released_at')->nullable();
            $table->foreignId('dispositioned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('dispositioned_at')->nullable();
            $table->timestamps();
            $table->index(
                ['company_id', 'branch_store_id', 'product_id', 'status'],
                'quality_stock_holds_position_status_index',
            );
            $table->index(['quality_inspection_id', 'status'], 'quality_stock_holds_inspection_status_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('quality_stock_holds');
    }
};
