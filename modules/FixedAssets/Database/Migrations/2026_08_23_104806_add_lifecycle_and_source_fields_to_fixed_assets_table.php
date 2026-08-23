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
        Schema::table('fixed_assets', function (Blueprint $table): void {
            $table->decimal('base_acquisition_value', 18, 4)->nullable()->after('purchase_value');
            $table->string('source_type')->nullable()->after('entry_type')->index();
            $table->unsignedBigInteger('source_id')->nullable()->after('source_type');
            $table->string('source_doc_num')->nullable()->after('source_id')->index();
            $table->timestamp('capitalized_at')->nullable()->after('status');
            $table->foreignId('capitalized_by')->nullable()->after('capitalized_at')->constrained('users')->nullOnDelete();
            $table->date('disposed_at')->nullable()->after('capitalized_by')->index();
            $table->timestamp('locked_at')->nullable()->after('disposed_at');

            $table->index(['company_id', 'source_type', 'source_id']);
            $table->index(['company_id', 'status', 'depreciation_start_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('fixed_assets', function (Blueprint $table): void {
            $table->dropIndex(['company_id', 'source_type', 'source_id']);
            $table->dropIndex(['company_id', 'status', 'depreciation_start_date']);
            $table->dropConstrainedForeignId('capitalized_by');
            $table->dropColumn([
                'base_acquisition_value',
                'source_type',
                'source_id',
                'source_doc_num',
                'capitalized_at',
                'disposed_at',
                'locked_at',
            ]);
        });
    }
};
