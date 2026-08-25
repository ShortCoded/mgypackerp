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
        Schema::table('cheques', function (Blueprint $table): void {
            $table->unsignedInteger('clearing_revision')->default(0);
            $table->timestamp('clearing_reversed_at')->nullable();
            $table->foreignId('clearing_reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('clearing_reversal_reason')->nullable();
        });

        Schema::create('cheque_clearing_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cheque_id')->constrained('cheques')->restrictOnDelete();
            $table->unsignedInteger('sequence');
            $table->date('clearing_date');
            $table->string('status', 30)->default('cleared');
            $table->foreignId('clearing_journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('reversal_journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->text('reversal_reason')->nullable();
            $table->foreignId('cleared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cleared_at');
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->timestamps();

            $table->unique(['cheque_id', 'sequence']);
            $table->index(['cheque_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cheque_clearing_events');
        Schema::table('cheques', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('clearing_reversed_by');
            $table->dropColumn(['clearing_revision', 'clearing_reversed_at', 'clearing_reversal_reason']);
        });
    }
};
