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
        Schema::table('inventory_reservations', function (Blueprint $table): void {
            $table->foreignId('production_material_request_line_id')
                ->nullable()
                ->after('production_material_requirement_id')
                ->constrained('production_material_request_lines')
                ->restrictOnDelete();
            $table->index(['production_material_request_line_id', 'status'], 'inventory_reservations_request_line_status_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('inventory_reservations', function (Blueprint $table): void {
            $table->dropIndex('inventory_reservations_request_line_status_index');
            $table->dropConstrainedForeignId('production_material_request_line_id');
        });
    }
};
