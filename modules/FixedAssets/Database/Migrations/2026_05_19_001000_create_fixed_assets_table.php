<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('fixed_assets')) {
            return;
        }

        Schema::create('fixed_assets', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('doc_number')->nullable()->index();
            $table->string('doc_num')->nullable()->index();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('period_id')->nullable()->constrained('financial_periods')->nullOnDelete();
            $table->foreignId('account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->foreignId('asset_group_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->foreignId('credit_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->foreignId('cost_center_id')->nullable();
            $table->foreignId('currency_id')->nullable()->constrained('currencies')->nullOnDelete();
            $table->date('asset_date');
            $table->string('asset_name');
            $table->text('description')->nullable();
            $table->string('serial_number')->nullable();
            $table->date('purchase_date')->nullable();
            $table->date('acquisition_date')->nullable();
            $table->date('operation_date')->nullable();
            $table->decimal('purchase_value', 18, 4)->nullable();
            $table->decimal('exchange_rate', 18, 6)->nullable();
            $table->decimal('previous_depreciation', 18, 4)->nullable();
            $table->decimal('net_value', 18, 4)->nullable();
            $table->decimal('annual_depreciation_rate', 8, 4)->nullable();
            $table->decimal('useful_life', 10, 2)->nullable();
            $table->boolean('is_depreciable')->default(true)->index();
            $table->text('location_address')->nullable();
            $table->string('status')->default('active')->index();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('restored_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('restored_at')->nullable();
            $table->timestamps();
            $table->softDeletes()->index();

            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'doc_number']);
            $table->index(['company_id', 'doc_num']);
            $table->index(['company_id', 'asset_name']);
            $table->index(['company_id', 'serial_number']);
            $table->index(['company_id', 'account_id']);
            $table->index(['company_id', 'asset_group_account_id']);
            $table->index(['company_id', 'credit_account_id']);
            $table->index(['company_id', 'cost_center_id']);
            $table->index(['company_id', 'currency_id']);
        });

        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            $this->createActiveUniqueIndexes();
        }
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            foreach ([
                'fixed_assets_company_doc_number_unique_active',
                'fixed_assets_company_doc_num_unique_active',
                'fixed_assets_company_account_id_unique_active',
                'fixed_assets_company_asset_name_unique_active',
                'fixed_assets_company_serial_number_unique_active',
            ] as $index) {
                DB::statement('DROP INDEX IF EXISTS '.DB::getQueryGrammar()->wrap($index));
            }
        }

        Schema::dropIfExists('fixed_assets');
    }

    private function createActiveUniqueIndexes(): void
    {
        $grammar = DB::getQueryGrammar();
        $table = $grammar->wrapTable('fixed_assets');
        $deletedAt = $grammar->wrap('deleted_at');
        $companyId = $grammar->wrap('company_id');

        foreach (['doc_number', 'doc_num', 'account_id', 'asset_name'] as $column) {
            $wrappedColumn = $grammar->wrap($column);
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS '.$grammar->wrap("fixed_assets_company_{$column}_unique_active")." ON {$table} ({$companyId}, {$wrappedColumn}) WHERE {$deletedAt} IS NULL AND {$wrappedColumn} IS NOT NULL");
        }

        $serialNumber = $grammar->wrap('serial_number');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS '.$grammar->wrap('fixed_assets_company_serial_number_unique_active')." ON {$table} ({$companyId}, {$serialNumber}) WHERE {$deletedAt} IS NULL AND {$serialNumber} IS NOT NULL");
    }
};
