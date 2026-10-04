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
        Schema::create('inventory_cost_standards', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_uuid')->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('doc_num', 100);
            $table->date('effective_from');
            $table->date('effective_to');
            $table->string('status', 20);
            foreach (['materials', 'labor', 'overhead'] as $component) {
                $table->decimal($component.'_unit_cost', 20, 8);
                $table->foreignId($component.'_variance_account_id')->constrained('accounts')->restrictOnDelete();
            }
            $table->foreignId('counterpart_account_id')->constrained('accounts')->restrictOnDelete();
            $table->text('source_reference');
            $table->json('basis_snapshot');
            $table->string('basis_sha256', 64);
            $table->foreignId('prepared_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('approval_reference', 500)->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'doc_num']);
            $table->index(['company_id', 'branch_id', 'product_id', 'effective_from'], 'inventory_standard_scope_date');
        });
        Schema::create('inventory_standard_cost_settlements', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_uuid')->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('financial_period_id')->constrained()->restrictOnDelete();
            $table->foreignId('posting_period_id')->constrained('financial_periods')->restrictOnDelete();
            $table->foreignId('production_run_id')->constrained()->restrictOnDelete();
            $table->foreignId('inventory_cost_standard_id')->constrained()->restrictOnDelete();
            $table->foreignId('counterpart_account_id')->constrained('accounts')->restrictOnDelete();
            $table->string('doc_num', 100);
            $table->unsignedInteger('revision');
            $table->date('posting_date');
            $table->string('status', 20);
            $table->text('reason');
            $table->json('impact_snapshot')->nullable();
            $table->string('impact_sha256', 64)->nullable();
            $table->foreignId('prepared_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('approval_reference', 500)->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'doc_num']);
            $table->unique(['production_run_id', 'revision']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('inventory_cost_standards')->exists() || DB::table('inventory_standard_cost_settlements')->exists()) {
            throw new RuntimeException('Standard cost versions, decisions and accounting lineage must be preserved.');
        }
        Schema::drop('inventory_standard_cost_settlements');
        Schema::drop('inventory_cost_standards');
    }
};
