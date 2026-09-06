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
        Schema::table('purchase_invoice_lines', function (Blueprint $table): void {
            $table->string('asset_treatment', 40)->nullable()->after('cost_center_id');
            $table->foreignId('target_fixed_asset_id')->nullable()->after('asset_treatment')->constrained('fixed_assets')->restrictOnDelete();
            $table->date('asset_effective_date')->nullable()->after('target_fixed_asset_id');
            $table->index(['company_id', 'asset_treatment'], 'purchase_invoice_lines_asset_treatment_index');
        });

        Schema::table('fixed_asset_movements', function (Blueprint $table): void {
            $table->string('source_type', 80)->nullable()->after('movement_type');
            $table->unsignedBigInteger('source_id')->nullable()->after('source_type');
            $table->string('source_doc_num', 120)->nullable()->after('source_id');
            $table->unique(['company_id', 'source_type', 'source_id'], 'fixed_asset_movements_source_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('fixed_asset_movements', function (Blueprint $table): void {
            $table->dropUnique('fixed_asset_movements_source_unique');
            $table->dropColumn(['source_type', 'source_id', 'source_doc_num']);
        });

        Schema::table('purchase_invoice_lines', function (Blueprint $table): void {
            $table->dropIndex('purchase_invoice_lines_asset_treatment_index');
            $table->dropConstrainedForeignId('target_fixed_asset_id');
            $table->dropColumn(['asset_treatment', 'asset_effective_date']);
        });
    }
};
