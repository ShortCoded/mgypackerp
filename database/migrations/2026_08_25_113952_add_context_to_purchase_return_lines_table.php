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
        if (! Schema::hasTable('purchase_return_lines')) {
            return;
        }

        $addCompany = ! Schema::hasColumn('purchase_return_lines', 'company_id');
        $addFinancialPeriod = ! Schema::hasColumn('purchase_return_lines', 'financial_period_id');
        $addLineNumber = ! Schema::hasColumn('purchase_return_lines', 'line_number');

        Schema::table('purchase_return_lines', function (Blueprint $table) use ($addCompany, $addFinancialPeriod, $addLineNumber): void {
            if ($addCompany) {
                $table->unsignedBigInteger('company_id')->nullable()->after('purchase_return_id')->index();
            }
            if ($addFinancialPeriod) {
                $table->unsignedBigInteger('financial_period_id')->nullable()->after('company_id')->index();
            }
            if ($addLineNumber) {
                $table->unsignedInteger('line_number')->nullable()->after('financial_period_id');
            }
        });

        DB::table('purchase_return_lines')->orderBy('id')->get(['id', 'purchase_return_id'])->each(function (object $line): void {
            $return = DB::table('purchase_returns')->where('id', $line->purchase_return_id)->first(['company_id', 'financial_period_id']);
            if ($return === null) {
                return;
            }

            $lineNumber = DB::table('purchase_return_lines')
                ->where('purchase_return_id', $line->purchase_return_id)
                ->where('id', '<=', $line->id)
                ->count();
            DB::table('purchase_return_lines')->where('id', $line->id)->update([
                'company_id' => $return->company_id,
                'financial_period_id' => $return->financial_period_id,
                'line_number' => $lineNumber,
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('purchase_return_lines')) {
            return;
        }

        Schema::table('purchase_return_lines', function (Blueprint $table): void {
            foreach (['company_id', 'financial_period_id', 'line_number'] as $column) {
                if (Schema::hasColumn('purchase_return_lines', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
