<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('customer_credit_allocations', function (Blueprint $table): void {
            $table->json('reversal_effect_snapshot')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('customer_credit_allocations')->whereNotNull('reversal_effect_snapshot')->exists()) {
            throw new RuntimeException('Customer credit allocation reversal evidence cannot be discarded.');
        }

        Schema::table('customer_credit_allocations', function (Blueprint $table): void {
            $table->dropColumn('reversal_effect_snapshot');
        });
    }
};
