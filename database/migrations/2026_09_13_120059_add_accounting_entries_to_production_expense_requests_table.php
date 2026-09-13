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
            $table->foreignId('journal_entry_id')->nullable()->after('cash_voucher_id')->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('reversal_journal_entry_id')->nullable()->after('journal_entry_id')->constrained('journal_entries')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('production_expense_requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reversal_journal_entry_id');
            $table->dropConstrainedForeignId('journal_entry_id');
        });
    }
};
