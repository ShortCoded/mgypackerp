<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_cancellation_owners', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('financial_period_id')->constrained()->restrictOnDelete();
            $table->foreignId('posting_financial_period_id')->constrained('financial_periods')->restrictOnDelete();
            $table->foreignId('production_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('production_run_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('inventory_document_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('treatment', 40);
            $table->string('status', 20)->default('prepared');
            $table->date('posting_date');
            $table->text('reason');
            $table->text('evidence');
            $table->json('source_snapshot');
            $table->json('execution_snapshot')->nullable();
            $table->string('proposal_seal', 64);
            $table->string('execution_seal', 64)->nullable();
            $table->foreignId('prepared_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'production_order_id', 'production_run_id', 'status'], 'production_cancellation_owner_scope');
        });
    }

    public function down(): void
    {
        if (DB::table('production_cancellation_owners')->exists()) {
            throw new RuntimeException('Production cancellation owner history must be preserved.');
        }
        Schema::dropIfExists('production_cancellation_owners');
    }
};
