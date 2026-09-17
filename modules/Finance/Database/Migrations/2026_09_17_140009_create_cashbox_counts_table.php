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
        Schema::create('cashbox_counts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('doc_number');
            $table->string('doc_num', 100);
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('cashbox_id')->constrained('cashboxes')->restrictOnDelete();
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->date('count_date')->index();
            $table->decimal('book_balance', 20, 4);
            $table->decimal('actual_amount', 20, 4);
            $table->decimal('variance', 20, 4);
            $table->string('status', 20)->default('saved')->index();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reopened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reopened_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'doc_number'], 'cashbox_counts_company_number_unique');
            $table->unique(['company_id', 'doc_num'], 'cashbox_counts_company_doc_unique');
            $table->index(['company_id', 'branch_id', 'count_date'], 'cashbox_counts_scope_date_index');
            $table->index(['cashbox_id', 'currency_id', 'count_date'], 'cashbox_counts_balance_scope_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cashbox_counts');
    }
};
