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
        Schema::table('customer_invoices', function (Blueprint $table): void {
            $table->json('credit_application_snapshot')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('customer_invoices')->whereNotNull('credit_application_snapshot')->exists()) {
            throw new RuntimeException('Credit application snapshots contain audit evidence and cannot be discarded.');
        }

        Schema::table('customer_invoices', function (Blueprint $table): void {
            $table->dropColumn('credit_application_snapshot');
        });
    }
};
