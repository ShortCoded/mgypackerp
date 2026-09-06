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
        Schema::table('purchase_receipts', function (Blueprint $table) {
            Schema::table('unpriced_inventory_receipts', function (Blueprint $table): void {
                $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('reversed_at')->nullable();
                $table->text('reversal_reason')->nullable();
            });
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('purchase_receipts', function (Blueprint $table) {
            Schema::table('unpriced_inventory_receipts', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('reversed_by');
                $table->dropColumn(['reversed_at', 'reversal_reason']);
            });
        });
    }
};
