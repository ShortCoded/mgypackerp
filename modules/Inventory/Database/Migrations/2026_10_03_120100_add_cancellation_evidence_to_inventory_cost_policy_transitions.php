<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('inventory_cost_transition_column_ownership')) {
            Schema::create('inventory_cost_transition_column_ownership', function (Blueprint $table): void {
                $table->string('column_name', 80)->primary();
                $table->boolean('created_by_migration');
            });
        }
        foreach (['cancelled_by', 'cancelled_at', 'cancellation_reason'] as $column) {
            DB::table('inventory_cost_transition_column_ownership')->insertOrIgnore([
                'column_name' => $column,
                'created_by_migration' => ! Schema::hasColumn('inventory_cost_policy_transitions', $column),
            ]);
        }
        Schema::table('inventory_cost_policy_transitions', function (Blueprint $table): void {
            if (! Schema::hasColumn('inventory_cost_policy_transitions', 'cancelled_by')) {
                $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            }
            if (! Schema::hasColumn('inventory_cost_policy_transitions', 'cancelled_at')) {
                $table->timestamp('cancelled_at')->nullable();
            }
            if (! Schema::hasColumn('inventory_cost_policy_transitions', 'cancellation_reason')) {
                $table->text('cancellation_reason')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('inventory_cost_transition_column_ownership')) {
            throw new RuntimeException('Cost transition column ownership was not recorded; preserve the existing columns.');
        }
        if (DB::table('inventory_cost_policy_transitions')->whereNotNull('cancelled_at')
            ->orWhereNotNull('cancelled_by')->orWhereNotNull('cancellation_reason')->exists()) {
            throw new RuntimeException('Cost transition cancellation evidence exists; rollback would discard history.');
        }
        Schema::table('inventory_cost_policy_transitions', function (Blueprint $table): void {
            if (DB::table('inventory_cost_transition_column_ownership')->where('column_name', 'cancelled_by')->value('created_by_migration')) {
                $table->dropConstrainedForeignId('cancelled_by');
            }
            foreach (['cancelled_at', 'cancellation_reason'] as $column) {
                if (DB::table('inventory_cost_transition_column_ownership')->where('column_name', $column)->value('created_by_migration')) {
                    $table->dropColumn($column);
                }
            }
        });
        Schema::drop('inventory_cost_transition_column_ownership');
    }
};
