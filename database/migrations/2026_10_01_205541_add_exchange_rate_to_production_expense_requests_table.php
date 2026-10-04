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
        Schema::table('production_expense_requests', function (Blueprint $table): void {
            $table->decimal('exchange_rate', 18, 6)->nullable()->after('currency_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('production_expense_requests', function (Blueprint $table): void {
            $table->dropColumn('exchange_rate');
        });
    }
};
