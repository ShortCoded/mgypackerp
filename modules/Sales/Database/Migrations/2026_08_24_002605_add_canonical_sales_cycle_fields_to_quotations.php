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
        Schema::table('quotations', function (Blueprint $table): void {
            $table->foreignId('branch_id')->nullable()->after('company_id')->constrained('branches')->restrictOnDelete();
            $table->string('customer_reference', 160)->nullable()->after('customer_id');
            $table->text('internal_notes')->nullable()->after('notes');
            $table->json('print_identity_snapshot')->nullable()->after('internal_notes');
        });

        Schema::table('quotation_revision_lines', function (Blueprint $table): void {
            $table->decimal('conversion_factor', 20, 8)->default(1)->after('quantity');
            $table->decimal('base_quantity', 20, 8)->default(0)->after('conversion_factor');
            $table->date('requested_date')->nullable()->after('line_total');
            $table->json('specifications')->nullable()->after('specs_snapshot');
            $table->text('warehouse_notes')->nullable()->after('specifications');
            $table->text('production_notes')->nullable()->after('warehouse_notes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('quotation_revision_lines', function (Blueprint $table): void {
            $table->dropColumn([
                'conversion_factor',
                'base_quantity',
                'requested_date',
                'specifications',
                'warehouse_notes',
                'production_notes',
            ]);
        });

        Schema::table('quotations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('branch_id');
            $table->dropColumn(['customer_reference', 'internal_notes', 'print_identity_snapshot']);
        });
    }
};
