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
        if (
            ! Schema::hasTable('fixed_assets')
            || ! Schema::hasTable('cost_centers')
            || ! Schema::hasColumn('fixed_assets', 'cost_center_id')
        ) {
            return;
        }

        if ($this->foreignKeyExists('fixed_assets', 'cost_center_id')) {
            return;
        }

        Schema::table('fixed_assets', function (Blueprint $table): void {
            $table->foreign('cost_center_id', 'fixed_assets_cost_center_id_foreign')
                ->references('id')
                ->on('cost_centers')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('fixed_assets') || ! $this->foreignKeyExists('fixed_assets', 'cost_center_id')) {
            return;
        }

        Schema::table('fixed_assets', function (Blueprint $table): void {
            $table->dropForeign('fixed_assets_cost_center_id_foreign');
        });
    }

    private function foreignKeyExists(string $table, string $column): bool
    {
        if (! Schema::hasTable($table)) {
            return false;
        }

        foreach (Schema::getForeignKeys($table) as $foreignKey) {
            if (in_array($column, $foreignKey['columns'] ?? [], true)) {
                return true;
            }
        }

        return false;
    }
};
