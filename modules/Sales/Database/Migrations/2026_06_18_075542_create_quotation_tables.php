<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('doc_number')->nullable()->index();
            $table->string('doc_num')->nullable()->index();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->restrictOnDelete();
            $table->string('quotation_type')->default('standard')->index();
            $table->string('project_name')->nullable();
            $table->string('subject')->nullable();
            $table->date('quotation_date')->index();
            $table->date('valid_until')->nullable()->index();
            $table->foreignId('currency_id')->nullable()->constrained('currencies')->restrictOnDelete();
            $table->decimal('exchange_rate', 18, 6)->default(1);
            $table->foreignId('sales_person_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('current_revision_id')->nullable();
            $table->string('status')->default('draft')->index();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('restored_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('restored_at')->nullable();
            $table->timestamps();
            $table->softDeletes()->index();

            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'quotation_date']);
            $table->index(['company_id', 'customer_id']);
        });

        Schema::create('quotation_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quotation_id')->constrained('quotations')->cascadeOnDelete();
            $table->unsignedInteger('revision_number');
            $table->string('revision_code')->index();
            $table->date('revision_date')->index();
            $table->string('status')->default('draft')->index();
            $table->text('change_reason')->nullable();
            $table->text('customer_feedback')->nullable();
            $table->decimal('subtotal', 18, 4)->default(0);
            $table->string('discount_type')->nullable();
            $table->decimal('discount_value', 18, 4)->default(0);
            $table->decimal('discount_amount', 18, 4)->default(0);
            $table->decimal('tax_amount', 18, 4)->default(0);
            $table->decimal('total', 18, 4)->default(0);
            $table->longText('notes_snapshot')->nullable();
            $table->longText('terms_snapshot')->nullable();
            $table->longText('payment_terms_snapshot')->nullable();
            $table->longText('execution_terms_snapshot')->nullable();
            $table->longText('warranty_terms_snapshot')->nullable();
            $table->longText('technical_notes_snapshot')->nullable();
            $table->longText('delivery_terms_snapshot')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['quotation_id', 'revision_number']);
            $table->index(['quotation_id', 'status']);
        });

        Schema::table('quotations', function (Blueprint $table): void {
            $table->foreign('current_revision_id')
                ->references('id')
                ->on('quotation_revisions')
                ->nullOnDelete();
        });

        Schema::create('quotation_revision_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quotation_revision_id')->constrained('quotation_revisions')->cascadeOnDelete();
            $table->unsignedInteger('line_number');
            $table->foreignId('product_id')->nullable()->constrained('products')->restrictOnDelete();
            $table->unsignedBigInteger('item_id')->nullable()->index();
            $table->text('description')->nullable();
            $table->foreignId('unit_id')->nullable()->constrained('item_units')->restrictOnDelete();
            $table->decimal('quantity', 18, 4)->default(0);
            $table->decimal('unit_price', 18, 4)->default(0);
            $table->string('discount_type')->nullable();
            $table->decimal('discount_value', 18, 4)->default(0);
            $table->decimal('discount_amount', 18, 4)->default(0);
            $table->decimal('tax_rate', 9, 4)->default(0);
            $table->decimal('tax_amount', 18, 4)->default(0);
            $table->decimal('line_total', 18, 4)->default(0);
            $table->text('notes')->nullable();
            $table->string('product_name_snapshot')->nullable();
            $table->string('unit_name_snapshot')->nullable();
            $table->longText('specs_snapshot')->nullable();
            $table->timestamps();

            $table->index(['quotation_revision_id', 'line_number']);
        });

        Schema::create('quotation_payment_milestones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quotation_revision_id')->constrained('quotation_revisions')->cascadeOnDelete();
            $table->unsignedInteger('line_number');
            $table->string('title');
            $table->text('description')->nullable();
            $table->decimal('percentage', 9, 4)->nullable();
            $table->decimal('amount', 18, 4)->nullable();
            $table->string('due_type')->nullable()->index();
            $table->date('due_date')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['quotation_revision_id', 'line_number']);
        });

        Schema::create('quotation_execution_schedule_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quotation_revision_id')->constrained('quotation_revisions')->cascadeOnDelete();
            $table->unsignedInteger('line_number');
            $table->string('phase_name');
            $table->text('description')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->unsignedInteger('duration_days')->nullable();
            $table->string('responsibility')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['quotation_revision_id', 'line_number']);
        });

        Schema::create('quotation_attachments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_uuid')->unique();
            $table->foreignId('quotation_id')->constrained('quotations')->cascadeOnDelete();
            $table->foreignId('archive_file_id')->nullable()->constrained('archive_files')->nullOnDelete();
            $table->string('disk', 50)->default('local');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['quotation_id', 'created_at']);
        });

        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            $this->createActiveUniqueIndexes();
        }
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            $grammar = DB::getQueryGrammar();

            foreach ([
                'quotations_company_doc_number_unique_active',
                'quotations_company_doc_num_unique_active',
            ] as $index) {
                DB::statement('DROP INDEX IF EXISTS '.$grammar->wrap($index));
            }
        }

        Schema::dropIfExists('quotation_attachments');
        Schema::dropIfExists('quotation_execution_schedule_lines');
        Schema::dropIfExists('quotation_payment_milestones');
        Schema::dropIfExists('quotation_revision_lines');
        Schema::table('quotations', function (Blueprint $table): void {
            $table->dropForeign(['current_revision_id']);
        });
        Schema::dropIfExists('quotation_revisions');
        Schema::dropIfExists('quotations');
    }

    private function createActiveUniqueIndexes(): void
    {
        $grammar = DB::getQueryGrammar();
        $table = $grammar->wrapTable('quotations');
        $deletedAt = $grammar->wrap('deleted_at');
        $companyId = $grammar->wrap('company_id');

        foreach (['doc_number', 'doc_num'] as $column) {
            $wrappedColumn = $grammar->wrap($column);
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS '.$grammar->wrap("quotations_company_{$column}_unique_active")." ON {$table} ({$companyId}, {$wrappedColumn}) WHERE {$deletedAt} IS NULL AND {$wrappedColumn} IS NOT NULL");
        }
    }
};
