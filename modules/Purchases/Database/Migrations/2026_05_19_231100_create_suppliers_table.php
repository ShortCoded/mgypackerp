<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('doc_number')->nullable()->index();
            $table->string('doc_num')->nullable()->index();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->foreignId('account_group_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->string('name');
            $table->string('status')->default('active')->index();
            $table->string('phone')->nullable();
            $table->string('mobile')->nullable();
            $table->string('email')->nullable();
            $table->string('tax_number')->nullable();
            $table->string('commercial_register')->nullable();
            $table->string('national_id')->nullable();
            $table->string('contact_person')->nullable();
            $table->string('city')->nullable();
            $table->string('governorate')->nullable();
            $table->string('country')->nullable();
            $table->decimal('credit_limit', 18, 4)->nullable();
            $table->unsignedInteger('payment_terms_days')->nullable();
            $table->text('address')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('restored_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('restored_at')->nullable();
            $table->timestamps();
            $table->softDeletes()->index();

            $table->index(['company_id', 'status']);
        });

        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            $this->createActiveUniqueIndexes();
        }
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            foreach ([
                'suppliers_company_doc_number_unique_active',
                'suppliers_company_doc_num_unique_active',
                'suppliers_company_account_id_unique_active',
            ] as $index) {
                DB::statement('DROP INDEX IF EXISTS '.DB::getQueryGrammar()->wrap($index));
            }
        }

        Schema::dropIfExists('suppliers');
    }

    private function createActiveUniqueIndexes(): void
    {
        $grammar = DB::getQueryGrammar();
        $table = $grammar->wrapTable('suppliers');
        $deletedAt = $grammar->wrap('deleted_at');
        $companyId = $grammar->wrap('company_id');

        foreach (['doc_number', 'doc_num', 'account_id'] as $column) {
            $wrappedColumn = $grammar->wrap($column);
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS '.$grammar->wrap("suppliers_company_{$column}_unique_active")." ON {$table} ({$companyId}, {$wrappedColumn}) WHERE {$deletedAt} IS NULL AND {$wrappedColumn} IS NOT NULL");
        }
    }
};
