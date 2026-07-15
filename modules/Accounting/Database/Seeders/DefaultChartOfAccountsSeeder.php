<?php

namespace Modules\Accounting\Database\Seeders;

use Database\Seeders\DefaultOperatingContextSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\AccountClassification;
use Modules\Core\Models\Company;
use Modules\Core\Services\DocumentNumberService;

class DefaultChartOfAccountsSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(AccountClassificationsSeeder::class);

        if (! Company::query()->exists()) {
            $this->call(DefaultOperatingContextSeeder::class);
        }

        DB::transaction(function (): void {
            $companies = Company::query()
                ->whereNull('deleted_at')
                ->orderBy('id')
                ->get();

            foreach ($companies as $company) {
                $this->seedForCompany($company);
            }
        });
    }

    private function seedForCompany(Company $company): void
    {
        $accountsByCode = Account::withTrashed()
            ->where('company_id', $company->getKey())
            ->get()
            ->keyBy('account_code');
        $classificationsByCode = AccountClassification::query()
            ->pluck('id', 'code');
        $groupCodes = collect($this->accounts())
            ->pluck('parent_code')
            ->filter()
            ->unique()
            ->all();
        $forcedGroupCodes = ['1111', '1112', '1121', '2111'];

        foreach ($this->accounts() as $data) {
            $account = $accountsByCode->get($data['account_code']);
            $parent = isset($data['parent_code'])
                ? $accountsByCode->get($data['parent_code'])
                : null;
            $classificationId = isset($data['classification_code'])
                ? $classificationsByCode->get($data['classification_code'])
                : null;
            $isGroup = in_array($data['account_code'], $groupCodes, true)
                || in_array($data['account_code'], $forcedGroupCodes, true);

            unset($data['parent_code'], $data['classification_code']);

            $payload = [
                ...$data,
                'company_id' => $company->getKey(),
                'parent_id' => $parent?->getKey(),
                'level' => $parent instanceof Account ? ((int) $parent->level) + 1 : 1,
                'account_classification_id' => $classificationId,
                'is_group' => $isGroup,
                'is_postable' => ! $isGroup,
                'is_system' => true,
                'status' => 'active',
            ];

            if ($account instanceof Account) {
                if ($account->trashed()) {
                    $account->restore();
                }

                $account->forceFill($payload)->save();
                $this->ensureDocumentNumber($account, $company);
                $accountsByCode->put($account->account_code, $account->refresh());

                continue;
            }

            $account = Account::query()->create([
                ...app(DocumentNumberService::class)->nextForCompany('accounts', Account::class, $company->getKey()),
                ...$payload,
            ]);
            $accountsByCode->put($account->account_code, $account);
        }
    }

    private function ensureDocumentNumber(Account $account, Company $company): void
    {
        if ($account->doc_number && $account->doc_num) {
            return;
        }

        $account->forceFill(app(DocumentNumberService::class)->nextForCompany('accounts', Account::class, $company->getKey()))->save();
    }

    /**
     * @return list<array{
     *     account_code: string,
     *     name: string,
     *     name_en: string,
     *     account_type: string,
     *     statement_type: string,
     *     normal_balance: string,
     *     parent_code?: string,
     *     classification_code?: string
     * }>
     */
    private function accounts(): array
    {
        return [
            ['account_code' => '1', 'name' => 'الأصول', 'name_en' => 'Assets', 'account_type' => Account::TypeAsset, 'statement_type' => Account::StatementFinancialPosition, 'normal_balance' => Account::BalanceDebit],
            ['account_code' => '11', 'name' => 'الأصول المتداولة', 'name_en' => 'Current Assets', 'parent_code' => '1', 'account_type' => Account::TypeAsset, 'statement_type' => Account::StatementFinancialPosition, 'normal_balance' => Account::BalanceDebit],
            ['account_code' => '111', 'name' => 'النقدية وما في حكمها', 'name_en' => 'Cash and Cash Equivalents', 'parent_code' => '11', 'account_type' => Account::TypeAsset, 'statement_type' => Account::StatementFinancialPosition, 'normal_balance' => Account::BalanceDebit],
            ['account_code' => '1111', 'name' => 'الخزائن', 'name_en' => 'Cashboxes', 'parent_code' => '111', 'classification_code' => 'cash', 'account_type' => Account::TypeAsset, 'statement_type' => Account::StatementFinancialPosition, 'normal_balance' => Account::BalanceDebit],
            ['account_code' => '1112', 'name' => 'البنك', 'name_en' => 'Bank', 'parent_code' => '111', 'classification_code' => 'bank', 'account_type' => Account::TypeAsset, 'statement_type' => Account::StatementFinancialPosition, 'normal_balance' => Account::BalanceDebit],
            ['account_code' => '112', 'name' => 'العملاء وأوراق القبض', 'name_en' => 'Customers and Notes Receivable', 'parent_code' => '11', 'account_type' => Account::TypeAsset, 'statement_type' => Account::StatementFinancialPosition, 'normal_balance' => Account::BalanceDebit],
            ['account_code' => '1121', 'name' => 'العملاء', 'name_en' => 'Customers', 'parent_code' => '112', 'classification_code' => 'accounts_receivable', 'account_type' => Account::TypeAsset, 'statement_type' => Account::StatementFinancialPosition, 'normal_balance' => Account::BalanceDebit],
            ['account_code' => '1122', 'name' => 'أوراق القبض', 'name_en' => 'Notes Receivable', 'parent_code' => '112', 'classification_code' => 'accounts_receivable', 'account_type' => Account::TypeAsset, 'statement_type' => Account::StatementFinancialPosition, 'normal_balance' => Account::BalanceDebit],
            ['account_code' => '1123', 'name' => 'مخصص الديون المشكوك فيها', 'name_en' => 'Allowance for Doubtful Debts', 'parent_code' => '112', 'classification_code' => 'accounts_receivable', 'account_type' => Account::TypeAsset, 'statement_type' => Account::StatementFinancialPosition, 'normal_balance' => Account::BalanceCredit],
            ['account_code' => '113', 'name' => 'المخزون', 'name_en' => 'Inventory', 'parent_code' => '11', 'classification_code' => 'inventory', 'account_type' => Account::TypeAsset, 'statement_type' => Account::StatementFinancialPosition, 'normal_balance' => Account::BalanceDebit],
            ['account_code' => '1131', 'name' => 'مخزون خامات', 'name_en' => 'Raw Materials Inventory', 'parent_code' => '113', 'classification_code' => 'inventory', 'account_type' => Account::TypeAsset, 'statement_type' => Account::StatementFinancialPosition, 'normal_balance' => Account::BalanceDebit],
            ['account_code' => '1132', 'name' => 'مخزون إنتاج تحت التشغيل', 'name_en' => 'Work in Process Inventory', 'parent_code' => '113', 'classification_code' => 'inventory', 'account_type' => Account::TypeAsset, 'statement_type' => Account::StatementFinancialPosition, 'normal_balance' => Account::BalanceDebit],
            ['account_code' => '1133', 'name' => 'مخزون إنتاج تام', 'name_en' => 'Finished Goods Inventory', 'parent_code' => '113', 'classification_code' => 'inventory', 'account_type' => Account::TypeAsset, 'statement_type' => Account::StatementFinancialPosition, 'normal_balance' => Account::BalanceDebit],
            ['account_code' => '1134', 'name' => 'مخزون بضاعة', 'name_en' => 'Merchandise Inventory', 'parent_code' => '113', 'classification_code' => 'inventory', 'account_type' => Account::TypeAsset, 'statement_type' => Account::StatementFinancialPosition, 'normal_balance' => Account::BalanceDebit],
            ['account_code' => '114', 'name' => 'المصروفات المدفوعة مقدمًا', 'name_en' => 'Prepaid Expenses', 'parent_code' => '11', 'classification_code' => 'prepaid_expenses', 'account_type' => Account::TypeAsset, 'statement_type' => Account::StatementFinancialPosition, 'normal_balance' => Account::BalanceDebit],
            ['account_code' => '115', 'name' => 'الإيرادات المستحقة', 'name_en' => 'Accrued Revenue', 'parent_code' => '11', 'account_type' => Account::TypeAsset, 'statement_type' => Account::StatementFinancialPosition, 'normal_balance' => Account::BalanceDebit],
            ['account_code' => '116', 'name' => 'العهد والسلف', 'name_en' => 'Custodies and Advances', 'parent_code' => '11', 'account_type' => Account::TypeAsset, 'statement_type' => Account::StatementFinancialPosition, 'normal_balance' => Account::BalanceDebit],
            ['account_code' => '12', 'name' => 'الأصول غير المتداولة', 'name_en' => 'Non-current Assets', 'parent_code' => '1', 'account_type' => Account::TypeAsset, 'statement_type' => Account::StatementFinancialPosition, 'normal_balance' => Account::BalanceDebit],
            ['account_code' => '121', 'name' => 'الأصول الثابتة', 'name_en' => 'Fixed Assets', 'parent_code' => '12', 'classification_code' => 'fixed_assets', 'account_type' => Account::TypeAsset, 'statement_type' => Account::StatementFinancialPosition, 'normal_balance' => Account::BalanceDebit],
            ['account_code' => '1211', 'name' => 'الأراضي', 'name_en' => 'Land', 'parent_code' => '121', 'classification_code' => 'fixed_assets', 'account_type' => Account::TypeAsset, 'statement_type' => Account::StatementFinancialPosition, 'normal_balance' => Account::BalanceDebit],
            ['account_code' => '1212', 'name' => 'المباني', 'name_en' => 'Buildings', 'parent_code' => '121', 'classification_code' => 'fixed_assets', 'account_type' => Account::TypeAsset, 'statement_type' => Account::StatementFinancialPosition, 'normal_balance' => Account::BalanceDebit],
            ['account_code' => '1213', 'name' => 'الآلات والمعدات', 'name_en' => 'Machinery and Equipment', 'parent_code' => '121', 'classification_code' => 'fixed_assets', 'account_type' => Account::TypeAsset, 'statement_type' => Account::StatementFinancialPosition, 'normal_balance' => Account::BalanceDebit],
            ['account_code' => '1214', 'name' => 'وسائل النقل', 'name_en' => 'Vehicles', 'parent_code' => '121', 'classification_code' => 'fixed_assets', 'account_type' => Account::TypeAsset, 'statement_type' => Account::StatementFinancialPosition, 'normal_balance' => Account::BalanceDebit],
            ['account_code' => '1215', 'name' => 'الأثاث والتجهيزات', 'name_en' => 'Furniture and Fixtures', 'parent_code' => '121', 'classification_code' => 'fixed_assets', 'account_type' => Account::TypeAsset, 'statement_type' => Account::StatementFinancialPosition, 'normal_balance' => Account::BalanceDebit],
            ['account_code' => '122', 'name' => 'مجمع الإهلاك', 'name_en' => 'Accumulated Depreciation', 'parent_code' => '12', 'classification_code' => 'accumulated_depreciation', 'account_type' => Account::TypeAsset, 'statement_type' => Account::StatementFinancialPosition, 'normal_balance' => Account::BalanceCredit],
            ['account_code' => '123', 'name' => 'الأصول غير الملموسة', 'name_en' => 'Intangible Assets', 'parent_code' => '12', 'account_type' => Account::TypeAsset, 'statement_type' => Account::StatementFinancialPosition, 'normal_balance' => Account::BalanceDebit],
            ['account_code' => '124', 'name' => 'الاستثمارات طويلة الأجل', 'name_en' => 'Long-term Investments', 'parent_code' => '12', 'account_type' => Account::TypeAsset, 'statement_type' => Account::StatementFinancialPosition, 'normal_balance' => Account::BalanceDebit],

            ['account_code' => '2', 'name' => 'الالتزامات', 'name_en' => 'Liabilities', 'account_type' => Account::TypeLiability, 'statement_type' => Account::StatementFinancialPosition, 'normal_balance' => Account::BalanceCredit],
            ['account_code' => '21', 'name' => 'الالتزامات المتداولة', 'name_en' => 'Current Liabilities', 'parent_code' => '2', 'account_type' => Account::TypeLiability, 'statement_type' => Account::StatementFinancialPosition, 'normal_balance' => Account::BalanceCredit],
            ['account_code' => '211', 'name' => 'الموردون وأوراق الدفع', 'name_en' => 'Suppliers and Notes Payable', 'parent_code' => '21', 'account_type' => Account::TypeLiability, 'statement_type' => Account::StatementFinancialPosition, 'normal_balance' => Account::BalanceCredit],
            ['account_code' => '2111', 'name' => 'الموردون', 'name_en' => 'Suppliers', 'parent_code' => '211', 'classification_code' => 'accounts_payable', 'account_type' => Account::TypeLiability, 'statement_type' => Account::StatementFinancialPosition, 'normal_balance' => Account::BalanceCredit],
            ['account_code' => '2112', 'name' => 'أوراق الدفع', 'name_en' => 'Notes Payable', 'parent_code' => '211', 'classification_code' => 'accounts_payable', 'account_type' => Account::TypeLiability, 'statement_type' => Account::StatementFinancialPosition, 'normal_balance' => Account::BalanceCredit],
            ['account_code' => '212', 'name' => 'المصروفات المستحقة', 'name_en' => 'Accrued Expenses', 'parent_code' => '21', 'classification_code' => 'accrued_expenses', 'account_type' => Account::TypeLiability, 'statement_type' => Account::StatementFinancialPosition, 'normal_balance' => Account::BalanceCredit],
            ['account_code' => '213', 'name' => 'الضرائب المستحقة', 'name_en' => 'Taxes Payable', 'parent_code' => '21', 'classification_code' => 'tax_payable', 'account_type' => Account::TypeLiability, 'statement_type' => Account::StatementFinancialPosition, 'normal_balance' => Account::BalanceCredit],
            ['account_code' => '2131', 'name' => 'ضريبة القيمة المضافة', 'name_en' => 'Value Added Tax', 'parent_code' => '213', 'classification_code' => 'tax_payable', 'account_type' => Account::TypeLiability, 'statement_type' => Account::StatementFinancialPosition, 'normal_balance' => Account::BalanceCredit],
            ['account_code' => '2132', 'name' => 'ضريبة الخصم والإضافة', 'name_en' => 'Withholding and Addition Tax', 'parent_code' => '213', 'classification_code' => 'tax_payable', 'account_type' => Account::TypeLiability, 'statement_type' => Account::StatementFinancialPosition, 'normal_balance' => Account::BalanceCredit],
            ['account_code' => '2133', 'name' => 'ضرائب أخرى', 'name_en' => 'Other Taxes', 'parent_code' => '213', 'classification_code' => 'tax_payable', 'account_type' => Account::TypeLiability, 'statement_type' => Account::StatementFinancialPosition, 'normal_balance' => Account::BalanceCredit],
            ['account_code' => '214', 'name' => 'القروض قصيرة الأجل', 'name_en' => 'Short-term Loans', 'parent_code' => '21', 'classification_code' => 'loans_payable', 'account_type' => Account::TypeLiability, 'statement_type' => Account::StatementFinancialPosition, 'normal_balance' => Account::BalanceCredit],
            ['account_code' => '215', 'name' => 'الدائنون المتنوعون', 'name_en' => 'Other Creditors', 'parent_code' => '21', 'account_type' => Account::TypeLiability, 'statement_type' => Account::StatementFinancialPosition, 'normal_balance' => Account::BalanceCredit],
            ['account_code' => '22', 'name' => 'الالتزامات غير المتداولة', 'name_en' => 'Non-current Liabilities', 'parent_code' => '2', 'account_type' => Account::TypeLiability, 'statement_type' => Account::StatementFinancialPosition, 'normal_balance' => Account::BalanceCredit],
            ['account_code' => '221', 'name' => 'القروض طويلة الأجل', 'name_en' => 'Long-term Loans', 'parent_code' => '22', 'classification_code' => 'loans_payable', 'account_type' => Account::TypeLiability, 'statement_type' => Account::StatementFinancialPosition, 'normal_balance' => Account::BalanceCredit],
            ['account_code' => '222', 'name' => 'الالتزامات طويلة الأجل الأخرى', 'name_en' => 'Other Long-term Liabilities', 'parent_code' => '22', 'account_type' => Account::TypeLiability, 'statement_type' => Account::StatementFinancialPosition, 'normal_balance' => Account::BalanceCredit],

            ['account_code' => '3', 'name' => 'حقوق الملكية', 'name_en' => 'Equity', 'account_type' => Account::TypeEquity, 'statement_type' => Account::StatementFinancialPosition, 'normal_balance' => Account::BalanceCredit],
            ['account_code' => '31', 'name' => 'رأس المال', 'name_en' => 'Capital', 'parent_code' => '3', 'classification_code' => 'capital', 'account_type' => Account::TypeEquity, 'statement_type' => Account::StatementFinancialPosition, 'normal_balance' => Account::BalanceCredit],
            ['account_code' => '32', 'name' => 'جاري الشركاء / المسحوبات', 'name_en' => 'Partners Current Accounts / Drawings', 'parent_code' => '3', 'account_type' => Account::TypeEquity, 'statement_type' => Account::StatementFinancialPosition, 'normal_balance' => Account::BalanceDebit],
            ['account_code' => '33', 'name' => 'الاحتياطيات', 'name_en' => 'Reserves', 'parent_code' => '3', 'account_type' => Account::TypeEquity, 'statement_type' => Account::StatementFinancialPosition, 'normal_balance' => Account::BalanceCredit],
            ['account_code' => '34', 'name' => 'الأرباح المحتجزة', 'name_en' => 'Retained Earnings', 'parent_code' => '3', 'classification_code' => 'retained_earnings', 'account_type' => Account::TypeEquity, 'statement_type' => Account::StatementFinancialPosition, 'normal_balance' => Account::BalanceCredit],
            ['account_code' => '35', 'name' => 'صافي الربح أو الخسارة', 'name_en' => 'Net Profit or Loss', 'parent_code' => '3', 'account_type' => Account::TypeEquity, 'statement_type' => Account::StatementFinancialPosition, 'normal_balance' => Account::BalanceCredit],

            ['account_code' => '4', 'name' => 'الإيرادات', 'name_en' => 'Revenue', 'account_type' => Account::TypeRevenue, 'statement_type' => Account::StatementIncomeStatement, 'normal_balance' => Account::BalanceCredit],
            ['account_code' => '41', 'name' => 'إيرادات النشاط', 'name_en' => 'Operating Revenue', 'parent_code' => '4', 'account_type' => Account::TypeRevenue, 'statement_type' => Account::StatementIncomeStatement, 'normal_balance' => Account::BalanceCredit],
            ['account_code' => '411', 'name' => 'إيرادات المبيعات', 'name_en' => 'Sales Revenue', 'parent_code' => '41', 'classification_code' => 'sales_revenue', 'account_type' => Account::TypeRevenue, 'statement_type' => Account::StatementIncomeStatement, 'normal_balance' => Account::BalanceCredit],
            ['account_code' => '412', 'name' => 'إيرادات الخدمات', 'name_en' => 'Service Revenue', 'parent_code' => '41', 'classification_code' => 'service_revenue', 'account_type' => Account::TypeRevenue, 'statement_type' => Account::StatementIncomeStatement, 'normal_balance' => Account::BalanceCredit],
            ['account_code' => '42', 'name' => 'مردودات وخصومات المبيعات', 'name_en' => 'Sales Returns and Discounts', 'parent_code' => '4', 'account_type' => Account::TypeRevenue, 'statement_type' => Account::StatementIncomeStatement, 'normal_balance' => Account::BalanceDebit],
            ['account_code' => '421', 'name' => 'مردودات المبيعات', 'name_en' => 'Sales Returns', 'parent_code' => '42', 'classification_code' => 'sales_returns', 'account_type' => Account::TypeRevenue, 'statement_type' => Account::StatementIncomeStatement, 'normal_balance' => Account::BalanceDebit],
            ['account_code' => '422', 'name' => 'خصومات المبيعات المسموح بها', 'name_en' => 'Sales Discounts Allowed', 'parent_code' => '42', 'classification_code' => 'sales_discounts', 'account_type' => Account::TypeRevenue, 'statement_type' => Account::StatementIncomeStatement, 'normal_balance' => Account::BalanceDebit],
            ['account_code' => '43', 'name' => 'إيرادات أخرى', 'name_en' => 'Other Revenue', 'parent_code' => '4', 'account_type' => Account::TypeRevenue, 'statement_type' => Account::StatementIncomeStatement, 'normal_balance' => Account::BalanceCredit],
            ['account_code' => '431', 'name' => 'إيرادات استثمارية', 'name_en' => 'Investment Revenue', 'parent_code' => '43', 'account_type' => Account::TypeRevenue, 'statement_type' => Account::StatementIncomeStatement, 'normal_balance' => Account::BalanceCredit],
            ['account_code' => '432', 'name' => 'إيرادات متنوعة', 'name_en' => 'Miscellaneous Revenue', 'parent_code' => '43', 'account_type' => Account::TypeRevenue, 'statement_type' => Account::StatementIncomeStatement, 'normal_balance' => Account::BalanceCredit],

            ['account_code' => '5', 'name' => 'المصروفات', 'name_en' => 'Expenses', 'account_type' => Account::TypeExpense, 'statement_type' => Account::StatementIncomeStatement, 'normal_balance' => Account::BalanceDebit],
            ['account_code' => '51', 'name' => 'تكلفة النشاط', 'name_en' => 'Activity Cost', 'parent_code' => '5', 'account_type' => Account::TypeExpense, 'statement_type' => Account::StatementIncomeStatement, 'normal_balance' => Account::BalanceDebit],
            ['account_code' => '511', 'name' => 'تكلفة المبيعات', 'name_en' => 'Cost of Sales', 'parent_code' => '51', 'classification_code' => 'cost_of_goods_sold', 'account_type' => Account::TypeExpense, 'statement_type' => Account::StatementIncomeStatement, 'normal_balance' => Account::BalanceDebit],
            ['account_code' => '512', 'name' => 'تكلفة الخدمات', 'name_en' => 'Cost of Services', 'parent_code' => '51', 'classification_code' => 'cost_of_goods_sold', 'account_type' => Account::TypeExpense, 'statement_type' => Account::StatementIncomeStatement, 'normal_balance' => Account::BalanceDebit],
            ['account_code' => '52', 'name' => 'المصروفات التشغيلية', 'name_en' => 'Operating Expenses', 'parent_code' => '5', 'account_type' => Account::TypeExpense, 'statement_type' => Account::StatementIncomeStatement, 'normal_balance' => Account::BalanceDebit],
            ['account_code' => '521', 'name' => 'الرواتب والأجور', 'name_en' => 'Salaries and Wages', 'parent_code' => '52', 'classification_code' => 'salary_expense', 'account_type' => Account::TypeExpense, 'statement_type' => Account::StatementIncomeStatement, 'normal_balance' => Account::BalanceDebit],
            ['account_code' => '522', 'name' => 'الإيجارات', 'name_en' => 'Rent', 'parent_code' => '52', 'classification_code' => 'rent_expense', 'account_type' => Account::TypeExpense, 'statement_type' => Account::StatementIncomeStatement, 'normal_balance' => Account::BalanceDebit],
            ['account_code' => '523', 'name' => 'الكهرباء والمياه والمرافق', 'name_en' => 'Electricity, Water, and Utilities', 'parent_code' => '52', 'classification_code' => 'utilities_expense', 'account_type' => Account::TypeExpense, 'statement_type' => Account::StatementIncomeStatement, 'normal_balance' => Account::BalanceDebit],
            ['account_code' => '524', 'name' => 'الصيانة', 'name_en' => 'Maintenance', 'parent_code' => '52', 'account_type' => Account::TypeExpense, 'statement_type' => Account::StatementIncomeStatement, 'normal_balance' => Account::BalanceDebit],
            ['account_code' => '525', 'name' => 'التسويق والإعلان', 'name_en' => 'Marketing and Advertising', 'parent_code' => '52', 'account_type' => Account::TypeExpense, 'statement_type' => Account::StatementIncomeStatement, 'normal_balance' => Account::BalanceDebit],
            ['account_code' => '526', 'name' => 'النقل والشحن', 'name_en' => 'Transportation and Freight', 'parent_code' => '52', 'account_type' => Account::TypeExpense, 'statement_type' => Account::StatementIncomeStatement, 'normal_balance' => Account::BalanceDebit],
            ['account_code' => '527', 'name' => 'الاتصالات', 'name_en' => 'Communications', 'parent_code' => '52', 'account_type' => Account::TypeExpense, 'statement_type' => Account::StatementIncomeStatement, 'normal_balance' => Account::BalanceDebit],
            ['account_code' => '528', 'name' => 'مصروفات إدارية', 'name_en' => 'Administrative Expenses', 'parent_code' => '52', 'account_type' => Account::TypeExpense, 'statement_type' => Account::StatementIncomeStatement, 'normal_balance' => Account::BalanceDebit],
            ['account_code' => '53', 'name' => 'الإهلاك والإطفاء', 'name_en' => 'Depreciation and Amortization', 'parent_code' => '5', 'account_type' => Account::TypeExpense, 'statement_type' => Account::StatementIncomeStatement, 'normal_balance' => Account::BalanceDebit],
            ['account_code' => '531', 'name' => 'مصروف الإهلاك', 'name_en' => 'Depreciation Expense', 'parent_code' => '53', 'classification_code' => 'depreciation_expense', 'account_type' => Account::TypeExpense, 'statement_type' => Account::StatementIncomeStatement, 'normal_balance' => Account::BalanceDebit],
            ['account_code' => '532', 'name' => 'مصروف الإطفاء', 'name_en' => 'Amortization Expense', 'parent_code' => '53', 'account_type' => Account::TypeExpense, 'statement_type' => Account::StatementIncomeStatement, 'normal_balance' => Account::BalanceDebit],
            ['account_code' => '54', 'name' => 'المصروفات التمويلية', 'name_en' => 'Finance Expenses', 'parent_code' => '5', 'account_type' => Account::TypeExpense, 'statement_type' => Account::StatementIncomeStatement, 'normal_balance' => Account::BalanceDebit],
            ['account_code' => '541', 'name' => 'فوائد ومصاريف بنكية', 'name_en' => 'Interest and Bank Charges', 'parent_code' => '54', 'account_type' => Account::TypeExpense, 'statement_type' => Account::StatementIncomeStatement, 'normal_balance' => Account::BalanceDebit],
            ['account_code' => '55', 'name' => 'مصروفات أخرى', 'name_en' => 'Other Expenses', 'parent_code' => '5', 'classification_code' => 'other_expense', 'account_type' => Account::TypeExpense, 'statement_type' => Account::StatementIncomeStatement, 'normal_balance' => Account::BalanceDebit],
            ['account_code' => '551', 'name' => 'مصروفات متنوعة', 'name_en' => 'Miscellaneous Expenses', 'parent_code' => '55', 'classification_code' => 'other_expense', 'account_type' => Account::TypeExpense, 'statement_type' => Account::StatementIncomeStatement, 'normal_balance' => Account::BalanceDebit],
        ];
    }
}
