<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_payroll_runs', function (Blueprint $table): void {
            $table->date('reversal_date')->nullable();
            $table->foreignId('reversal_journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('correction_of_run_id')->nullable()->constrained('hr_payroll_runs')->restrictOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reversal_reason')->nullable();
        });
        Schema::table('hr_payroll_advance_applications', function (Blueprint $table): void {
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
        });
        Schema::create('hr_payroll_corrections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('payroll_run_id')->constrained('hr_payroll_runs')->restrictOnDelete();
            $table->foreignId('financial_period_id')->constrained('financial_periods')->restrictOnDelete();
            $table->foreignId('original_journal_entry_id')->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('reversal_journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->date('reversal_date');
            $table->text('reason');
            $table->string('status', 30)->default('prepared');
            $table->string('fingerprint', 64);
            $table->json('source_snapshot');
            $table->foreignId('prepared_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamps();
            $table->index(['payroll_run_id', 'status']);
        });
        $this->indexes(true);
    }

    public function down(): void
    {
        if (DB::table('hr_payroll_corrections')->exists() || DB::table('hr_payroll_runs')->where('status', 'reversed')->exists()) {
            throw new RuntimeException('Payroll correction history must be preserved.');
        }
        $this->indexes(false);
        Schema::drop('hr_payroll_corrections');
        Schema::table('hr_payroll_advance_applications', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reversed_by');
            $table->dropColumn('reversed_at');
        });
        Schema::table('hr_payroll_runs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reversal_journal_entry_id');
            $table->dropConstrainedForeignId('correction_of_run_id');
            $table->dropConstrainedForeignId('reversed_by');
            $table->dropColumn(['reversal_date', 'reversed_at', 'reversal_reason']);
        });
    }

    private function indexes(bool $excludeReversed): void
    {
        if (! in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            return;
        }
        $grammar = DB::getQueryGrammar();
        foreach (['branch' => ' IS NOT NULL', 'companywide' => ' IS NULL'] as $scope => $nullability) {
            $name = $grammar->wrap('hr_payroll_runs_period_'.$scope.'_unique_active');
            DB::statement('DROP INDEX IF EXISTS '.$name);
            DB::statement('CREATE UNIQUE INDEX '.$name.' ON '.$grammar->wrapTable('hr_payroll_runs')
                .' ('.$grammar->wrap('payroll_period_id').($scope === 'branch' ? ', '.$grammar->wrap('branch_id') : '').')'
                .' WHERE '.$grammar->wrap('deleted_at').' IS NULL AND '.$grammar->wrap('branch_id').$nullability
                .($excludeReversed ? ' AND '.$grammar->wrap('status')." <> 'reversed'" : ''));
        }
    }
};
