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
        Schema::table('quality_inspections', function (Blueprint $table): void {
            $table->foreignId('parent_inspection_id')->nullable()->after('id')->constrained('quality_inspections')->restrictOnDelete();
            $table->foreignId('root_inspection_id')->nullable()->after('parent_inspection_id')->constrained('quality_inspections')->restrictOnDelete();
            $table->unsignedInteger('reinspection_number')->default(0)->after('version');
            $table->foreignId('requested_by')->nullable()->after('sampled_at')->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at')->nullable()->after('requested_by');
            $table->foreignId('received_by')->nullable()->after('requested_at')->constrained('users')->nullOnDelete();
            $table->timestamp('received_at')->nullable()->after('received_by');
            $table->foreignId('started_by')->nullable()->after('received_at')->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable()->after('started_by');
            $table->foreignId('closed_by')->nullable()->after('released_by')->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable()->after('closed_by');
            $table->text('close_notes')->nullable()->after('closed_at');
            $table->index(['root_inspection_id', 'reinspection_number'], 'quality_inspections_root_reinspection_index');
            $table->index(['company_id', 'financial_period_id', 'branch_id', 'requested_at'], 'quality_inspections_context_requested_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('quality_inspections', function (Blueprint $table): void {
            $table->dropIndex('quality_inspections_context_requested_index');
            $table->dropIndex('quality_inspections_root_reinspection_index');
            $table->dropConstrainedForeignId('closed_by');
            $table->dropConstrainedForeignId('started_by');
            $table->dropConstrainedForeignId('received_by');
            $table->dropConstrainedForeignId('requested_by');
            $table->dropConstrainedForeignId('root_inspection_id');
            $table->dropConstrainedForeignId('parent_inspection_id');
            $table->dropColumn(['close_notes', 'closed_at', 'started_at', 'received_at', 'requested_at', 'reinspection_number']);
        });
    }
};
