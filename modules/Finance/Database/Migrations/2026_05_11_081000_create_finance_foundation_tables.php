<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_accounts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('doc_number')->nullable()->index();
            $table->string('doc_num')->nullable()->index();
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->string('bank_name');
            $table->string('account_name');
            $table->string('account_number')->nullable();
            $table->string('iban')->nullable();
            $table->string('swift_code')->nullable();
            $table->string('owner_name')->nullable();
            $table->string('bank_branch_name')->nullable();
            $table->string('status')->default('active')->index();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('restored_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('restored_at')->nullable();
            $table->timestamps();
            $table->softDeletes()->index();
        });

        Schema::create('cashboxes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('doc_number')->nullable()->index();
            $table->string('doc_num')->nullable()->index();
            $table->string('name');
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->string('status')->default('active')->index();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('restored_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('restored_at')->nullable();
            $table->timestamps();
            $table->softDeletes()->index();
        });

        Schema::create('cashbox_currencies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cashbox_id')->constrained('cashboxes')->cascadeOnDelete();
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->boolean('is_default')->default(false);
            $table->string('status')->default('active')->index();
            $table->timestamps();
            $table->softDeletes()->index();
        });

        Schema::create('account_opening_balances', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('doc_number')->nullable()->index();
            $table->string('doc_num')->nullable()->index();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('financial_period_id')->constrained('financial_periods')->restrictOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->decimal('debit_amount', 18, 4)->default(0);
            $table->decimal('credit_amount', 18, 4)->default(0);
            $table->string('status')->default('active')->index();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('restored_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('restored_at')->nullable();
            $table->timestamps();
            $table->softDeletes()->index();
        });

        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            $this->createActiveUniqueIndexes();
        }
    }

    public function down(): void
    {
        foreach ([
            'bank_accounts_doc_number_unique_active',
            'bank_accounts_doc_num_unique_active',
            'bank_accounts_account_id_unique_active',
            'cashboxes_doc_number_unique_active',
            'cashboxes_doc_num_unique_active',
            'cashboxes_account_id_unique_active',
            'cashbox_currencies_unique_active',
            'opening_balances_doc_number_unique_active',
            'opening_balances_doc_num_unique_active',
            'opening_balances_natural_unique_active',
        ] as $index) {
            if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
                DB::statement('DROP INDEX IF EXISTS '.DB::getQueryGrammar()->wrap($index));
            }
        }

        Schema::dropIfExists('account_opening_balances');
        Schema::dropIfExists('cashbox_currencies');
        Schema::dropIfExists('cashboxes');
        Schema::dropIfExists('bank_accounts');
    }

    private function createActiveUniqueIndexes(): void
    {
        $grammar = DB::getQueryGrammar();

        foreach ([
            'bank_accounts' => ['doc_number', 'doc_num', 'account_id'],
            'cashboxes' => ['doc_number', 'doc_num', 'account_id'],
        ] as $tableName => $columns) {
            $table = $grammar->wrapTable($tableName);
            $deletedAt = $grammar->wrap('deleted_at');

            foreach ($columns as $column) {
                $index = $grammar->wrap($tableName.'_'.$column.'_unique_active');
                $wrappedColumn = $grammar->wrap($column);
                DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS {$index} ON {$table} ({$wrappedColumn}) WHERE {$deletedAt} IS NULL AND {$wrappedColumn} IS NOT NULL");
            }
        }

        $openingTable = $grammar->wrapTable('account_opening_balances');
        $deletedAt = $grammar->wrap('deleted_at');
        foreach (['doc_number', 'doc_num'] as $column) {
            $index = $grammar->wrap('opening_balances_'.$column.'_unique_active');
            DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS {$index} ON {$openingTable} (".$grammar->wrap($column).") WHERE {$deletedAt} IS NULL AND ".$grammar->wrap($column).' IS NOT NULL');
        }

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS '.$grammar->wrap('opening_balances_natural_unique_active').' ON '.$openingTable.' ('.$grammar->wrap('company_id').', COALESCE('.$grammar->wrap('branch_id').', 0), '.$grammar->wrap('financial_period_id').', '.$grammar->wrap('account_id').', '.$grammar->wrap('currency_id').') WHERE '.$deletedAt.' IS NULL');

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS '.$grammar->wrap('cashbox_currencies_unique_active').' ON '.$grammar->wrapTable('cashbox_currencies').' ('.$grammar->wrap('cashbox_id').', '.$grammar->wrap('currency_id').') WHERE '.$deletedAt.' IS NULL');
    }
};
