<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('fixed_assets') || Schema::hasColumn('fixed_assets', 'branch_hall_id')) {
            return;
        }

        Schema::table('fixed_assets', function (Blueprint $table): void {
            $table->foreignId('branch_hall_id')
                ->nullable()
                ->after('branch_id')
                ->constrained('branch_halls')
                ->nullOnDelete();
            $table->index(['company_id', 'branch_hall_id']);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('fixed_assets') || ! Schema::hasColumn('fixed_assets', 'branch_hall_id')) {
            return;
        }

        Schema::table('fixed_assets', function (Blueprint $table): void {
            $table->dropIndex(['company_id', 'branch_hall_id']);
            $table->dropConstrainedForeignId('branch_hall_id');
        });
    }
};
