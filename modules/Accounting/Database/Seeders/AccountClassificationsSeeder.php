<?php

namespace Modules\Accounting\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\AccountClassification;
use Modules\Core\Services\DocumentNumberService;

class AccountClassificationsSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            foreach ($this->classifications() as $data) {
                $classification = AccountClassification::withTrashed()->where('code', $data['code'])->first();

                if ($classification instanceof AccountClassification) {
                    if ($classification->trashed()) {
                        $classification->restore();
                    }

                    $classification->forceFill($data + ['is_system' => true, 'status' => 'active'])->save();
                    $this->ensureDocumentNumber($classification);

                    continue;
                }

                AccountClassification::query()->create([
                    ...app(DocumentNumberService::class)->next('account_classifications', AccountClassification::class),
                    ...$data,
                    'is_system' => true,
                    'status' => 'active',
                ]);
            }
        });
    }

    private function ensureDocumentNumber(AccountClassification $classification): void
    {
        if ($classification->doc_number && $classification->doc_num) {
            return;
        }

        $classification->forceFill(app(DocumentNumberService::class)->next('account_classifications', AccountClassification::class))->save();
    }

    /**
     * @return list<array<string, string>>
     */
    private function classifications(): array
    {
        return [
            ['code' => 'cash', 'name' => 'نقدية', 'name_en' => 'Cash', 'account_type' => 'asset', 'statement_type' => 'financial_position', 'normal_balance' => 'debit'],
            ['code' => 'bank', 'name' => 'بنك', 'name_en' => 'Bank', 'account_type' => 'asset', 'statement_type' => 'financial_position', 'normal_balance' => 'debit'],
            ['code' => 'accounts_receivable', 'name' => 'عملاء / ذمم مدينة', 'name_en' => 'Accounts Receivable', 'account_type' => 'asset', 'statement_type' => 'financial_position', 'normal_balance' => 'debit'],
            ['code' => 'inventory', 'name' => 'مخزون', 'name_en' => 'Inventory', 'account_type' => 'asset', 'statement_type' => 'financial_position', 'normal_balance' => 'debit'],
            ['code' => 'fixed_assets', 'name' => 'أصول ثابتة', 'name_en' => 'Fixed Assets', 'account_type' => 'asset', 'statement_type' => 'financial_position', 'normal_balance' => 'debit'],
            ['code' => 'accumulated_depreciation', 'name' => 'مجمع الإهلاك', 'name_en' => 'Accumulated Depreciation', 'account_type' => 'asset', 'statement_type' => 'financial_position', 'normal_balance' => 'credit'],
            ['code' => 'prepaid_expenses', 'name' => 'مصروفات مدفوعة مقدمًا', 'name_en' => 'Prepaid Expenses', 'account_type' => 'asset', 'statement_type' => 'financial_position', 'normal_balance' => 'debit'],
            ['code' => 'accounts_payable', 'name' => 'موردين / ذمم دائنة', 'name_en' => 'Accounts Payable', 'account_type' => 'liability', 'statement_type' => 'financial_position', 'normal_balance' => 'credit'],
            ['code' => 'tax_payable', 'name' => 'ضرائب مستحقة', 'name_en' => 'Tax Payable', 'account_type' => 'liability', 'statement_type' => 'financial_position', 'normal_balance' => 'credit'],
            ['code' => 'loans_payable', 'name' => 'قروض', 'name_en' => 'Loans Payable', 'account_type' => 'liability', 'statement_type' => 'financial_position', 'normal_balance' => 'credit'],
            ['code' => 'accrued_expenses', 'name' => 'مصروفات مستحقة', 'name_en' => 'Accrued Expenses', 'account_type' => 'liability', 'statement_type' => 'financial_position', 'normal_balance' => 'credit'],
            ['code' => 'capital', 'name' => 'رأس المال', 'name_en' => 'Capital', 'account_type' => 'equity', 'statement_type' => 'financial_position', 'normal_balance' => 'credit'],
            ['code' => 'retained_earnings', 'name' => 'أرباح محتجزة', 'name_en' => 'Retained Earnings', 'account_type' => 'equity', 'statement_type' => 'financial_position', 'normal_balance' => 'credit'],
            ['code' => 'sales_revenue', 'name' => 'إيرادات مبيعات', 'name_en' => 'Sales Revenue', 'account_type' => 'revenue', 'statement_type' => 'income_statement', 'normal_balance' => 'credit'],
            ['code' => 'service_revenue', 'name' => 'إيرادات خدمات', 'name_en' => 'Service Revenue', 'account_type' => 'revenue', 'statement_type' => 'income_statement', 'normal_balance' => 'credit'],
            ['code' => 'sales_returns', 'name' => 'مردودات مبيعات', 'name_en' => 'Sales Returns', 'account_type' => 'revenue', 'statement_type' => 'income_statement', 'normal_balance' => 'debit'],
            ['code' => 'sales_discounts', 'name' => 'خصومات مبيعات', 'name_en' => 'Sales Discounts', 'account_type' => 'revenue', 'statement_type' => 'income_statement', 'normal_balance' => 'debit'],
            ['code' => AccountClassification::Expenses, 'name' => 'مصروفات', 'name_en' => 'Expenses', 'account_type' => 'expense', 'statement_type' => 'income_statement', 'normal_balance' => 'debit'],
            ['code' => 'cost_of_goods_sold', 'name' => 'تكلفة المبيعات', 'name_en' => 'Cost of Goods Sold', 'account_type' => 'expense', 'statement_type' => 'income_statement', 'normal_balance' => 'debit'],
            ['code' => 'salary_expense', 'name' => 'مصروف رواتب', 'name_en' => 'Salary Expense', 'account_type' => 'expense', 'statement_type' => 'income_statement', 'normal_balance' => 'debit'],
            ['code' => 'rent_expense', 'name' => 'مصروف إيجار', 'name_en' => 'Rent Expense', 'account_type' => 'expense', 'statement_type' => 'income_statement', 'normal_balance' => 'debit'],
            ['code' => 'utilities_expense', 'name' => 'مصروف مرافق', 'name_en' => 'Utilities Expense', 'account_type' => 'expense', 'statement_type' => 'income_statement', 'normal_balance' => 'debit'],
            ['code' => 'depreciation_expense', 'name' => 'مصروف إهلاك', 'name_en' => 'Depreciation Expense', 'account_type' => 'expense', 'statement_type' => 'income_statement', 'normal_balance' => 'debit'],
            ['code' => 'other_expense', 'name' => 'مصروفات أخرى', 'name_en' => 'Other Expense', 'account_type' => 'expense', 'statement_type' => 'income_statement', 'normal_balance' => 'debit'],
        ];
    }
}
