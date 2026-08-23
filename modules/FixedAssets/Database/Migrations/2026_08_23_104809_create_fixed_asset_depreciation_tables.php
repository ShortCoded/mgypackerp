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
        Schema::create('fixed_asset_depreciation_runs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('doc_number');
            $table->string('doc_num');
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('financial_period_id')->constrained('financial_periods')->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->date('posting_date');
            $table->json('filters')->nullable();
            $table->decimal('total_depreciation', 18, 4)->default(0);
            $table->decimal('base_total_depreciation', 18, 4)->default(0);
            $table->string('status')->default('posted');
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('reversal_journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reversal_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'doc_number']);
            $table->unique(['company_id', 'doc_num']);
            $table->index(['company_id', 'financial_period_id', 'period_end'], 'fixed_asset_dep_runs_period_index');
            $table->index(['company_id', 'status', 'posting_date'], 'fixed_asset_dep_runs_status_index');
        });

        Schema::create('fixed_asset_depreciations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('depreciation_run_id')->constrained('fixed_asset_depreciation_runs')->restrictOnDelete();
            $table->foreignId('fixed_asset_id')->constrained('fixed_assets')->restrictOnDelete();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('financial_period_id')->constrained('financial_periods')->restrictOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('acquisition_cost', 18, 4);
            $table->decimal('base_acquisition_cost', 18, 4);
            $table->decimal('depreciation_base', 18, 4);
            $table->decimal('base_depreciation_base', 18, 4);
            $table->decimal('period_depreciation', 18, 4);
            $table->decimal('base_period_depreciation', 18, 4);
            $table->decimal('usage_units', 18, 4)->nullable();
            $table->decimal('accumulated_before', 18, 4);
            $table->decimal('base_accumulated_before', 18, 4);
            $table->decimal('accumulated_after', 18, 4);
            $table->decimal('base_accumulated_after', 18, 4);
            $table->decimal('closing_net_book_value', 18, 4);
            $table->decimal('base_closing_net_book_value', 18, 4);
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('cost_center_id')->nullable()->constrained('cost_centers')->nullOnDelete();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->string('status')->default('posted');
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['depreciation_run_id', 'fixed_asset_id'], 'fixed_asset_depreciation_run_asset_unique');
            $table->index(['company_id', 'financial_period_id', 'period_end'], 'fixed_asset_depreciations_period_index');
            $table->index(['fixed_asset_id', 'status', 'period_end'], 'fixed_asset_depreciations_asset_index');
            $table->index(['company_id', 'cost_center_id', 'period_end'], 'fixed_asset_depreciations_cost_center_index');
        });

        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            $grammar = DB::getQueryGrammar();
            $table = $grammar->wrapTable('fixed_asset_depreciations');
            DB::statement('CREATE UNIQUE INDEX '.$grammar->wrap('fixed_asset_depreciations_period_posted_unique')." ON {$table} (fixed_asset_id, period_end) WHERE status = 'posted'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fixed_asset_depreciations');
        Schema::dropIfExists('fixed_asset_depreciation_runs');
    }
};
