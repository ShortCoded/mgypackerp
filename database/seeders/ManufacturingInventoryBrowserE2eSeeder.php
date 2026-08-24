<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Database\Seeders\PermissionSeeder;
use Modules\Core\Models\Branch;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Models\Product;
use Modules\Core\Models\ProductComponent;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Models\WarehouseLocation;
use Modules\Production\Models\ProductionMachine;
use Modules\Production\Models\ProductionMold;
use Modules\Production\Models\ProductionShift;

class ManufacturingInventoryBrowserE2eSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            DefaultOperatingContextSeeder::class,
            PermissionSeeder::class,
            DefaultAdminSeeder::class,
        ]);

        DB::transaction(function (): void {
            $company = Company::query()->active()->firstOrFail();
            $branch = Branch::query()->where('company_id', $company->getKey())->where('status', 'active')->firstOrFail();
            $period = FinancialPeriod::query()->where('company_id', $company->getKey())->where('is_closed', false)->firstOrFail();
            $admin = User::query()->where('username', 'admin')->firstOrFail();

            $admin->update([
                'locale' => 'en',
                'default_company_id' => $company->getKey(),
                'default_branch_id' => $branch->getKey(),
                'default_financial_period_id' => $period->getKey(),
            ]);

            $rawStore = BranchStore::query()->create([
                'branch_id' => $branch->getKey(),
                'name' => 'E2E Raw Material Store',
                'position' => 91,
                'created_by' => $admin->getKey(),
            ]);
            $finishedStore = BranchStore::query()->create([
                'branch_id' => $branch->getKey(),
                'name' => 'E2E Finished Goods Store',
                'position' => 92,
                'created_by' => $admin->getKey(),
            ]);
            WarehouseLocation::query()->create([
                'branch_store_id' => $rawStore->getKey(),
                'code' => 'E2E-RAW-A',
                'name' => 'E2E Raw Zone A',
                'zone_code' => 'RAW',
                'created_by' => $admin->getKey(),
            ]);
            WarehouseLocation::query()->create([
                'branch_store_id' => $finishedStore->getKey(),
                'code' => 'E2E-FG-A',
                'name' => 'E2E Finished Zone A',
                'zone_code' => 'FG',
                'created_by' => $admin->getKey(),
            ]);

            $kilogram = $this->unit($company, 996001, 'Unit-E2E-MFG-KG', 'Kilogram');
            $piece = $this->unit($company, 996002, 'Unit-E2E-MFG-PIECE', 'Piece');
            $carton = $this->unit($company, 996003, 'Unit-E2E-MFG-CARTON', 'Carton');

            $rawMaterial = $this->product($company, 996001, 'Product-E2E-MFG-PP', 'E2E PP Raw Material', Product::ClassificationRawMaterial, $kilogram);
            $masterbatch = $this->product($company, 996002, 'Product-E2E-MFG-MB', 'E2E Masterbatch', Product::ClassificationRawMaterial, $kilogram);
            $packaging = $this->product($company, 996003, 'Product-E2E-MFG-CARTON', 'E2E Packaging Carton', Product::ClassificationPackaging, $piece);
            $finishedProduct = $this->product(
                $company,
                996004,
                'Product-E2E-MFG-FG',
                'E2E Plastic Product',
                Product::ClassificationFinishedProduct,
                $piece,
                $carton,
                '0.010000',
            );

            $rawComponent = ProductComponent::query()->create([
                'company_id' => $company->getKey(),
                'product_id' => $finishedProduct->getKey(),
                'component_product_id' => $rawMaterial->getKey(),
                'unit_id' => $kilogram->getKey(),
                'calculation_method' => ProductComponent::CalculationDirect,
                'quantity' => '0.10000000',
                'created_by' => $admin->getKey(),
            ]);
            ProductComponent::query()->create([
                'company_id' => $company->getKey(),
                'product_id' => $finishedProduct->getKey(),
                'component_product_id' => $masterbatch->getKey(),
                'unit_id' => $kilogram->getKey(),
                'calculation_method' => ProductComponent::CalculationPercentage,
                'quantity' => '0.00200000',
                'percentage' => '2.00000000',
                'reference_component_id' => $rawComponent->getKey(),
                'created_by' => $admin->getKey(),
            ]);
            ProductComponent::query()->create([
                'company_id' => $company->getKey(),
                'product_id' => $finishedProduct->getKey(),
                'component_product_id' => $packaging->getKey(),
                'unit_id' => $piece->getKey(),
                'calculation_method' => ProductComponent::CalculationQuantity,
                'quantity' => '0.01000000',
                'created_by' => $admin->getKey(),
            ]);

            foreach ([
                [$rawMaterial, '1000', '2.00000000'],
                [$masterbatch, '100', '8.00000000'],
                [$packaging, '500', '0.50000000'],
            ] as [$product, $quantity, $unitCost]) {
                InventoryTransaction::query()->create([
                    'posting_key' => 'manufacturing-browser-e2e-opening:'.$product->getKey(),
                    'company_id' => $company->getKey(),
                    'financial_period_id' => $period->getKey(),
                    'branch_id' => $branch->getKey(),
                    'branch_store_id' => $rawStore->getKey(),
                    'stock_status' => InventoryTransaction::StatusAvailable,
                    'transaction_date' => now()->toDateString(),
                    'transaction_type' => 'opening_stock',
                    'product_id' => $product->getKey(),
                    'unit_id' => $product->item_unit_id,
                    'quantity_in' => $quantity,
                    'quantity_out' => 0,
                    'source_type' => self::class,
                    'source_id' => $product->getKey(),
                    'source_doc_num' => 'E2E-MFG-OPENING',
                    'unit_cost' => $unitCost,
                    'total_cost' => bcmul($quantity, $unitCost, 8),
                    'created_by' => $admin->getKey(),
                ]);
            }

            $machine = ProductionMachine::query()->create([
                'company_id' => $company->getKey(),
                'branch_id' => $branch->getKey(),
                'code' => 'E2E-MACHINE-01',
                'name' => 'E2E Injection Machine 1',
                'created_by' => $admin->getKey(),
            ]);
            $mold = ProductionMold::query()->create([
                'company_id' => $company->getKey(),
                'branch_id' => $branch->getKey(),
                'code' => 'E2E-MOLD-01',
                'name' => 'E2E Product Mold',
                'created_by' => $admin->getKey(),
            ]);
            $machine->molds()->attach($mold);
            $mold->products()->attach($finishedProduct);
            ProductionShift::query()->create([
                'company_id' => $company->getKey(),
                'branch_id' => $branch->getKey(),
                'code' => 'E2E-SHIFT-A',
                'name' => 'E2E Morning Shift',
                'starts_at' => '08:00',
                'ends_at' => '16:00',
            ]);
        });
    }

    private function unit(Company $company, int $docNumber, string $docNum, string $name): ItemUnit
    {
        return ItemUnit::query()->create([
            'company_id' => $company->getKey(),
            'doc_number' => $docNumber,
            'doc_num' => $docNum,
            'name' => $name,
            'status' => 'active',
        ]);
    }

    private function product(
        Company $company,
        int $docNumber,
        string $docNum,
        string $name,
        string $classification,
        ItemUnit $unit,
        ?ItemUnit $equivalentUnit = null,
        ?string $equivalentValue = null,
    ): Product {
        return Product::query()->create([
            'company_id' => $company->getKey(),
            'doc_number' => $docNumber,
            'doc_num' => $docNum,
            'name' => $name,
            'item_classification' => $classification,
            'item_unit_id' => $unit->getKey(),
            'equivalent_unit_id' => $equivalentUnit?->getKey(),
            'equivalent_value' => $equivalentValue,
            'status' => 'active',
        ]);
    }
}
