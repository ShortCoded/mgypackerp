<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            ['hr_payroll_posting_column_ownership', 'hr_payroll_runs', ['posting_date', 'posting_financial_period_id']],
            ['inventory_cost_transition_column_ownership', 'inventory_cost_policy_transitions', ['cancelled_by', 'cancelled_at', 'cancellation_reason']],
        ] as [$ownershipTable, $sourceTable, $columns]) {
            if (! Schema::hasTable($sourceTable)) {
                continue;
            }
            if (! Schema::hasTable($ownershipTable)) {
                Schema::create($ownershipTable, function (Blueprint $table): void {
                    $table->string('column_name', 80)->primary();
                    $table->boolean('created_by_migration');
                });
            }
            foreach ($columns as $column) {
                if (Schema::hasColumn($sourceTable, $column)) {
                    DB::table($ownershipTable)->insertOrIgnore(['column_name' => $column, 'created_by_migration' => false]);
                }
            }
        }
    }

    public function down(): void
    {
        // Retain ownership evidence needed by the earlier additive migrations.
    }
};
