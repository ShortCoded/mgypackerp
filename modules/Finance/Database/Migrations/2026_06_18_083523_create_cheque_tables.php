<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cheques', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('doc_number')->nullable()->index();
            $table->string('doc_num')->nullable()->index();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('cheque_type')->index();
            $table->string('cheque_number');
            $table->date('cheque_date')->nullable()->index();
            $table->date('due_date')->nullable()->index();
            $table->foreignId('bank_account_id')->nullable()->constrained('bank_accounts')->restrictOnDelete();
            $table->string('external_bank_name')->nullable();
            $table->string('external_bank_branch')->nullable();
            $table->string('party_type')->nullable()->index();
            $table->unsignedBigInteger('party_id')->nullable()->index();
            $table->string('party_name')->nullable();
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->decimal('exchange_rate', 18, 6)->default(1);
            $table->decimal('amount', 18, 4);
            $table->decimal('amount_base', 18, 4)->nullable();
            $table->text('reason');
            $table->text('description')->nullable();
            $table->string('status')->index();
            $table->timestamp('deposited_at')->nullable();
            $table->timestamp('collected_at')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('cleared_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancel_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('restored_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('restored_at')->nullable();
            $table->timestamps();
            $table->softDeletes()->index();

            $table->index(['company_id', 'cheque_type']);
            $table->index(['company_id', 'cheque_type', 'status']);
            $table->index(['company_id', 'cheque_type', 'due_date']);
            $table->index(['bank_account_id', 'due_date']);
            $table->index(['currency_id', 'due_date']);
        });

        Schema::create('cheque_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cheque_id')->constrained('cheques')->cascadeOnDelete();
            $table->unsignedInteger('line_number');
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->decimal('amount', 18, 4);
            $table->decimal('amount_base', 18, 4)->nullable();
            $table->text('description')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['cheque_id', 'line_number']);
            $table->index('account_id');
        });

        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            $this->createActiveUniqueIndexes();
        }
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            foreach ([
                'cheques_company_type_doc_number_unique_active',
                'cheques_company_doc_num_unique_active',
            ] as $index) {
                DB::statement('DROP INDEX IF EXISTS '.DB::getQueryGrammar()->wrap($index));
            }
        }

        Schema::dropIfExists('cheque_lines');
        Schema::dropIfExists('cheques');
    }

    private function createActiveUniqueIndexes(): void
    {
        $grammar = DB::getQueryGrammar();
        $table = $grammar->wrapTable('cheques');
        $deletedAt = $grammar->wrap('deleted_at');

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS '.$grammar->wrap('cheques_company_type_doc_number_unique_active').' ON '.$table.' ('.$grammar->wrap('company_id').', '.$grammar->wrap('cheque_type').', '.$grammar->wrap('doc_number').') WHERE '.$deletedAt.' IS NULL AND '.$grammar->wrap('doc_number').' IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS '.$grammar->wrap('cheques_company_doc_num_unique_active').' ON '.$table.' ('.$grammar->wrap('company_id').', '.$grammar->wrap('doc_num').') WHERE '.$deletedAt.' IS NULL AND '.$grammar->wrap('doc_num').' IS NOT NULL');
    }
};
