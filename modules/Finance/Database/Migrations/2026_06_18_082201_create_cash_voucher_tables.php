<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_vouchers', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('doc_number')->nullable()->index();
            $table->string('doc_num')->nullable()->index();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('voucher_type')->index();
            $table->date('voucher_date')->index();
            $table->foreignId('cashbox_id')->constrained('cashboxes')->restrictOnDelete();
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->decimal('exchange_rate', 18, 6)->default(1);
            $table->decimal('amount', 18, 4);
            $table->decimal('amount_base', 18, 4)->nullable();
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

            $table->index(['company_id', 'voucher_type']);
            $table->index(['company_id', 'voucher_type', 'status']);
            $table->index(['company_id', 'voucher_type', 'voucher_date']);
            $table->index(['cashbox_id', 'voucher_date']);
            $table->index(['currency_id', 'voucher_date']);
        });

        Schema::create('cash_voucher_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cash_voucher_id')->constrained('cash_vouchers')->cascadeOnDelete();
            $table->unsignedInteger('line_number');
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->decimal('amount', 18, 4);
            $table->decimal('amount_base', 18, 4)->nullable();
            $table->text('description')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['cash_voucher_id', 'line_number']);
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
                'cash_vouchers_company_type_doc_number_unique_active',
                'cash_vouchers_company_doc_num_unique_active',
            ] as $index) {
                DB::statement('DROP INDEX IF EXISTS '.DB::getQueryGrammar()->wrap($index));
            }
        }

        Schema::dropIfExists('cash_voucher_lines');
        Schema::dropIfExists('cash_vouchers');
    }

    private function createActiveUniqueIndexes(): void
    {
        $grammar = DB::getQueryGrammar();
        $table = $grammar->wrapTable('cash_vouchers');
        $deletedAt = $grammar->wrap('deleted_at');

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS '.$grammar->wrap('cash_vouchers_company_type_doc_number_unique_active').' ON '.$table.' ('.$grammar->wrap('company_id').', '.$grammar->wrap('voucher_type').', '.$grammar->wrap('doc_number').') WHERE '.$deletedAt.' IS NULL AND '.$grammar->wrap('doc_number').' IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS '.$grammar->wrap('cash_vouchers_company_doc_num_unique_active').' ON '.$table.' ('.$grammar->wrap('company_id').', '.$grammar->wrap('doc_num').') WHERE '.$deletedAt.' IS NULL AND '.$grammar->wrap('doc_num').' IS NOT NULL');
    }
};
