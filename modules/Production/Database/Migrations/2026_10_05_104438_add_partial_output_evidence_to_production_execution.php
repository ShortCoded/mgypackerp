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
        $schema = Schema::connection($this->getConnection());
        $schema->table('production_runs', function (Blueprint $table): void {
            $table->string('material_accounting_mode', 30)->default('legacy');
            $table->json('material_evidence_policy')->nullable();
        });
        $schema->table('production_progress_entries', function (Blueprint $table): void {
            $table->json('material_evidence')->nullable();
            $table->json('material_documents')->nullable();
        });
        $schema->create('production_quality_output_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('production_run_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('correction_sequence')->default(0);
            $table->unsignedInteger('batch_number');
            $table->decimal('base_quantity', 20, 8);
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('withdrawn_at')->nullable();
            $table->timestamps();
            $table->unique(['production_run_id', 'correction_sequence', 'batch_number'], 'production_quality_batch_sequence_unique');
        });
        $schema->table('quality_inspections', function (Blueprint $table): void {
            $table->foreignId('production_quality_output_batch_id')->nullable()->constrained('production_quality_output_batches')->restrictOnDelete();
            $table->decimal('accepted_base_quantity', 20, 8)->nullable();
        });
        $schema->create('production_quality_receipt_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('production_quality_output_batch_id')->constrained('production_quality_output_batches')->restrictOnDelete();
            $table->foreignId('inventory_document_id')->constrained()->restrictOnDelete();
            $table->decimal('base_quantity', 20, 8);
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['production_quality_output_batch_id', 'inventory_document_id'], 'production_quality_receipt_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());
        $connection = DB::connection($this->getConnection());
        if ($connection->table('production_runs')->where('material_accounting_mode', 'output_evidence')->exists()
            || $connection->table('production_quality_output_batches')->exists()) {
            throw new RuntimeException('Recorded production output evidence must be preserved.');
        }
        $schema->dropIfExists('production_quality_receipt_allocations');
        $schema->table('quality_inspections', fn (Blueprint $table) => $table->dropConstrainedForeignId('production_quality_output_batch_id'));
        $schema->table('quality_inspections', fn (Blueprint $table) => $table->dropColumn('accepted_base_quantity'));
        $schema->dropIfExists('production_quality_output_batches');
        $schema->table('production_progress_entries', fn (Blueprint $table) => $table->dropColumn(['material_evidence', 'material_documents']));
        $schema->table('production_runs', fn (Blueprint $table) => $table->dropColumn(['material_accounting_mode', 'material_evidence_policy']));
    }
};
