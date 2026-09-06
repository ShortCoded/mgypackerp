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
        Schema::table('purchase_requisitions', function (Blueprint $table): void {
            $table->foreignId('suggested_supplier_id')->nullable()->constrained('suppliers')->restrictOnDelete();
            $table->unsignedInteger('lead_time_days')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('purchase_requisitions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('suggested_supplier_id');
            $table->dropConstrainedForeignId('submitted_by');
            $table->dropConstrainedForeignId('closed_by');
            $table->dropColumn(['lead_time_days', 'submitted_at', 'closed_at']);
        });
    }
};
