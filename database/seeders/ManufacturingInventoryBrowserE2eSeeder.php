<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Database\Seeders\DefaultChartOfAccountsSeeder;
use Modules\Auth\Database\Seeders\PermissionSeeder;
use Modules\Core\Database\Seeders\CurrencySeeder;
use Modules\Core\Models\Branch;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Company;
use Modules\Core\Models\Currency;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Models\Product;
use Modules\Core\Models\ProductComponent;
use Modules\FixedAssets\Models\FixedAsset;
use Modules\Sales\Models\Customer;
use Modules\Sales\Models\CustomerCommercialAgreement;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Services\SalesOrderService;

class ManufacturingInventoryBrowserE2eSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            DefaultOperatingContextSeeder::class,
            CurrencySeeder::class,
            DefaultChartOfAccountsSeeder::class,
            PermissionSeeder::class,
            DefaultAdminSeeder::class,
        ]);

        DB::transaction(function (): void {
            $company = Company::query()->active()->firstOrFail();
            $branch = Branch::query()->where('company_id', $company->getKey())->where('status', 'active')->firstOrFail();
            $period = FinancialPeriod::query()->where('company_id', $company->getKey())->where('is_closed', false)->firstOrFail();
            $currency = Currency::query()->where('company_id', $company->getKey())->where('is_main', true)->firstOrFail();
            $admin = User::query()->where('username', 'admin')->firstOrFail();

            $company->update([
                'legal_name' => 'Short Coded Plastic Industries S.A.E.',
                'commercial_register_number' => 'E2E-MFG-CR-2026',
                'vat_registration_number' => 'E2E-MFG-VAT-2026',
                'phone' => '+20 2 5555 2424',
                'email' => 'manufacturing-e2e@shortcoded.test',
                'address' => '10th of Ramadan Industrial Zone, Egypt',
                'authorized_signatory_name' => 'E2E Factory Manager',
                'authorized_signatory_title' => 'Factory Manager',
            ]);
            $branch->update(['type' => Branch::TypeFactory]);
            $admin->update([
                'locale' => 'en',
                'default_company_id' => $company->getKey(),
                'default_branch_id' => $branch->getKey(),
                'default_financial_period_id' => $period->getKey(),
            ]);

            $rawStore = BranchStore::query()->create([
                'public_uuid' => '00000000-0000-4000-8000-000000000091',
                'branch_id' => $branch->getKey(),
                'name' => 'E2E Raw Material Store',
                'position' => 91,
                'created_by' => $admin->getKey(),
            ]);
            $finishedStore = BranchStore::query()->create([
                'public_uuid' => '00000000-0000-4000-8000-000000000092',
                'branch_id' => $branch->getKey(),
                'name' => 'E2E Finished Goods Store',
                'position' => 92,
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
                'TEST Sales-Origin Plastic Product',
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

            FixedAsset::query()->create([
                'doc_number' => 996001,
                'doc_num' => 'FixedAsset-E2E-MACHINE-01',
                'company_id' => $company->getKey(),
                'branch_id' => $branch->getKey(),
                'period_id' => $period->getKey(),
                'asset_date' => now()->toDateString(),
                'asset_name' => 'E2E Injection Machine 1',
                'status' => FixedAsset::StatusActive,
                'created_by' => $admin->getKey(),
            ]);
            FixedAsset::query()->create([
                'doc_number' => 996002,
                'doc_num' => 'FixedAsset-E2E-PACK-LINE-02',
                'company_id' => $company->getKey(),
                'branch_id' => $branch->getKey(),
                'period_id' => $period->getKey(),
                'asset_date' => now()->toDateString(),
                'asset_name' => 'E2E Customer Packing Line 02',
                'status' => FixedAsset::StatusActive,
                'created_by' => $admin->getKey(),
            ]);

            $kit = $this->product(
                $company,
                996100,
                'Product-E2E-CUSTOMER-KIT',
                'TEST Customer Kit',
                Product::ClassificationFinishedProduct,
                $piece,
            );
            foreach ([
                'TEST Printed Wrapper — Customer A',
                'TEST Kit Napkin',
                'TEST Kit Spoon',
                'TEST Kit Fork',
                'TEST Kit Salt',
                'TEST Kit Pepper',
                'TEST Kit Outer Carton',
            ] as $index => $name) {
                $component = $this->product(
                    $company,
                    996110 + $index,
                    sprintf('Product-E2E-PACK-%02d', $index + 1),
                    $name,
                    Product::ClassificationPackaging,
                    $piece,
                );
                ProductComponent::query()->create([
                    'company_id' => $company->getKey(),
                    'product_id' => $kit->getKey(),
                    'component_product_id' => $component->getKey(),
                    'unit_id' => $piece->getKey(),
                    'calculation_method' => ProductComponent::CalculationDirect,
                    'quantity' => '1.00000000',
                    'created_by' => $admin->getKey(),
                ]);
            }

            $stressProduct = $this->product(
                $company,
                996200,
                'Product-E2E-25-FG',
                'TEST 25-Component Product',
                Product::ClassificationFinishedProduct,
                $piece,
            );
            foreach (range(1, 25) as $number) {
                $component = $this->product(
                    $company,
                    996200 + $number,
                    sprintf('Product-E2E-25-COMP-%02d', $number),
                    sprintf('TEST Stress Component %02d', $number),
                    Product::ClassificationRawMaterial,
                    $piece,
                );
                ProductComponent::query()->create([
                    'company_id' => $company->getKey(),
                    'product_id' => $stressProduct->getKey(),
                    'component_product_id' => $component->getKey(),
                    'unit_id' => $piece->getKey(),
                    'calculation_method' => ProductComponent::CalculationDirect,
                    'quantity' => '1.00000000',
                    'created_by' => $admin->getKey(),
                ]);
            }

            Auth::login($admin);
            $primaryCustomer = $this->customer($company, $currency, 996001, 'E2E Sales-Origin Customer');
            $packingCustomerA = $this->customer($company, $currency, 996002, 'E2E Packing Customer A');
            $packingCustomerB = $this->customer($company, $currency, 996003, 'E2E Packing Customer B');
            $stressCustomer = $this->customer($company, $currency, 996004, 'E2E Stress Customer');

            $this->approvedSalesOrder(
                $company,
                $period,
                $branch,
                $finishedStore,
                $currency,
                $primaryCustomer,
                $finishedProduct,
                $carton,
                '10',
                'E2E-MFG-SALES-ORIGIN',
                ['packaging' => '100 pieces per carton', 'colour' => 'Natural'],
            );
            $this->approvedSalesOrder($company, $period, $branch, $finishedStore, $currency, $packingCustomerA, $kit, $piece, '1000', 'E2E-PACK-CUSTOMER-A');
            $this->approvedSalesOrder($company, $period, $branch, $finishedStore, $currency, $packingCustomerB, $kit, $piece, '1000', 'E2E-PACK-CUSTOMER-B');
            $this->approvedSalesOrder($company, $period, $branch, $finishedStore, $currency, $stressCustomer, $stressProduct, $piece, '1', 'E2E-25-COMPONENT-SALES');

            $this->restrictedUser($company, $branch, $period, 996010, 'e2e_warehouse', [
                'dashboard.view', 'inventory.documents.view', 'inventory.documents.create', 'inventory.documents.transfer',
                'inventory.reports.operational', 'inventory.stock_counts.view', 'inventory.stock_counts.create',
            ]);
            $this->restrictedUser($company, $branch, $period, 996011, 'e2e_planner', [
                'dashboard.view', 'production.orders.view', 'production.orders.plan', 'production.orders.release',
                'production.runs.view', 'production.runs.plan',
            ]);
            $this->restrictedUser($company, $branch, $period, 996012, 'e2e_quality', [
                'dashboard.view', 'production.runs.view', 'production.runs.qc', 'production.quality.view',
                'production.quality.create', 'production.quality.receive', 'production.quality.start',
                'production.quality.submit', 'production.quality.review', 'production.quality.close',
                'production.quality.reinspect',
            ]);
            $this->restrictedUser($company, $branch, $period, 996013, 'e2e_cost', [
                'dashboard.view', 'inventory.reports.operational', 'inventory.reports.financial', 'inventory.reports.export',
                'production.reports.operational', 'production.reports.financial', 'production.reports.export',
            ]);
        });

        Auth::logout();
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

    private function customer(Company $company, Currency $currency, int $number, string $name): Customer
    {
        $customer = Customer::query()->create([
            'doc_number' => $number,
            'doc_num' => 'Customer-E2E-MFG-'.$number,
            'company_id' => $company->getKey(),
            'name' => $name,
            'status' => 'active',
            'phone' => '+20 100 000 2424',
            'email' => strtolower(str_replace(' ', '.', $name)).'@shortcoded.test',
            'tax_number' => 'E2E-MFG-TAX-'.$number,
            'address' => 'Industrial Zone, Cairo, Egypt',
        ]);
        CustomerCommercialAgreement::query()->create([
            'company_id' => $company->getKey(),
            'customer_id' => $customer->getKey(),
            'currency_id' => $currency->getKey(),
            'customer_type' => CustomerCommercialAgreement::TypeCredit,
            'credit_limit' => '1000000.0000',
            'include_open_orders' => true,
            'required_advance_percentage' => 0,
            'required_advance_minimum' => 0,
            'blocking_enabled' => true,
            'temporary_override_allowed' => true,
            'status' => 'active',
        ]);

        return $customer;
    }

    /** @param array<string, string> $specifications */
    private function approvedSalesOrder(
        Company $company,
        FinancialPeriod $period,
        Branch $branch,
        BranchStore $store,
        Currency $currency,
        Customer $customer,
        Product $product,
        ItemUnit $unit,
        string $quantity,
        string $reference,
        array $specifications = [],
    ): SalesOrder {
        $orders = app(SalesOrderService::class);
        $order = $orders->create([
            'company_id' => $company->getKey(),
            'financial_period_id' => $period->getKey(),
            'branch_id' => $branch->getKey(),
            'branch_store_id' => $store->getKey(),
            'customer_id' => $customer->getKey(),
            'currency_id' => $currency->getKey(),
            'order_date' => now()->toDateString(),
            'expected_delivery_date' => now()->addWeek()->toDateString(),
            'sales_channel' => 'direct',
            'exchange_rate' => '1.000000',
            'customer_reference' => $reference,
            'notes' => 'Approved canonical Sales order for manufacturing browser acceptance.',
            'lines' => [[
                'product_id' => $product->getKey(),
                'unit_id' => $unit->getKey(),
                'description' => $product->name,
                'quantity' => $quantity,
                'unit_price' => '1.0000',
                'discount_amount' => '0.0000',
                'tax_amount' => '0.0000',
                'specifications' => $specifications,
                'production_notes' => 'Manufacture against the approved Sales specification snapshot.',
            ]],
        ]);

        return $orders->approve($orders->submit($order));
    }

    /** @param list<string> $permissions */
    private function restrictedUser(
        Company $company,
        Branch $branch,
        FinancialPeriod $period,
        int $number,
        string $username,
        array $permissions,
    ): User {
        $user = User::query()->create([
            'doc_number' => $number,
            'doc_num' => 'User-E2E-MFG-'.$number,
            'name' => str($username)->replace('_', ' ')->title()->toString(),
            'username' => $username,
            'email' => $username.'@shortcoded.test',
            'password' => 'e2e-password',
            'status' => 'active',
            'locale' => 'en',
            'email_verified_at' => now(),
            'default_company_id' => $company->getKey(),
            'default_branch_id' => $branch->getKey(),
            'default_financial_period_id' => $period->getKey(),
        ]);
        $user->givePermissionTo($permissions);

        return $user;
    }
}
