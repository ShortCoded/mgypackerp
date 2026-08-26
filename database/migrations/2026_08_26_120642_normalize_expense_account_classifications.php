<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\AccountClassification;
use Modules\Core\Models\Company;
use Modules\Core\Services\DocumentNumberService;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::transaction(function (): void {
            $classification = AccountClassification::withTrashed()
                ->where('code', AccountClassification::Expenses)
                ->first();

            if (! $classification instanceof AccountClassification) {
                $classification = AccountClassification::query()->create([
                    ...app(DocumentNumberService::class)->next('account_classifications', AccountClassification::class),
                    'code' => AccountClassification::Expenses,
                    'name' => 'مصروفات',
                    'name_en' => 'Expenses',
                    'account_type' => Account::TypeExpense,
                    'statement_type' => Account::StatementIncomeStatement,
                    'normal_balance' => Account::BalanceDebit,
                    'is_system' => true,
                    'status' => 'active',
                ]);
            } else {
                if ($classification->trashed()) {
                    $classification->restore();
                }

                $classification->forceFill([
                    'name' => 'مصروفات',
                    'name_en' => 'Expenses',
                    'account_type' => Account::TypeExpense,
                    'statement_type' => Account::StatementIncomeStatement,
                    'normal_balance' => Account::BalanceDebit,
                    'is_system' => true,
                    'status' => 'active',
                ])->save();
            }

            Company::query()->orderBy('id')->pluck('id')->each(function (int $companyId) use ($classification): void {
                $root = Account::query()
                    ->where('company_id', $companyId)
                    ->whereNull('parent_id')
                    ->where('account_code', '5')
                    ->first();

                if (! $root instanceof Account) {
                    return;
                }

                $accountIds = [(int) $root->getKey()];
                $parentIds = $accountIds;

                while ($parentIds !== []) {
                    $childIds = Account::query()
                        ->where('company_id', $companyId)
                        ->whereIn('parent_id', $parentIds)
                        ->pluck('id')
                        ->map(fn (int|string $id): int => (int) $id)
                        ->all();
                    $parentIds = array_values(array_diff($childIds, $accountIds));
                    $accountIds = [...$accountIds, ...$parentIds];
                }

                Account::query()
                    ->where('company_id', $companyId)
                    ->whereIn('id', $accountIds)
                    ->update(['account_classification_id' => $classification->getKey()]);
            });
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // The prior per-expense classifications cannot be reconstructed reliably.
    }
};
