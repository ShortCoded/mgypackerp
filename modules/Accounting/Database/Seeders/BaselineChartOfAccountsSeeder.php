<?php

namespace Modules\Accounting\Database\Seeders;

use Database\Seeders\DefaultOperatingContextSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\AccountClassification;
use Modules\Core\Models\Company;
use Modules\Core\Services\DocumentNumberService;

class BaselineChartOfAccountsSeeder extends Seeder
{
    public function run(): void
    {
        if (! Company::query()->whereNull('deleted_at')->exists()) {
            $this->call(DefaultOperatingContextSeeder::class);
        }

        DB::transaction(function (): void {
            Company::query()
                ->whereNull('deleted_at')
                ->orderBy('id')
                ->each(function (Company $company): void {
                    $this->seedForCompany($company);
                });
        });
    }

    private function seedForCompany(Company $company): void
    {
        $expensesClassificationId = AccountClassification::query()
            ->where('code', AccountClassification::Expenses)
            ->value('id');

        foreach ($this->rootAccounts() as $data) {
            $account = Account::withTrashed()
                ->where('company_id', $company->getKey())
                ->where('account_code', $data['account_code'])
                ->orderByRaw('CASE WHEN deleted_at IS NULL THEN 0 ELSE 1 END')
                ->first();

            if (! $account instanceof Account) {
                $account = new Account(app(DocumentNumberService::class)->nextForCompany('accounts', Account::class, $company->getKey()));
            }

            if ($account->trashed()) {
                $account->restore();
            }

            $account->forceFill([
                'company_id' => $company->getKey(),
                'account_code' => $data['account_code'],
                'name' => trim((string) $account->name) !== '' ? $account->name : $data['name'],
                'name_en' => trim((string) $account->name_en) !== '' ? $account->name_en : $data['name_en'],
                'parent_id' => null,
                'level' => 1,
                'account_classification_id' => $data['account_code'] === '5' ? $expensesClassificationId : null,
                'account_type' => $data['account_type'],
                'statement_type' => $data['statement_type'],
                'normal_balance' => $data['normal_balance'],
                'is_group' => true,
                'is_postable' => false,
                'is_system' => true,
                'status' => 'active',
            ])->save();

            $this->ensureDocumentNumber($account, $company);
        }
    }

    private function ensureDocumentNumber(Account $account, Company $company): void
    {
        if ($account->doc_number !== null && $account->doc_num !== null) {
            return;
        }

        $account->forceFill(app(DocumentNumberService::class)->nextForCompany('accounts', Account::class, $company->getKey()))->save();
    }

    /**
     * @return list<array{account_code: string, name: string, name_en: string, account_type: string, statement_type: string, normal_balance: string}>
     */
    private function rootAccounts(): array
    {
        return [
            ['account_code' => '1', 'name' => 'الأصول', 'name_en' => 'Assets', 'account_type' => Account::TypeAsset, 'statement_type' => Account::StatementFinancialPosition, 'normal_balance' => Account::BalanceDebit],
            ['account_code' => '2', 'name' => 'الالتزامات', 'name_en' => 'Liabilities', 'account_type' => Account::TypeLiability, 'statement_type' => Account::StatementFinancialPosition, 'normal_balance' => Account::BalanceCredit],
            ['account_code' => '3', 'name' => 'حقوق الملكية', 'name_en' => 'Equity', 'account_type' => Account::TypeEquity, 'statement_type' => Account::StatementFinancialPosition, 'normal_balance' => Account::BalanceCredit],
            ['account_code' => '4', 'name' => 'الإيرادات', 'name_en' => 'Revenue', 'account_type' => Account::TypeRevenue, 'statement_type' => Account::StatementIncomeStatement, 'normal_balance' => Account::BalanceCredit],
            ['account_code' => '5', 'name' => 'المصروفات', 'name_en' => 'Expenses', 'account_type' => Account::TypeExpense, 'statement_type' => Account::StatementIncomeStatement, 'normal_balance' => Account::BalanceDebit],
        ];
    }
}
