<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Accounting\Models\Account;
use Modules\Core\Models\Branch;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Company;
use Modules\Core\Models\Currency;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Models\Product;
use Modules\Core\Services\DocumentNumberService;
use Modules\Finance\Models\BankAccount;
use Modules\Finance\Models\Cashbox;
use Modules\Finance\Models\CashboxCurrency;
use Modules\Production\Models\ProductionOrder;
use Modules\Production\Models\ProductionOrderLine;
use Modules\Purchases\Models\Supplier;
use Modules\Sales\Models\Customer;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderLine;

class ProcurementBrowserVerificationSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::query()->where('status', 'active')->firstOrFail();
        $branch = Branch::query()->where('company_id', $company->getKey())->where('status', 'active')->firstOrFail();
        $period = FinancialPeriod::query()->where('company_id', $company->getKey())->where('is_closed', false)->firstOrFail();
        $currency = Currency::query()->where('company_id', $company->getKey())->where('is_main', true)->firstOrFail();
        $company->forceFill([
            'legal_name' => 'Short Coded Plastic Manufacturing SAE',
            'commercial_register_number' => 'CR-BROWSER-2026',
            'tax_card_number' => 'TAX-BROWSER-2026',
            'vat_registration_number' => 'VAT-BROWSER-2026',
            'address' => 'Industrial Zone, Cairo',
            'phone' => '+20 2 0000 0000',
            'authorized_signatory_name' => 'Browser Verification Signatory',
            'authorized_signatory_title' => 'Procurement Director',
        ])->save();

        $store = BranchStore::query()->create([
            'branch_id' => $branch->getKey(),
            'name' => 'Browser Raw Materials Store',
            'position' => 1,
        ]);
        $unit = ItemUnit::query()->create([
            'company_id' => $company->getKey(),
            'doc_number' => 9801,
            'doc_num' => 'Unit-BROWSER-KG',
            'name' => 'Kilogram',
            'status' => 'active',
        ]);
        $raw = Product::query()->create([
            'company_id' => $company->getKey(),
            'doc_number' => 9801,
            'doc_num' => 'Product-BROWSER-RESIN',
            'name' => 'Browser Polymer Resin',
            'item_classification' => Product::ClassificationRawMaterial,
            'item_unit_id' => $unit->getKey(),
            'status' => 'active',
        ]);
        Product::query()->create([
            'company_id' => $company->getKey(),
            'doc_number' => 9802,
            'doc_num' => 'Product-BROWSER-SERVICE',
            'name' => 'Browser Machine Calibration',
            'item_classification' => Product::ClassificationService,
            'item_unit_id' => $unit->getKey(),
            'status' => 'active',
        ]);
        $finished = Product::query()->create([
            'company_id' => $company->getKey(),
            'doc_number' => 9803,
            'doc_num' => 'Product-BROWSER-FINISHED',
            'name' => 'Browser Finished Container',
            'item_classification' => Product::ClassificationFinishedProduct,
            'item_unit_id' => $unit->getKey(),
            'status' => 'active',
        ]);

        $supplierAAccount = $this->postableAccount($company, '2111', '2111981', 'Browser Supplier A Payable');
        $supplierBAccount = $this->postableAccount($company, '2111', '2111982', 'Browser Supplier B Payable');
        Supplier::query()->create([
            'doc_number' => 9801,
            'doc_num' => 'Supplier-BROWSER-A',
            'company_id' => $company->getKey(),
            'account_id' => $supplierAAccount->getKey(),
            'name' => 'Browser Resin Supplier A',
            'payment_terms_days' => 30,
            'status' => 'active',
        ]);
        Supplier::query()->create([
            'doc_number' => 9802,
            'doc_num' => 'Supplier-BROWSER-B',
            'company_id' => $company->getKey(),
            'account_id' => $supplierBAccount->getKey(),
            'name' => 'Browser Resin Supplier B',
            'payment_terms_days' => 45,
            'status' => 'active',
        ]);

        $cashAccount = $this->postableAccount($company, '1111', '1111981', 'Browser Procurement Cash');
        $cashbox = Cashbox::query()->create([
            ...app(DocumentNumberService::class)->nextForCompany('cashboxes', Cashbox::class, $company->getKey()),
            'company_id' => $company->getKey(),
            'branch_id' => $branch->getKey(),
            'account_id' => $cashAccount->getKey(),
            'name' => 'Browser Procurement Cashbox',
            'status' => 'active',
        ]);
        CashboxCurrency::query()->create([
            'cashbox_id' => $cashbox->getKey(),
            'currency_id' => $currency->getKey(),
            'is_default' => true,
            'status' => 'active',
        ]);

        $bankParent = Account::query()->where('company_id', $company->getKey())->where('account_code', '1112')->firstOrFail();
        $bankGl = $this->postableAccount($company, '1112', '1112981', 'Browser Procurement Bank');
        BankAccount::query()->create([
            ...app(DocumentNumberService::class)->nextForCompany('bank_accounts', BankAccount::class, $company->getKey()),
            'company_id' => $company->getKey(),
            'bank_id' => $bankParent->getKey(),
            'account_id' => $bankGl->getKey(),
            'currency_id' => $currency->getKey(),
            'account_name' => 'Browser Procurement Operating Account',
            'account_number' => 'BR-0011223344',
            'bank_branch_name' => 'Browser Factory Branch',
            'status' => 'active',
        ]);

        $customer = Customer::query()->create([
            'doc_number' => 9801,
            'doc_num' => 'Customer-BROWSER',
            'company_id' => $company->getKey(),
            'name' => 'Browser Production Customer',
            'status' => 'active',
        ]);
        $salesOrder = SalesOrder::query()->create([
            'doc_number' => 9801,
            'doc_num' => 'SO-BROWSER',
            'company_id' => $company->getKey(),
            'financial_period_id' => $period->getKey(),
            'branch_id' => $branch->getKey(),
            'branch_store_id' => $store->getKey(),
            'customer_id' => $customer->getKey(),
            'currency_id' => $currency->getKey(),
            'order_date' => now()->toDateString(),
            'expected_delivery_date' => now()->addMonth()->toDateString(),
            'status' => SalesOrder::StatusApproved,
        ]);
        $salesLine = SalesOrderLine::query()->create([
            'sales_order_id' => $salesOrder->getKey(),
            'line_number' => 1,
            'product_id' => $finished->getKey(),
            'unit_id' => $unit->getKey(),
            'description' => 'Browser production demand',
            'quantity' => 10,
        ]);
        $productionOrder = ProductionOrder::query()->create([
            'doc_number' => 9801,
            'doc_num' => 'PROD-BROWSER',
            'company_id' => $company->getKey(),
            'financial_period_id' => $period->getKey(),
            'branch_id' => $branch->getKey(),
            'sales_order_id' => $salesOrder->getKey(),
            'customer_id' => $customer->getKey(),
            'production_order_date' => now()->toDateString(),
            'expected_delivery_date' => now()->addMonth()->toDateString(),
            'status' => ProductionOrder::StatusReleased,
        ]);
        ProductionOrderLine::query()->create([
            'production_order_id' => $productionOrder->getKey(),
            'sales_order_line_id' => $salesLine->getKey(),
            'line_number' => 1,
            'public_id' => '98000000-0000-4000-8000-000000000001',
            'product_id' => $finished->getKey(),
            'unit_id' => $unit->getKey(),
            'description' => 'Browser production demand',
            'quantity' => 10,
        ]);
    }

    private function postableAccount(Company $company, string $parentCode, string $accountCode, string $name): Account
    {
        $parent = Account::query()->where('company_id', $company->getKey())->where('account_code', $parentCode)->firstOrFail();

        return Account::query()->create([
            ...app(DocumentNumberService::class)->nextForCompany('accounts', Account::class, $company->getKey()),
            'company_id' => $company->getKey(),
            'account_code' => $accountCode,
            'name' => $name,
            'name_en' => $name,
            'parent_id' => $parent->getKey(),
            'level' => ((int) $parent->level) + 1,
            'account_classification_id' => $parent->account_classification_id,
            'account_type' => $parent->account_type,
            'statement_type' => $parent->statement_type,
            'normal_balance' => $parent->normal_balance,
            'is_group' => false,
            'is_postable' => true,
            'is_system' => false,
            'status' => 'active',
        ]);
    }
}
