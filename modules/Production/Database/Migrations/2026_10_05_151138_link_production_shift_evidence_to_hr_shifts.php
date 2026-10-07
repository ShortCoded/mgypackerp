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
        foreach (['production_shift_crews', 'production_shift_entries'] as $name) {
            $schema->table($name, function (Blueprint $table): void {
                $table->unsignedBigInteger('production_shift_id')->nullable()->change();
                $table->foreignId('hr_shift_id')->nullable()->constrained('hr_shifts')->restrictOnDelete();
            });
        }
        $schema->table('production_shift_crews', function (Blueprint $table): void {
            $table->unique(['company_id', 'branch_id', 'fixed_asset_id', 'hr_shift_id'], 'production_hr_shift_asset_crew_unique');
            $table->unique(['company_id', 'branch_id', 'production_machine_id', 'hr_shift_id'], 'production_hr_shift_machine_crew_unique');
        });
        $schema->table('production_shift_entries', fn (Blueprint $table) => $table->unique(['production_run_id', 'hr_shift_id', 'work_date'], 'production_daily_hr_shift_run_unique'));
        $schema->table('production_runs', fn (Blueprint $table) => $table->boolean('uses_hr_shift_evidence')->default(false));
    }

    public function down(): void
    {
        $connection = DB::connection($this->getConnection());
        if ($connection->table('production_shift_entries')->whereNotNull('hr_shift_id')->exists()
            || $connection->table('production_shift_crews')->whereNotNull('hr_shift_id')->exists()) {
            throw new RuntimeException('Recorded HR shift references must be preserved.');
        }
        $schema = Schema::connection($this->getConnection());
        $schema->table('production_runs', fn (Blueprint $table) => $table->dropColumn('uses_hr_shift_evidence'));
        $schema->table('production_shift_crews', function (Blueprint $table): void {
            $table->dropUnique('production_hr_shift_asset_crew_unique');
            $table->dropUnique('production_hr_shift_machine_crew_unique');
        });
        $schema->table('production_shift_entries', fn (Blueprint $table) => $table->dropUnique('production_daily_hr_shift_run_unique'));
        foreach (['production_shift_crews', 'production_shift_entries'] as $name) {
            $schema->table($name, function (Blueprint $table): void {
                $table->dropConstrainedForeignId('hr_shift_id');
                $table->unsignedBigInteger('production_shift_id')->nullable(false)->change();
            });
        }
    }
};
