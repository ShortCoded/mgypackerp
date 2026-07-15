<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opening_balances', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('doc_number')->nullable()->index();
            $table->string('doc_num')->nullable()->index();
            $table->date('document_date')->index();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('financial_period_id')->constrained('financial_periods')->restrictOnDelete();
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->decimal('exchange_rate', 18, 6)->default(1);
            $table->string('opening_balance_type')->default('general')->index();
            $table->text('description')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_cancelled')->default(false)->index();
            $table->boolean('is_closed')->default(false)->index();
            $table->boolean('approved')->default(false)->index();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->string('status')->default('draft')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('restored_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('restored_at')->nullable();
            $table->timestamps();
            $table->softDeletes()->index();

            $table->index(['company_id', 'financial_period_id']);
            $table->index(['company_id', 'financial_period_id', 'doc_num']);
        });

        Schema::create('opening_balance_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('opening_balance_id')->constrained('opening_balances')->cascadeOnDelete();
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

            $table->index(['opening_balance_id', 'line_no']);
            $table->index(['account_id', 'branch_id']);
        });

        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            $this->createActiveUniqueIndexes();
        }
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement('DROP INDEX IF EXISTS '.DB::getQueryGrammar()->wrap('opening_balance_documents_doc_number_unique_active'));
            DB::statement('DROP INDEX IF EXISTS '.DB::getQueryGrammar()->wrap('opening_balance_documents_doc_num_unique_active'));
            DB::statement('DROP INDEX IF EXISTS '.DB::getQueryGrammar()->wrap('opening_balances_company_period_doc_num_unique_active'));
        }

        Schema::dropIfExists('opening_balance_lines');
        Schema::dropIfExists('opening_balances');
    }

    private function createActiveUniqueIndexes(): void
    {
        $grammar = DB::getQueryGrammar();
        $table = $grammar->wrapTable('opening_balances');
        $deletedAt = $grammar->wrap('deleted_at');

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS '.$grammar->wrap('opening_balances_company_period_doc_num_unique_active').' ON '.$table.' ('.$grammar->wrap('company_id').', '.$grammar->wrap('financial_period_id').', '.$grammar->wrap('doc_num').') WHERE '.$deletedAt.' IS NULL AND '.$grammar->wrap('doc_num').' IS NOT NULL');
    }
};
