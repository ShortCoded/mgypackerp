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
        Schema::table('production_stages', function (Blueprint $table): void {
            $table->foreignId('branch_id')
                ->nullable()
                ->after('company_id')
                ->constrained('branches')
                ->restrictOnDelete();
            $table->index(['company_id', 'branch_id', 'status', 'display_order'], 'production_stages_branch_context_order_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('production_stages', function (Blueprint $table): void {
            $table->dropIndex('production_stages_branch_context_order_index');
            $table->dropConstrainedForeignId('branch_id');
        });
    }
};
