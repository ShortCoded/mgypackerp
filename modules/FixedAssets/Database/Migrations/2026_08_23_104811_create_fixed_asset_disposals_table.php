<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('fixed_asset_disposals', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('doc_number');
            $table->string('doc_num');
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('financial_period_id')->constrained('financial_periods')->restrictOnDelete();
            $table->foreignId('fixed_asset_id')->constrained('fixed_assets')->restrictOnDelete();
            $table->date('disposal_date');
            $table->string('disposition_type');
            $table->text('reason');
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('proceeds_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->decimal('original_cost', 18, 4);
            $table->decimal('base_original_cost', 18, 4);
            $table->decimal('accumulated_depreciation', 18, 4);
            $table->decimal('base_accumulated_depreciation', 18, 4);
            $table->decimal('net_book_value', 18, 4);
            $table->decimal('base_net_book_value', 18, 4);
            $table->decimal('proceeds', 18, 4)->default(0);
            $table->decimal('base_proceeds', 18, 4)->default(0);
            $table->decimal('gain_amount', 18, 4)->default(0);
            $table->decimal('base_gain_amount', 18, 4)->default(0);
            $table->decimal('loss_amount', 18, 4)->default(0);
            $table->decimal('base_loss_amount', 18, 4)->default(0);
            $table->string('status')->default('posted');
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('reversal_journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'doc_number']);
            $table->unique(['company_id', 'doc_num']);
            $table->index(['company_id', 'disposal_date', 'disposition_type'], 'fixed_asset_disposals_date_type_index');
            $table->index(['fixed_asset_id', 'status']);
        });

        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            $grammar = DB::getQueryGrammar();
            $table = $grammar->wrapTable('fixed_asset_disposals');
            DB::statement('CREATE UNIQUE INDEX '.$grammar->wrap('fixed_asset_disposals_posted_unique')." ON {$table} (fixed_asset_id) WHERE status = 'posted'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fixed_asset_disposals');
    }
};
