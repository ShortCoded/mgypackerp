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
        Schema::table('customer_credit_refunds', function (Blueprint $table): void {
            $table->foreignId('reversal_journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->string('recovery_reference')->nullable();
            $table->json('reversal_effect_snapshot')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('customer_credit_refunds')->where('status', 'reversed')->orWhereNotNull('reversal_journal_entry_id')->exists()) {
            throw new RuntimeException('Customer credit refund reversal evidence cannot be discarded.');
        }

        Schema::table('customer_credit_refunds', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reversal_journal_entry_id');
            $table->dropConstrainedForeignId('reversed_by');
            $table->dropColumn(['reversed_at', 'reversal_reason', 'recovery_reference', 'reversal_effect_snapshot']);
        });
    }
};
