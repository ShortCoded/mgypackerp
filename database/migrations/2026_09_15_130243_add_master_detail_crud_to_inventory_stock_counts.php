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
        Schema::table('inventory_stock_counts', function (Blueprint $table) {
            $table->foreignId('updated_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
            $table->foreignId('restored_by')->nullable()->after('deleted_by')->constrained('users')->nullOnDelete();
            $table->timestamp('restored_at')->nullable()->after('restored_by');
            $table->softDeletes()->index();
        });

        Schema::table('inventory_stock_count_lines', function (Blueprint $table) {
            $table->foreignId('created_by')->nullable()->after('notes')->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            $table->boolean('deleted_with_document')->default(false)->after('updated_by');
            $table->foreignId('deleted_by')->nullable()->after('updated_by')->constrained('users')->nullOnDelete();
            $table->softDeletes()->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('inventory_stock_count_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('deleted_by');
            $table->dropColumn('deleted_with_document');
            $table->dropConstrainedForeignId('updated_by');
            $table->dropConstrainedForeignId('created_by');
            $table->dropSoftDeletes();
        });

        Schema::table('inventory_stock_counts', function (Blueprint $table) {
            $table->dropColumn('restored_at');
            $table->dropConstrainedForeignId('restored_by');
            $table->dropConstrainedForeignId('deleted_by');
            $table->dropConstrainedForeignId('updated_by');
            $table->dropSoftDeletes();
        });
    }
};
