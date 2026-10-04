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
        Schema::create('inventory_opening_stock_cost_corrections', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('opening_stock_id')->constrained('inventory_opening_stocks')->restrictOnDelete();
            $table->foreignId('financial_period_id')->constrained('financial_periods')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('posting_period_id')->constrained('financial_periods')->restrictOnDelete();
            $table->date('posting_date');
            $table->foreignId('counterpart_account_id')->constrained('accounts')->restrictOnDelete();
            $table->string('status', 20)->default('pending');
            $table->text('reason');
            $table->string('source_reference', 255);
            $table->string('approval_reference', 255)->nullable();
            $table->text('rejection_reason')->nullable();
            $table->json('unit_costs');
            $table->json('source_snapshot');
            $table->json('plan');
            $table->string('fingerprint', 64);
            $table->foreignId('prepared_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('inventory_value_adjustment_id')->nullable()->constrained('inventory_value_adjustments')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'opening_stock_id', 'status'], 'opening_cost_corrections_source_status_index');
            $table->index(['company_id', 'posting_period_id', 'posting_date'], 'opening_cost_corrections_posting_scope_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('inventory_opening_stock_cost_corrections')->exists()) {
            throw new RuntimeException('Opening-stock cost-correction approval evidence cannot be dropped.');
        }

        Schema::dropIfExists('inventory_opening_stock_cost_corrections');
    }
};
