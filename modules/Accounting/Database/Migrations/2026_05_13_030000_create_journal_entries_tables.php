<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entries', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('doc_number')->nullable()->index();
            $table->string('doc_num')->nullable()->index();
            $table->date('entry_date')->index();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('financial_period_id')->constrained('financial_periods')->restrictOnDelete();
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->decimal('exchange_rate', 18, 6)->default(1);
            $table->text('description')->nullable();
            $table->text('notes')->nullable();
            $table->string('source_type')->nullable()->index();
            $table->unsignedBigInteger('source_id')->nullable()->index();
            $table->string('source_doc_num')->nullable()->index();
            $table->string('status')->default('draft')->index();
            $table->boolean('is_system_generated')->default(false)->index();
            $table->boolean('is_posted')->default(false)->index();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('approved')->default(false)->index();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reversed_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('restored_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('restored_at')->nullable();
            $table->timestamps();
            $table->softDeletes()->index();

            $table->index(['company_id', 'financial_period_id']);
            $table->index(['source_type', 'source_id']);
        });

        Schema::create('journal_entry_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained('journal_entries')->cascadeOnDelete();
            $table->unsignedInteger('line_no');
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->decimal('debit_amount', 18, 4)->default(0);
            $table->decimal('credit_amount', 18, 4)->default(0);
            $table->text('description')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable()->index();
            $table->unsignedBigInteger('supplier_id')->nullable()->index();
            $table->unsignedBigInteger('employee_id')->nullable()->index();
            $table->unsignedBigInteger('bank_account_id')->nullable()->index();
            $table->unsignedBigInteger('cost_center_id')->nullable()->index();
            $table->unsignedBigInteger('branch_id')->nullable()->index();
            $table->timestamps();

            $table->index(['journal_entry_id', 'line_no']);
            $table->index(['account_id', 'branch_id']);
        });

        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            $this->createActiveUniqueIndexes();
        }
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement('DROP INDEX IF EXISTS '.DB::getQueryGrammar()->wrap('journal_entries_doc_number_unique_active'));
            DB::statement('DROP INDEX IF EXISTS '.DB::getQueryGrammar()->wrap('journal_entries_doc_num_unique_active'));
            DB::statement('DROP INDEX IF EXISTS '.DB::getQueryGrammar()->wrap('journal_entries_company_period_doc_num_unique_active'));
        }

        Schema::dropIfExists('journal_entry_lines');
        Schema::dropIfExists('journal_entries');
    }

    private function createActiveUniqueIndexes(): void
    {
        $grammar = DB::getQueryGrammar();
        $table = $grammar->wrapTable('journal_entries');
        $deletedAt = $grammar->wrap('deleted_at');

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS '.$grammar->wrap('journal_entries_company_period_doc_num_unique_active').' ON '.$table.' ('.$grammar->wrap('company_id').', '.$grammar->wrap('financial_period_id').', '.$grammar->wrap('doc_num').') WHERE '.$deletedAt.' IS NULL AND '.$grammar->wrap('doc_num').' IS NOT NULL');
    }
};
