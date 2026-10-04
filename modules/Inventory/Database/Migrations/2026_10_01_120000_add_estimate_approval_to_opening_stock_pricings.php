<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_opening_stock_pricings', function (Blueprint $table): void {
            $table->string('pricing_basis', 24)->default('documented')->index();
            $table->string('source_reference', 160)->nullable();
            $table->text('estimate_basis_note')->nullable();
            $table->string('approval_reference', 160)->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('source_file_path', 255)->nullable();
            $table->string('source_file_sha256', 64)->nullable();
            $table->string('source_file_name', 180)->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('inventory_opening_stock_pricings')->where('pricing_basis', 'estimate')->exists()) {
            throw new RuntimeException('Estimated opening-stock pricing must be reviewed before this migration can be reversed.');
        }

        Schema::table('inventory_opening_stock_pricings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn(['pricing_basis', 'source_reference', 'estimate_basis_note', 'approval_reference', 'approved_at', 'source_file_path', 'source_file_sha256', 'source_file_name']);
        });
    }
};
