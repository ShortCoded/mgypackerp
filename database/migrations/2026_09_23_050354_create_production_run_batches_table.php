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
        Schema::create('production_run_batches', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('batch_number', 100);
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('financial_period_id')->constrained('financial_periods')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('production_order_id')->constrained('production_orders')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'batch_number'], 'production_run_batches_company_number_unique');
            $table->index(['production_order_id', 'created_at'], 'production_run_batches_order_created_index');
        });

        Schema::table('production_runs', function (Blueprint $table): void {
            $table->foreignId('production_run_batch_id')->nullable()->after('production_order_id')
                ->constrained('production_run_batches')->restrictOnDelete();
            $table->index(['production_run_batch_id', 'status'], 'production_runs_batch_status_index');
        });

        Schema::table('inventory_documents', function (Blueprint $table): void {
            $table->foreignId('production_run_batch_id')->nullable()->after('production_run_id')
                ->constrained('production_run_batches')->restrictOnDelete();
            $table->index(['production_run_batch_id', 'document_type', 'status'], 'inventory_documents_batch_type_status_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('production_run_batches') && DB::table('production_run_batches')->exists()) {
            throw new RuntimeException('Production run batches contain operational records and cannot be rolled back without losing inventory traceability.');
        }

        Schema::table('inventory_documents', function (Blueprint $table): void {
            $table->dropIndex('inventory_documents_batch_type_status_index');
            $table->dropConstrainedForeignId('production_run_batch_id');
        });

        Schema::table('production_runs', function (Blueprint $table): void {
            $table->dropIndex('production_runs_batch_status_index');
            $table->dropConstrainedForeignId('production_run_batch_id');
        });

        Schema::dropIfExists('production_run_batches');
    }
};
