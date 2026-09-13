<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('price_lists', function (Blueprint $table): void {
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('restored_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('restored_at')->nullable();
            $table->index(['company_id', 'deleted_at'], 'price_lists_company_deleted_index');
        });
    }

    public function down(): void
    {
        Schema::table('price_lists', function (Blueprint $table): void {
            $table->dropIndex('price_lists_company_deleted_index');
            $table->dropConstrainedForeignId('restored_by');
            $table->dropConstrainedForeignId('deleted_by');
            $table->dropColumn('restored_at');
        });
    }
};
