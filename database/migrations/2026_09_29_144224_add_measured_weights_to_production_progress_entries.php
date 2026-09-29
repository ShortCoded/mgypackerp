<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_progress_entries', function (Blueprint $table): void {
            $table->decimal('good_weight_kg', 20, 8)->nullable()->after('good_base_quantity');
            $table->decimal('production_scrap_weight_kg', 20, 8)->nullable()->after('scrap_base_quantity');
        });
    }

    public function down(): void
    {
        Schema::table('production_progress_entries', function (Blueprint $table): void {
            $table->dropColumn(['good_weight_kg', 'production_scrap_weight_kg']);
        });
    }
};
