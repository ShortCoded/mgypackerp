<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('customer_receipt_allocations', 'settlement_evidence')) {
            return;
        }
        Schema::table('customer_receipt_allocations', function (Blueprint $table) {
            $table->json('settlement_evidence')->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('customer_receipt_allocations', 'settlement_evidence')) {
            return;
        }
        if (DB::table('customer_receipt_allocations')->whereNotNull('settlement_evidence')->exists()) {
            throw new RuntimeException('Receipt settlement evidence cannot be discarded by migration rollback.');
        }
        Schema::table('customer_receipt_allocations', function (Blueprint $table) {
            $table->dropColumn('settlement_evidence');
        });
    }
};
