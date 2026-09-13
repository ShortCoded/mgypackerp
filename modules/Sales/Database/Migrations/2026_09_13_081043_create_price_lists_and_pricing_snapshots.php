<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_lists', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('doc_number');
            $table->string('doc_num');
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->restrictOnDelete();
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->date('price_list_date');
            $table->date('valid_from');
            $table->date('valid_until')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_id', 'doc_num']);
            $table->unique(['company_id', 'doc_number']);
            $table->index(['company_id', 'customer_id', 'currency_id', 'valid_from'], 'price_lists_resolution_index');
            $table->index(['company_id', 'currency_id', 'valid_until'], 'price_lists_expiry_index');
        });

        Schema::create('price_list_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('price_list_id')->constrained('price_lists')->cascadeOnDelete();
            $table->unsignedInteger('line_number');
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->decimal('unit_price', 20, 4);
            $table->string('allowed_discount_type')->nullable();
            $table->decimal('allowed_discount_value', 20, 4)->default(0);
            $table->timestamps();
            $table->unique(['price_list_id', 'product_id']);
            $table->index(['product_id', 'price_list_id']);
        });

        foreach (['quotation_revision_lines', 'sales_order_lines', 'customer_invoice_lines'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->foreignId('price_list_line_id')->nullable()->constrained('price_list_lines')->nullOnDelete();
                $table->string('allowed_discount_type')->nullable();
                $table->decimal('allowed_discount_value', 20, 4)->default(0);
            });
        }
    }

    public function down(): void
    {
        foreach (['quotation_revision_lines', 'sales_order_lines', 'customer_invoice_lines'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropConstrainedForeignId('price_list_line_id');
                $table->dropColumn(['allowed_discount_type', 'allowed_discount_value']);
            });
        }

        Schema::dropIfExists('price_list_lines');
        Schema::dropIfExists('price_lists');
    }
};
