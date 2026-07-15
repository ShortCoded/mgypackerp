<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('item_units')) {
            return;
        }

        Schema::table('item_units', function (Blueprint $table): void {
            if (! Schema::hasColumn('item_units', 'equivalent_value')) {
                $table->decimal('equivalent_value', 18, 6)->nullable();
            }

            if (! Schema::hasColumn('item_units', 'equivalent_unit_id')) {
                $table->foreignId('equivalent_unit_id')
                    ->nullable()
                    ->constrained('item_units')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('item_units')) {
            return;
        }

        Schema::table('item_units', function (Blueprint $table): void {
            if (Schema::hasColumn('item_units', 'equivalent_unit_id')) {
                $table->dropConstrainedForeignId('equivalent_unit_id');
            }

            if (Schema::hasColumn('item_units', 'equivalent_value')) {
                $table->dropColumn('equivalent_value');
            }
        });
    }
};
