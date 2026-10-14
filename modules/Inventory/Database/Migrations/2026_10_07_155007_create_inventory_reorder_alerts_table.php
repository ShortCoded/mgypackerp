<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_reorder_alerts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('financial_period_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_store_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->boolean('is_low')->default(false);
            $table->unsignedBigInteger('episode')->default(0);
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('low_since')->nullable();
            $table->timestamp('recovered_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'financial_period_id', 'branch_store_id', 'product_id'], 'inventory_reorder_scope_unique');
            $table->index(['company_id', 'financial_period_id', 'branch_id', 'is_low'], 'inventory_reorder_active_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_reorder_alerts');
    }
};
