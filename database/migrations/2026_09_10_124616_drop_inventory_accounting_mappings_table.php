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
        if (Schema::hasTable('inventory_accounting_mappings')) {
            $this->migrateLegacyAccountAssignments();
        }

        Schema::dropIfExists('inventory_accounting_mappings');
    }

    private function migrateLegacyAccountAssignments(): void
    {
        $mappings = DB::table('inventory_accounting_mappings')->get();

        if ($mappings->isEmpty()) {
            return;
        }

        $fieldClassifications = [
            'raw_material_inventory_account_id' => 'raw_material_inventory',
            'packaging_inventory_account_id' => 'packaging_material_inventory',
            'semi_finished_inventory_account_id' => 'semi_finished_goods_inventory',
            'finished_goods_inventory_account_id' => 'finished_goods_inventory',
            'wip_account_id' => 'work_in_process_inventory',
            'production_waste_account_id' => 'abnormal_waste_loss',
            'recoverable_scrap_inventory_account_id' => 'scrap_waste_inventory',
            'warehouse_damage_loss_account_id' => 'warehouse_damage_loss',
            'inventory_adjustment_gain_account_id' => 'inventory_adjustment_gain',
            'inventory_adjustment_loss_account_id' => 'inventory_adjustment_loss',
            'production_variance_account_id' => 'manufacturing_variance',
            'quarantine_inventory_account_id' => 'quarantine_inventory',
            'rework_inventory_account_id' => 'rework_inventory',
            'grni_account_id' => 'goods_received_not_invoiced',
            'purchase_price_variance_account_id' => 'purchase_price_variance',
        ];
        $requiredCodes = array_values(array_unique(array_values($fieldClassifications)));
        $classifications = DB::table('account_classifications')
            ->whereIn('code', $requiredCodes)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->get(['id', 'code'])
            ->groupBy('code');
        $invalidCodes = collect($requiredCodes)
            ->filter(fn (string $code): bool => $classifications->get($code, collect())->count() !== 1)
            ->values();

        if ($invalidCodes->isNotEmpty()) {
            throw new RuntimeException(
                'Inventory accounting mappings were preserved because their target classifications are missing or duplicated: '
                .$invalidCodes->implode(', ')
                .'. Run account-classifications:sync --force before this migration.'
            );
        }

        DB::transaction(function () use ($classifications, $fieldClassifications, $mappings): void {
            foreach ($mappings as $mapping) {
                $accountAssignments = [];

                foreach ($fieldClassifications as $field => $classificationCode) {
                    $accountId = isset($mapping->{$field}) ? (int) $mapping->{$field} : 0;

                    if ($accountId < 1) {
                        continue;
                    }

                    if (isset($accountAssignments[$accountId]) && $accountAssignments[$accountId] !== $classificationCode) {
                        throw new RuntimeException("Legacy inventory account [{$accountId}] is assigned to multiple posting purposes.");
                    }

                    $accountAssignments[$accountId] = $classificationCode;
                }

                foreach ($accountAssignments as $accountId => $classificationCode) {
                    $account = DB::table('accounts')->where('id', $accountId)->lockForUpdate()->first(['id', 'company_id']);

                    if ($account === null || (int) $account->company_id !== (int) $mapping->company_id) {
                        throw new RuntimeException("Legacy inventory account [{$accountId}] does not belong to its mapping company.");
                    }

                    $classificationId = (int) $classifications->get($classificationCode)->sole()->id;
                    $conflictingAccount = DB::table('accounts')
                        ->where('company_id', $mapping->company_id)
                        ->where('account_classification_id', $classificationId)
                        ->where('id', '<>', $accountId)
                        ->where('status', 'active')
                        ->where('is_postable', true)
                        ->where('is_group', false)
                        ->whereNull('deleted_at')
                        ->exists();

                    if ($conflictingAccount) {
                        throw new RuntimeException("Posting classification [{$classificationCode}] already belongs to another eligible account for this company.");
                    }

                    DB::table('accounts')->where('id', $accountId)->update([
                        'account_classification_id' => $classificationId,
                        'updated_at' => now(),
                    ]);
                }
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::create('inventory_accounting_mappings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('valuation_method', 40)->default('moving_average');
            $table->foreignId('raw_material_inventory_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('packaging_inventory_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('semi_finished_inventory_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('finished_goods_inventory_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('wip_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('production_waste_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('recoverable_scrap_inventory_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->foreignId('warehouse_damage_loss_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('inventory_adjustment_gain_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('inventory_adjustment_loss_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('production_variance_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->foreignId('production_cost_center_id')->nullable()->constrained('cost_centers')->restrictOnDelete();
            $table->foreignId('quarantine_inventory_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->foreignId('rework_inventory_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->foreignId('grni_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->foreignId('purchase_price_variance_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('company_id', 'inventory_accounting_mappings_company_unique');
            $table->index(['company_id', 'wip_account_id'], 'inventory_accounting_mappings_wip_index');
        });
    }
};
