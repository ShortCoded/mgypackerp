<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Database\Seeders\DefaultChartOfAccountsSeeder;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\AccountClassification;
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
use Modules\Finance\Models\BankAccount;
use Modules\Finance\Models\Cashbox;
use Modules\Inventory\Models\InventoryAccountingMapping;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Sales\Models\Customer;
use Modules\Sales\Models\CustomerCommercialAgreement;

class SalesCycleBrowserE2eSeeder extends Seeder
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
            $currency = Currency::query()->where('company_id', $company->getKey())->orderByDesc('is_main')->firstOrFail();
            $admin = User::query()->where('username', 'admin')->firstOrFail();

            $company->update([
                'legal_name' => 'Short Coded Plastic Industries S.A.E.',
                'commercial_register_number' => 'E2E-CR-2026',
                'vat_registration_number' => 'E2E-VAT-2026',
                'phone' => '+20 2 5555 2026',
                'email' => 'sales-e2e@shortcoded.test',
                'address' => '10th of Ramadan Industrial Zone, Egypt',
                'authorized_signatory_name' => 'E2E Authorized Manager',
                'authorized_signatory_title' => 'General Manager',
            ]);
            $admin->update([
                'locale' => 'en',
                'default_company_id' => $company->getKey(),
                'default_branch_id' => $branch->getKey(),
                'default_financial_period_id' => $period->getKey(),
            ]);

            $store = BranchStore::query()->create([
                'branch_id' => $branch->getKey(),
                'name' => 'E2E Finished Goods Store',
                'position' => 1,
                'created_by' => $admin->getKey(),
            ]);
            $piece = ItemUnit::query()->create([
                'company_id' => $company->getKey(),
                'doc_number' => 990001,
                'doc_num' => 'Unit-E2E-PIECE',
                'name' => 'Piece',
                'status' => 'active',
            ]);
            $carton = ItemUnit::query()->create([
                'company_id' => $company->getKey(),
                'doc_number' => 990002,
                'doc_num' => 'Unit-E2E-CARTON',
                'name' => 'Carton',
                'status' => 'active',
            ]);
            $product = Product::query()->create([
                'company_id' => $company->getKey(),
                'doc_number' => 990001,
                'doc_num' => 'Product-E2E-SPOON',
                'name' => 'TEST Plastic Spoon',
                'item_classification' => Product::ClassificationFinishedProduct,
                'item_unit_id' => $piece->getKey(),
                'equivalent_value' => '0.001',
                'equivalent_unit_id' => $carton->getKey(),
                'status' => 'active',
            ]);
            $rawMaterial = Product::query()->create([
                'company_id' => $company->getKey(),
                'doc_number' => 990003,
                'doc_num' => 'Product-E2E-RESIN',
                'name' => 'E2E Food Grade Resin',
                'item_classification' => Product::ClassificationRawMaterial,
                'item_unit_id' => $piece->getKey(),
                'status' => 'active',
            ]);
            ProductComponent::query()->create([
                'company_id' => $company->getKey(),
                'product_id' => $product->getKey(),
                'component_product_id' => $rawMaterial->getKey(),
                'unit_id' => $piece->getKey(),
                'calculation_method' => ProductComponent::CalculationDirect,
                'quantity' => '1.00000000',
                'created_by' => $admin->getKey(),
            ]);
            Product::query()->create([
                'company_id' => $company->getKey(),
                'doc_number' => 990002,
                'doc_num' => 'Product-E2E-SERVICE',
                'name' => 'TEST Packaging Design Service',
                'item_classification' => Product::ClassificationService,
                'item_unit_id' => $piece->getKey(),
                'status' => 'active',
            ]);

            $accountId = fn (string $code): int => (int) Account::query()
                ->where('company_id', $company->getKey())
                ->where('account_code', $code)
                ->valueOrFail('id');
            InventoryAccountingMapping::query()->create([
                'company_id' => $company->getKey(),
                'raw_material_inventory_account_id' => $accountId('1131'),
                'packaging_inventory_account_id' => $accountId('1134'),
                'semi_finished_inventory_account_id' => $accountId('1132'),
                'finished_goods_inventory_account_id' => $accountId('1133'),
                'wip_account_id' => $accountId('1132'),
                'production_waste_account_id' => $accountId('551'),
                'recoverable_scrap_inventory_account_id' => $accountId('1134'),
                'warehouse_damage_loss_account_id' => $accountId('551'),
                'inventory_adjustment_gain_account_id' => $accountId('432'),
                'inventory_adjustment_loss_account_id' => $accountId('551'),
                'production_variance_account_id' => $accountId('551'),
                'quarantine_inventory_account_id' => $accountId('1134'),
                'rework_inventory_account_id' => $accountId('1132'),
                'grni_account_id' => $accountId('212'),
                'purchase_price_variance_account_id' => $accountId('551'),
                'created_by' => $admin->getKey(),
            ]);

            $receivableClassification = AccountClassification::query()->where('code', 'accounts_receivable')->firstOrFail();
            $receivableParent = Account::query()->where('company_id', $company->getKey())
                ->where('account_classification_id', $receivableClassification->getKey())
                ->where('is_group', true)->orderByDesc('level')->firstOrFail();
            $mainCustomer = $this->customer(
                $company,
                $receivableClassification,
                $receivableParent,
                990001,
                'E2E Main Customer',
                '500000',
                $currency,
            );
            $this->customer(
                $company,
                $receivableClassification,
                $receivableParent,
                990002,
                'E2E Credit Hold Customer',
                '100',
                $currency,
            );

            $cashClassification = AccountClassification::query()->where('code', 'cash')->firstOrFail();
            $cashParent = Account::query()->where('company_id', $company->getKey())->where('account_code', '1111')->firstOrFail();
            $cashAccount = $this->postableAccount($company, $cashClassification, $cashParent, 990003, '111199003', 'E2E Cash Account');
            $cashbox = Cashbox::query()->create([
                'doc_number' => 990001,
                'doc_num' => 'Cashbox-E2E',
                'company_id' => $company->getKey(),
                'branch_id' => $branch->getKey(),
                'account_id' => $cashAccount->getKey(),
                'name' => 'E2E Customer Collections',
                'status' => 'active',
            ]);
            $bankParent = Account::query()->where('company_id', $company->getKey())->where('account_code', '1112')->firstOrFail();
            $bankLedger = $this->postableAccount($company, $cashClassification, $bankParent, 990004, '111299004', 'E2E Bank Account');
            BankAccount::query()->create([
                'doc_number' => 990001,
                'doc_num' => 'BankAccount-E2E',
                'company_id' => $company->getKey(),
                'account_id' => $bankLedger->getKey(),
                'currency_id' => $currency->getKey(),
                'account_name' => 'E2E Cheque Bank',
                'account_number' => 'E2E-2026-001',
                'status' => 'active',
            ]);

            InventoryTransaction::query()->create([
                'posting_key' => 'sales-cycle-browser-e2e-opening',
                'company_id' => $company->getKey(),
                'financial_period_id' => $period->getKey(),
                'branch_id' => $branch->getKey(),
                'branch_store_id' => $store->getKey(),
                'transaction_date' => now()->toDateString(),
                'transaction_type' => 'opening_stock',
                'product_id' => $product->getKey(),
                'unit_id' => $piece->getKey(),
                'quantity_in' => '30000',
                'quantity_out' => 0,
                'source_type' => 'sales_cycle_browser_e2e',
                'source_id' => $mainCustomer->getKey(),
                'source_doc_num' => 'E2E-OPENING-30-CARTONS',
                'unit_cost' => '0.0100',
                'total_cost' => '300.0000',
                'created_by' => $admin->getKey(),
            ]);
            InventoryTransaction::query()->create([
                'posting_key' => 'sales-cycle-browser-e2e-resin-opening',
                'company_id' => $company->getKey(),
                'financial_period_id' => $period->getKey(),
                'branch_id' => $branch->getKey(),
                'branch_store_id' => $store->getKey(),
                'transaction_date' => now()->toDateString(),
                'transaction_type' => 'opening_stock',
                'product_id' => $rawMaterial->getKey(),
                'unit_id' => $piece->getKey(),
                'quantity_in' => '100000',
                'quantity_out' => 0,
                'source_type' => 'sales_cycle_browser_e2e',
                'source_id' => $rawMaterial->getKey(),
                'source_doc_num' => 'E2E-OPENING-RESIN',
                'unit_cost' => '0.0050',
                'total_cost' => '500.0000',
                'created_by' => $admin->getKey(),
            ]);

            $cashbox->currencies()->create([
                'currency_id' => $currency->getKey(),
                'is_default' => true,
                'status' => 'active',
            ]);
        });
    }

    private function customer(
        Company $company,
        AccountClassification $classification,
        Account $parent,
        int $number,
        string $name,
        string $creditLimit,
        Currency $currency,
    ): Customer {
        $account = $this->postableAccount(
            $company,
            $classification,
            $parent,
            $number,
            '1121'.$number,
            $name.' Receivable',
        );
        $customer = Customer::query()->create([
            'doc_number' => $number,
            'doc_num' => 'Customer-'.$number,
            'company_id' => $company->getKey(),
            'account_id' => $account->getKey(),
            'account_group_id' => $parent->getKey(),
            'name' => $name,
            'status' => 'active',
            'phone' => '+20 100 000 2026',
            'email' => strtolower(str_replace(' ', '.', $name)).'@shortcoded.test',
            'tax_number' => 'E2E-TAX-'.$number,
            'address' => 'Industrial Zone, Cairo, Egypt',
        ]);
        CustomerCommercialAgreement::query()->create([
            'company_id' => $company->getKey(),
            'customer_id' => $customer->getKey(),
            'currency_id' => $currency->getKey(),
            'customer_type' => CustomerCommercialAgreement::TypeCredit,
            'credit_limit' => $creditLimit,
            'include_open_orders' => true,
            'required_advance_percentage' => 0,
            'required_advance_minimum' => 0,
            'blocking_enabled' => true,
            'temporary_override_allowed' => true,
            'status' => 'active',
        ]);

        return $customer;
    }

    private function postableAccount(
        Company $company,
        AccountClassification $classification,
        Account $parent,
        int $number,
        string $code,
        string $name,
    ): Account {
        return Account::query()->create([
            'doc_number' => $number,
            'doc_num' => 'Account-'.$number,
            'company_id' => $company->getKey(),
            'account_code' => $code,
            'name' => $name,
            'parent_id' => $parent->getKey(),
            'level' => ((int) $parent->level) + 1,
            'account_classification_id' => $classification->getKey(),
            'account_type' => Account::TypeAsset,
            'statement_type' => Account::StatementFinancialPosition,
            'normal_balance' => Account::BalanceDebit,
            'is_group' => false,
            'is_postable' => true,
            'status' => 'active',
        ]);
    }
}
