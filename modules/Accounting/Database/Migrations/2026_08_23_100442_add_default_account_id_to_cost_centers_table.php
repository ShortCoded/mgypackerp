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
        Schema::table('cost_centers', function (Blueprint $table) {
            $table->foreignId('default_account_id')
                ->nullable()
                ->after('parent_id')
                ->constrained('accounts')
                ->restrictOnDelete();
            $table->index(['company_id', 'default_account_id'], 'cost_centers_company_default_account_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cost_centers', function (Blueprint $table) {
            $table->dropIndex('cost_centers_company_default_account_index');
            $table->dropConstrainedForeignId('default_account_id');
        });
    }
};
