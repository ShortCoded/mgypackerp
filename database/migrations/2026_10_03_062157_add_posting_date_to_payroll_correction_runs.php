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
        if (! Schema::hasTable('hr_payroll_posting_column_ownership')) {
            Schema::create('hr_payroll_posting_column_ownership', function (Blueprint $table): void {
                $table->string('column_name', 80)->primary();
                $table->boolean('created_by_migration');
            });
        }
        foreach (['posting_date', 'posting_financial_period_id'] as $column) {
            DB::table('hr_payroll_posting_column_ownership')->insertOrIgnore([
                'column_name' => $column, 'created_by_migration' => ! Schema::hasColumn('hr_payroll_runs', $column),
            ]);
        }
        if (! Schema::hasColumn('hr_payroll_runs', 'posting_date')) {
            Schema::table('hr_payroll_runs', function (Blueprint $table): void {
                $table->date('posting_date')->nullable();
            });
        }
        if (! Schema::hasColumn('hr_payroll_runs', 'posting_financial_period_id')) {
            Schema::table('hr_payroll_runs', function (Blueprint $table): void {
                $table->foreignId('posting_financial_period_id')->nullable()->constrained('financial_periods')->restrictOnDelete();
            });
        }
        $this->activeRunIndexes();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('hr_payroll_posting_column_ownership')) {
            throw new RuntimeException('Payroll posting column ownership was not recorded; preserve the existing columns.');
        }
        if (DB::table('hr_payroll_runs')->whereNotNull('posting_date')->orWhereNotNull('posting_financial_period_id')->exists()) {
            throw new RuntimeException('Dated replacement payroll evidence must be preserved.');
        }
        Schema::table('hr_payroll_runs', function (Blueprint $table): void {
            if (DB::table('hr_payroll_posting_column_ownership')->where('column_name', 'posting_financial_period_id')->value('created_by_migration')) {
                $table->dropConstrainedForeignId('posting_financial_period_id');
            }
            if (DB::table('hr_payroll_posting_column_ownership')->where('column_name', 'posting_date')->value('created_by_migration')) {
                $table->dropColumn('posting_date');
            }
        });
        Schema::dropIfExists('hr_payroll_posting_column_ownership');
        $this->activeRunIndexes();
    }

    private function activeRunIndexes(): void
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
                .' AND '.$grammar->wrap('status')." <> 'reversed'");
        }
    }
};
