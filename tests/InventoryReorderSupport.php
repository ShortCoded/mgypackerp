<?php

use App\Models\User;
use Modules\Auth\Models\Role;
use Modules\Core\Models\Branch;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Models\Product;
use Modules\Inventory\Models\InventoryReorderAlert;
use Modules\Inventory\Models\InventoryReservation;
use Modules\Inventory\Models\InventoryTransaction;
use Spatie\Permission\Models\Permission;

/** @return array<string, mixed> */
function reorderFixture(): array
{
    $company = Company::factory()->create(['name' => 'SYNTHETIC Reorder Company']);
    $number = $company->doc_number;
    $branch = Branch::query()->create(['company_id' => $company->id, 'doc_number' => $number, 'doc_num' => 'REORDER-BRANCH-'.$number,
        'name' => 'فرع التنبيهات التجريبي', 'type' => Branch::TypeFactory, 'status' => 'active']);
    $period = FinancialPeriod::query()->create(['company_id' => $company->id, 'doc_number' => $number, 'doc_num' => 'REORDER-PERIOD-'.$number,
        'name' => 'Synthetic current year', 'from_date' => today()->startOfYear(), 'to_date' => today()->endOfYear(), 'is_closed' => false]);
    $store = BranchStore::query()->create(['branch_id' => $branch->id, 'name' => 'مخزن التنبيهات التجريبي', 'position' => 1]);
    $unit = ItemUnit::query()->create(['company_id' => $company->id, 'doc_number' => $number, 'doc_num' => 'REORDER-UNIT-'.$number,
        'name' => 'كجم', 'status' => 'active']);
    $product = Product::query()->create(['company_id' => $company->id, 'doc_number' => $number, 'doc_num' => 'REORDER-ITEM-'.$number,
        'name' => 'خامة اختبار إعادة الطلب', 'item_classification' => Product::ClassificationRawMaterial,
        'item_unit_id' => $unit->id, 'status' => 'active', 'reorder_point' => '10']);
    $permission = Permission::findOrCreate(InventoryReorderAlert::Permission, 'web');
    $user = User::factory()->create(['name' => 'SYNTHETIC Reorder Recipient']);
    $user->givePermissionTo($permission);
    $fixture = compact('company', 'branch', 'period', 'store', 'unit', 'product', 'user');
    reorderMovement($fixture, '12');
    reorderMovement($fixture, '100', '0', InventoryTransaction::StatusQuarantine);
    $fixture['reservation'] = InventoryReservation::query()->create([
        'company_id' => $company->id, 'financial_period_id' => $period->id, 'branch_id' => $branch->id, 'branch_store_id' => $store->id,
        'product_id' => $product->id, 'quantity' => '5', 'consumed_quantity' => '0', 'released_quantity' => '0',
        'status' => InventoryReservation::StatusActive]);

    return $fixture;
}

/** @param array<string, mixed> $fixture */
function reorderMovement(array $fixture, string $in, string $out = '0', string $status = InventoryTransaction::StatusAvailable): InventoryTransaction
{
    return InventoryTransaction::query()->create(['posting_key' => 'synthetic-reorder-'.bin2hex(random_bytes(10)),
        'company_id' => $fixture['company']->id, 'financial_period_id' => $fixture['period']->id, 'branch_id' => $fixture['branch']->id,
        'branch_store_id' => $fixture['store']->id, 'transaction_date' => today()->toDateString(), 'transaction_type' => 'opening_balance',
        'product_id' => $fixture['product']->id, 'unit_id' => $fixture['unit']->id, 'quantity_in' => $in, 'quantity_out' => $out,
        'unit_cost' => '1', 'total_cost' => bccomp($in, '0', 8) > 0 ? $in : $out,
        'source_type' => 'synthetic_reorder_test', 'source_id' => 1, 'source_doc_num' => 'SYNTHETIC-REORDER', 'stock_status' => $status]);
}

/** @param array<string, mixed> $fixture */
function reorderRestrictedRecipient(array $fixture, ?string $omittedScope = null): User
{
    $user = User::factory()->create();
    $role = Role::query()->create(['name' => 'reorder-scope-'.$user->id, 'guard_name' => 'web', 'doc_number' => $user->doc_number,
        'doc_num' => 'REORDER-ROLE-'.$user->id, 'company_access_restricted' => true, 'branch_access_restricted' => true,
        'financial_period_access_restricted' => true]);
    $user->assignRole($role);
    $user->givePermissionTo(InventoryReorderAlert::Permission);
    foreach (['company', 'branch', 'financial_period'] as $scope) {
        if ($scope !== $omittedScope) {
            $model = $scope === 'financial_period' ? $fixture['period'] : $fixture[$scope];
            DB::table('role_'.$scope.'_access')->insert(['role_id' => $role->id, $scope.'_id' => $model->id,
                'created_at' => now(), 'updated_at' => now()]);
        }
    }

    return $user;
}
