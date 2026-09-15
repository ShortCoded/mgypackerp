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
        Schema::table('maintenance_requests', function (Blueprint $table): void {
            $table->unsignedBigInteger('fixed_asset_id')->nullable()->change();
            $table->foreignId('production_mold_id')->nullable()->after('fixed_asset_id')->constrained('production_molds')->restrictOnDelete();
            $table->foreignId('quality_inspection_id')->nullable()->unique()->after('production_run_id')->constrained('quality_inspections')->restrictOnDelete();
            $table->boolean('is_machine_stopped')->default(false)->after('reported_at');
            $table->index(['quality_inspection_id', 'status'], 'maintenance_requests_quality_status_index');
        });

        Schema::table('maintenance_work_orders', function (Blueprint $table): void {
            $table->unsignedBigInteger('fixed_asset_id')->nullable()->change();
            $table->string('test_result', 30)->nullable()->after('completion_notes')->index();
            $table->string('repair_outcome', 30)->nullable()->after('test_result');
            $table->json('labor_details')->nullable()->after('repair_outcome');
            $table->timestamp('machine_released_at')->nullable()->after('actual_end_at')->index();
            $table->timestamp('follow_up_due_at')->nullable()->after('machine_released_at')->index();
            $table->timestamp('cost_closed_at')->nullable()->after('follow_up_due_at');
        });

        Schema::table('maintenance_material_request_lines', function (Blueprint $table): void {
            $table->decimal('consumed_quantity', 20, 8)->default(0)->after('issued_quantity');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('maintenance_material_request_lines', function (Blueprint $table): void {
            $table->dropColumn('consumed_quantity');
        });

        Schema::table('maintenance_work_orders', function (Blueprint $table): void {
            $table->dropIndex(['test_result']);
            $table->dropIndex(['machine_released_at']);
            $table->dropIndex(['follow_up_due_at']);
            $table->dropColumn([
                'test_result',
                'repair_outcome',
                'labor_details',
                'machine_released_at',
                'follow_up_due_at',
                'cost_closed_at',
            ]);
            $table->unsignedBigInteger('fixed_asset_id')->nullable(false)->change();
        });

        Schema::table('maintenance_requests', function (Blueprint $table): void {
            $table->dropIndex('maintenance_requests_quality_status_index');
            $table->dropConstrainedForeignId('quality_inspection_id');
            $table->dropConstrainedForeignId('production_mold_id');
            $table->dropColumn('is_machine_stopped');
            $table->unsignedBigInteger('fixed_asset_id')->nullable(false)->change();
        });
    }
};
