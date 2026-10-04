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
        Schema::table('sales_orders', function (Blueprint $table): void {
            $table->json('reopen_snapshot')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('sales_orders')->whereNotNull('reopen_snapshot')->exists()) {
            throw new RuntimeException('Cannot remove the provenance of an active sales order amendment.');
        }

        Schema::table('sales_orders', function (Blueprint $table): void {
            $table->dropColumn('reopen_snapshot');
        });
    }
};
