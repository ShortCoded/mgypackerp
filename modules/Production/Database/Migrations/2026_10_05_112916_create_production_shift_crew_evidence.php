<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());
        $schema->create('production_shift_crews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('production_shift_id')->constrained()->restrictOnDelete();
            $table->foreignId('fixed_asset_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('production_machine_id')->nullable()->constrained()->restrictOnDelete();
            $table->json('crew_snapshot');
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'branch_id', 'fixed_asset_id', 'production_shift_id'], 'production_shift_asset_crew_unique');
            $table->unique(['company_id', 'branch_id', 'production_machine_id', 'production_shift_id'], 'production_shift_machine_crew_unique');
        });
        $schema->create('production_shift_entries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('financial_period_id')->constrained()->restrictOnDelete();
            $table->foreignId('production_run_id')->constrained()->restrictOnDelete();
            $table->foreignId('production_shift_id')->constrained()->restrictOnDelete();
            $table->date('work_date');
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->decimal('downtime_minutes', 12, 4)->default(0);
            $table->json('equipment_snapshot');
            $table->json('crew_snapshot');
            $table->json('sheet_fields');
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['production_run_id', 'production_shift_id', 'work_date'], 'production_daily_shift_run_unique');
        });
        $schema->table('production_progress_entries', function (Blueprint $table): void {
            $table->foreignId('production_shift_entry_id')->nullable()->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());
        $connection = DB::connection($this->getConnection());
        if ($connection->table('production_shift_entries')->exists() || $connection->table('production_shift_crews')->exists()) {
            throw new RuntimeException('Recorded shift evidence must be preserved.');
        }
        $schema->table('production_progress_entries', fn (Blueprint $table) => $table->dropConstrainedForeignId('production_shift_entry_id'));
        $schema->dropIfExists('production_shift_entries');
        $schema->dropIfExists('production_shift_crews');
    }
};
