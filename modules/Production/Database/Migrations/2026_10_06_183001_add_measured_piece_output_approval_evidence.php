<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_piece_approvals', function (Blueprint $table): void {
            $table->json('output_evidence_snapshot')->nullable();
            $table->string('output_evidence_seal', 64)->nullable();
            $table->timestamp('output_completed_at')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('production_piece_approvals')->whereNotNull('output_evidence_snapshot')->exists()) {
            throw new RuntimeException('Measured piece-output payroll evidence must be preserved.');
        }
        Schema::table('production_piece_approvals', function (Blueprint $table): void {
            $table->dropColumn(['output_evidence_snapshot', 'output_evidence_seal', 'output_completed_at']);
        });
    }
};
