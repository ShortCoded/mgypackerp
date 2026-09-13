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
        Schema::table('customers', function (Blueprint $table): void {
            $table->longText('quotation_terms')->nullable();
            $table->longText('quotation_payment_terms')->nullable();
            $table->longText('quotation_execution_terms')->nullable();
            $table->longText('quotation_warranty_terms')->nullable();
            $table->longText('quotation_delivery_terms')->nullable();
            $table->longText('quotation_technical_notes')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn([
                'quotation_terms',
                'quotation_payment_terms',
                'quotation_execution_terms',
                'quotation_warranty_terms',
                'quotation_delivery_terms',
                'quotation_technical_notes',
            ]);
        });
    }
};
