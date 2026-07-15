<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fund_transfers', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('doc_number')->nullable()->index();
            $table->string('doc_num')->nullable()->index();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->date('transfer_date')->index();
            $table->string('source_type')->index();
            $table->foreignId('source_cashbox_id')->nullable()->constrained('cashboxes')->restrictOnDelete();
            $table->foreignId('source_bank_account_id')->nullable()->constrained('bank_accounts')->restrictOnDelete();
            $table->string('target_type')->index();
            $table->foreignId('target_cashbox_id')->nullable()->constrained('cashboxes')->restrictOnDelete();
            $table->foreignId('target_bank_account_id')->nullable()->constrained('bank_accounts')->restrictOnDelete();
            $table->foreignId('source_currency_id')->constrained('currencies')->restrictOnDelete();
            $table->foreignId('target_currency_id')->constrained('currencies')->restrictOnDelete();
            $table->decimal('source_amount', 18, 4);
            $table->decimal('exchange_rate', 18, 6)->default(1);
            $table->decimal('target_amount', 18, 4);
            $table->decimal('source_amount_base', 18, 4)->nullable();
            $table->decimal('target_amount_base', 18, 4)->nullable();
            $table->text('reason');
            $table->text('description')->nullable();
            $table->string('status')->default('draft')->index();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('restored_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('restored_at')->nullable();
            $table->timestamps();
            $table->softDeletes()->index();

            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'transfer_date']);
            $table->index(['source_type', 'source_cashbox_id']);
            $table->index(['source_type', 'source_bank_account_id']);
            $table->index(['target_type', 'target_cashbox_id']);
            $table->index(['target_type', 'target_bank_account_id']);
            $table->index(['source_currency_id', 'transfer_date']);
            $table->index(['target_currency_id', 'transfer_date']);
        });

        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            $this->createActiveUniqueIndexes();
        }
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            foreach ([
                'fund_transfers_company_doc_number_unique_active',
                'fund_transfers_company_doc_num_unique_active',
            ] as $index) {
                DB::statement('DROP INDEX IF EXISTS '.DB::getQueryGrammar()->wrap($index));
            }
        }

        Schema::dropIfExists('fund_transfers');
    }

    private function createActiveUniqueIndexes(): void
    {
        $grammar = DB::getQueryGrammar();
        $table = $grammar->wrapTable('fund_transfers');
        $deletedAt = $grammar->wrap('deleted_at');

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS '.$grammar->wrap('fund_transfers_company_doc_number_unique_active').' ON '.$table.' ('.$grammar->wrap('company_id').', '.$grammar->wrap('doc_number').') WHERE '.$deletedAt.' IS NULL AND '.$grammar->wrap('doc_number').' IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS '.$grammar->wrap('fund_transfers_company_doc_num_unique_active').' ON '.$table.' ('.$grammar->wrap('company_id').', '.$grammar->wrap('doc_num').') WHERE '.$deletedAt.' IS NULL AND '.$grammar->wrap('doc_num').' IS NOT NULL');
    }
};
