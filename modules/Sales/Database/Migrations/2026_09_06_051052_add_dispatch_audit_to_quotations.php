<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotations', function (Blueprint $table): void {
            $table->foreignId('sent_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('sent_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('quotations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('sent_by');
            $table->dropColumn('sent_at');
        });
    }
};
