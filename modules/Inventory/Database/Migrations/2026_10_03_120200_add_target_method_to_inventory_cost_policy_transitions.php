<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Inventory\Models\InventoryCostPolicy;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('inventory_cost_transition_column_ownership')->insertOrIgnore([
            'column_name' => 'target_method',
            'created_by_migration' => ! Schema::hasColumn('inventory_cost_policy_transitions', 'target_method'),
        ]);
        Schema::table('inventory_cost_policy_transitions', function (Blueprint $table): void {
            if (! Schema::hasColumn('inventory_cost_policy_transitions', 'target_method')) {
                $table->string('target_method', 40)->default(InventoryCostPolicy::Fifo);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('inventory_cost_transition_column_ownership')) {
            throw new RuntimeException('Cost transition column ownership is missing; preserve target-method history.');
        }
        if (! DB::table('inventory_cost_transition_column_ownership')->where('column_name', 'target_method')->exists()) {
            throw new RuntimeException('Target-method column ownership is missing; preserve transition history.');
        }
        if (DB::table('inventory_cost_transition_column_ownership')->where('column_name', 'target_method')->value('created_by_migration')) {
            if (DB::table('inventory_cost_policy_transitions')->where('target_method', '!=', InventoryCostPolicy::Fifo)->exists()) {
                throw new RuntimeException('A specific-identification transition exists; rollback would discard its approved target method.');
            }
            Schema::table('inventory_cost_policy_transitions', function (Blueprint $table): void {
                $table->dropColumn('target_method');
            });
        }
        DB::table('inventory_cost_transition_column_ownership')->where('column_name', 'target_method')->delete();
    }
};
