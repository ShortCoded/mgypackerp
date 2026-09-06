<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_requests', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('doc_number');
            $table->string('doc_num', 100);
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('financial_period_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_store_id')->nullable()->constrained('branch_stores')->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('currency_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('sales_employee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('request_date');
            $table->date('required_delivery_date')->nullable();
            $table->string('priority', 20)->default('normal');
            $table->string('customer_reference', 160)->nullable();
            $table->decimal('exchange_rate', 20, 6)->default(1);
            $table->string('status', 30)->default('draft');
            $table->text('notes')->nullable();
            $table->json('print_identity_snapshot')->nullable();
            $table->json('status_history')->nullable();
            foreach (['created', 'updated', 'submitted', 'approved', 'rejected', 'cancelled', 'closed'] as $event) {
                $table->foreignId($event.'_by')->nullable()->constrained('users')->nullOnDelete();
                if (! in_array($event, ['created', 'updated'], true)) {
                    $table->timestamp($event.'_at')->nullable();
                }
            }
            $table->text('status_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_id', 'doc_num']);
            $table->index(['company_id', 'branch_id', 'status', 'request_date'], 'sales_requests_work_queue');
        });
        Schema::create('sales_request_lines', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('sales_request_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('line_number');
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('item_units')->restrictOnDelete();
            $table->text('description')->nullable();
            $table->decimal('quantity', 20, 8);
            $table->decimal('conversion_factor', 20, 8)->default(1);
            $table->decimal('base_quantity', 20, 8);
            $table->decimal('converted_quantity', 20, 8)->default(0);
            $table->decimal('unit_price', 20, 4)->nullable();
            $table->json('specifications')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['sales_request_id', 'line_number']);
        });
        foreach (['quotations', 'sales_orders'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->foreignId('sales_request_id')->nullable()->constrained()->restrictOnDelete());
        }
        foreach (['quotation_revision_lines', 'sales_order_lines'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->foreignId('sales_request_line_id')->nullable()->constrained()->restrictOnDelete());
        }
    }

    public function down(): void
    {
        foreach (['quotation_revision_lines', 'sales_order_lines'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropConstrainedForeignId('sales_request_line_id'));
        }
        foreach (['quotations', 'sales_orders'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropConstrainedForeignId('sales_request_id'));
        }
        Schema::dropIfExists('sales_request_lines');
        Schema::dropIfExists('sales_requests');
    }
};
