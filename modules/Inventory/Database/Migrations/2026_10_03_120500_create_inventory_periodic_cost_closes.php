<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_periodic_cost_closes', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('scope_branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->foreignId('scope_store_id')->nullable()->constrained('branch_stores')->restrictOnDelete();
            $table->foreignId('financial_period_id')->constrained('financial_periods')->restrictOnDelete();
            $table->foreignId('posting_period_id')->constrained('financial_periods')->restrictOnDelete();
            $table->foreignId('counterpart_account_id')->constrained('accounts')->restrictOnDelete();
            $table->string('doc_num', 100);
            $table->date('from_date');
            $table->date('to_date');
            $table->date('posting_date');
            $table->string('status', 20);
            $table->text('reason');
            $table->json('scope_snapshot');
            $table->json('impact_snapshot')->nullable();
            $table->string('impact_sha256', 64)->nullable();
            $table->foreignId('prepared_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('prepared_at');
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('approval_reference', 500)->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'doc_num']);
            $table->index(['company_id', 'status', 'to_date']);
        });
    }

    public function down(): void
    {
        if (DB::table('inventory_periodic_cost_closes')->exists()) {
            throw new RuntimeException('Periodic cost inputs, decisions and accounting lineage must be preserved.');
        }
        Schema::drop('inventory_periodic_cost_closes');
    }
};
