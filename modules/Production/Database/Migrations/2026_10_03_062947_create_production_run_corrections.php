<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_run_corrections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('production_run_id')->constrained()->restrictOnDelete();
            $table->foreignId('financial_period_id')->constrained()->restrictOnDelete();
            $table->string('status', 30)->default('prepared');
            $table->text('reason');
            $table->string('fingerprint', 64);
            $table->json('source_snapshot');
            $table->json('corrected_output');
            $table->foreignId('prepared_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'production_run_id', 'status']);
        });
        Schema::table('production_runs', fn (Blueprint $table) => $table->unsignedInteger('correction_sequence')->default(0));
        Schema::table('quality_inspections', fn (Blueprint $table) => $table->unsignedInteger('correction_sequence')->default(0));
        Schema::table('production_progress_entries', fn (Blueprint $table) => $table->foreignId('production_run_correction_id')->nullable()->constrained()->restrictOnDelete());
        Schema::table('production_piece_approvals', function (Blueprint $table): void {
            $table->dropUnique(['production_run_id', 'employee_id']);
            $table->unsignedInteger('correction_sequence')->default(0);
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('production_run_correction_id')->nullable()->constrained()->restrictOnDelete();
            $table->unique(['production_run_id', 'employee_id', 'correction_sequence'], 'production_piece_approval_revision_unique');
        });
    }

    public function down(): void
    {
        if (DB::table('production_run_corrections')->exists()) {
            throw new RuntimeException('Production correction evidence exists; rollback would discard history.');
        }
        Schema::table('production_piece_approvals', function (Blueprint $table): void {
            $table->dropUnique('production_piece_approval_revision_unique');
            $table->dropConstrainedForeignId('production_run_correction_id');
            $table->dropConstrainedForeignId('revoked_by');
            $table->dropColumn(['correction_sequence', 'revoked_at']);
            $table->unique(['production_run_id', 'employee_id']);
        });
        Schema::table('production_progress_entries', fn (Blueprint $table) => $table->dropConstrainedForeignId('production_run_correction_id'));
        Schema::table('quality_inspections', fn (Blueprint $table) => $table->dropColumn('correction_sequence'));
        Schema::table('production_runs', fn (Blueprint $table) => $table->dropColumn('correction_sequence'));
        Schema::dropIfExists('production_run_corrections');
    }
};
