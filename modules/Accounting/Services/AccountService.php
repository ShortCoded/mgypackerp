<?php

namespace Modules\Accounting\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\AccountClassification;
use Modules\Core\Services\CrudAuditService;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Finance\Services\BankAccountAccountingSyncService;
use Modules\Finance\Services\CashboxAccountingSyncService;
use Modules\FixedAssets\Services\FixedAssetAccountingSyncService;
use Modules\Purchases\Services\SupplierAccountingSyncService;
use Modules\Sales\Services\CustomerAccountingSyncService;

class AccountService
{
    public function __construct(
        private readonly DocumentNumberService $documentNumbers,
        private readonly CrudAuditService $audit,
        private readonly OperatingCompanyContextService $companies,
    ) {}

    public function create(array $data): Account
    {
        return DB::transaction(function () use ($data): Account {
            $companyId = $this->companyIdForOperation();
            $document = array_key_exists('doc_number', $data) && $data['doc_number']
                ? ['doc_number' => (int) $data['doc_number'], 'doc_num' => $this->documentNumbers->format('accounts', (int) $data['doc_number'])]
                : $this->documentNumbers->nextForCompany('accounts', Account::class, $companyId);

            $account = Account::query()->create([
                ...$this->values($data, companyId: $companyId),
                ...$document,
                'created_by' => auth()->id(),
            ]);
            $this->audit->clearCreationUpdateAudit($account);

            return $account->refresh();
        });
    }

    public function createChildFromParent(Account $parent, array $data): Account
    {
        return DB::transaction(function () use ($parent, $data): Account {
            $companyId = $this->companyIdForOperation($parent);
            $parent = Account::query()
                ->with('classification')
                ->forCompany($companyId)
                ->lockForUpdate()
                ->findOrFail($parent->getKey());

            $document = $this->documentNumbers->nextForCompany('accounts', Account::class, $companyId);
            $payload = [
                ...$data,
                'account_code' => $this->nextChildAccountCode($parent),
                'parent_doc_num' => $parent->doc_num,
                'classification_code' => $data['classification_code'] ?? $parent->classification?->code,
                'account_type' => $data['account_type'] ?? $parent->account_type,
                'statement_type' => $data['statement_type'] ?? $parent->statement_type,
                'normal_balance' => $data['normal_balance'] ?? $parent->normal_balance,
                'status' => $data['status'] ?? 'active',
            ];

            $account = Account::query()->create([
                ...$this->values($payload, companyId: $companyId),
                ...$document,
                'created_by' => auth()->id(),
            ]);
            $this->audit->clearCreationUpdateAudit($account);

            return $account->refresh();
        });
    }

    public function update(Account $account, array $data): array
    {
        return DB::transaction(function () use ($account, $data): array {
            $companyId = $this->companyIdForOperation($account);
            $this->assertBelongsToCompany($account, $companyId);

            $values = $this->values($data, $account, $companyId);

            if (array_key_exists('doc_number', $data) && $data['doc_number']) {
                $values['doc_number'] = (int) $data['doc_number'];
                $values['doc_num'] = $this->documentNumbers->format('accounts', (int) $data['doc_number']);
            }

            $changes = [];
            foreach ($values as $field => $value) {
                if ((string) $account->{$field} !== (string) $value) {
                    $changes[$field] = ['old' => $account->{$field}, 'new' => $value];
                }
            }

            if ($changes === []) {
                return ['record' => $account->refresh(), 'changed' => false, 'changes' => []];
            }

            $this->audit->saveUpdate($account, $values);
            $account = $account->refresh();
            app(CashboxAccountingSyncService::class)->syncCashboxForAccountUpdate($account);
            app(CustomerAccountingSyncService::class)->syncCustomerForAccountUpdate($account);
            app(SupplierAccountingSyncService::class)->syncSupplierForAccountUpdate($account);
            app(FixedAssetAccountingSyncService::class)->syncFixedAssetForAccountUpdate($account);

            return ['record' => $account->refresh(), 'changed' => true, 'changes' => $changes];
        });
    }

    public function delete(Account $account): void
    {
        $companyId = $this->companyIdForOperation($account);
        $this->assertBelongsToCompany($account, $companyId);

        if ($account->isProtectedRoot() || ($account->is_system && $account->parent_id === null)) {
            throw new DomainException(__('accounts.messages.delete_blocked_system'));
        }

        if ($account->children()->exists()) {
            throw new DomainException(__('accounts.messages.delete_blocked_children'));
        }

        DB::transaction(function () use ($account): void {
            $this->audit->softDelete($account);
            app(BankAccountAccountingSyncService::class)->softDeleteBankAccountForAccount($account);
            app(CashboxAccountingSyncService::class)->softDeleteCashboxForAccount($account);
            app(CustomerAccountingSyncService::class)->softDeleteCustomerForAccount($account);
            app(SupplierAccountingSyncService::class)->softDeleteSupplierForAccount($account);
            app(FixedAssetAccountingSyncService::class)->softDeleteFixedAssetForAccount($account);
        });
    }

    public function bulkDelete(array $docNums): int
    {
        $deleted = 0;
        $companyId = $this->companyIdForOperation();

        DB::transaction(function () use ($docNums, $companyId, &$deleted): void {
            foreach (Account::query()->forCompany($companyId)->whereIn('doc_num', $docNums)->get() as $account) {
                $this->delete($account);
                $deleted++;
            }
        });

        return $deleted;
    }

    public function restore(Account $account): Account
    {
        return DB::transaction(function () use ($account): Account {
            $companyId = $this->companyIdForOperation($account);
            $account = Account::withTrashed()->whereKey($account->getKey())->lockForUpdate()->firstOrFail();
            $this->assertBelongsToCompany($account, $companyId);

            if (Account::query()->forCompany($companyId)->where('doc_num', $account->doc_num)->whereKeyNot($account->getKey())->exists()
                || Account::query()->forCompany($companyId)->where('doc_number', $account->doc_number)->whereKeyNot($account->getKey())->exists()
                || Account::query()->forCompany($companyId)->where('account_code', $account->account_code)->whereKeyNot($account->getKey())->exists()) {
                throw new DomainException(__('accounts.messages.restore_conflict'));
            }

            $this->audit->restore($account, auth()->id());
            app(BankAccountAccountingSyncService::class)->restoreBankAccountForAccount($account);
            app(CashboxAccountingSyncService::class)->restoreCashboxForAccount($account);
            app(CustomerAccountingSyncService::class)->restoreCustomerForAccount($account);
            app(SupplierAccountingSyncService::class)->restoreSupplierForAccount($account);
            app(FixedAssetAccountingSyncService::class)->restoreFixedAssetForAccount($account);

            return $account->refresh();
        });
    }

    private function values(array $data, ?Account $current = null, ?int $companyId = null): array
    {
        $companyId ??= $this->companyIdForOperation($current);
        $parent = ! empty($data['parent_doc_num'])
            ? Account::query()->forCompany($companyId)->where('doc_num', $data['parent_doc_num'])->first()
            : null;
        $classificationCode = $this->mustUseExpensesClassification($data, $parent, $current)
            ? AccountClassification::Expenses
            : ($data['classification_code'] ?? null);
        $classification = ! empty($classificationCode)
            ? AccountClassification::query()->where('code', $classificationCode)->first()
            : null;
        $isGroup = (bool) ($data['is_group'] ?? false);
        $accountType = $this->derivedAccountType($data, $parent, $classification);
        $statementType = $this->derivedStatementType($accountType, $parent, $classification);
        $normalBalance = $data['normal_balance'] ?? $parent?->normal_balance ?? $classification?->normal_balance ?? $this->defaultNormalBalance($accountType);

        return [
            'company_id' => $companyId,
            'account_code' => $data['account_code'] ?: ($parent ? $this->nextChildAccountCode($parent) : ''),
            'name' => $data['name'],
            'name_en' => ($data['name_en'] ?? null) ?: null,
            'parent_id' => $parent?->getKey(),
            'level' => $parent ? ((int) $parent->level + 1) : 1,
            'account_classification_id' => $classification?->getKey(),
            'account_type' => $accountType,
            'statement_type' => $statementType,
            'normal_balance' => $normalBalance,
            'is_group' => $isGroup,
            'is_postable' => $isGroup ? false : (bool) ($data['is_postable'] ?? true),
            'status' => $data['status'] ?? 'active',
            'notes' => $data['notes'] ?? null,
            'is_system' => (bool) ($current?->is_system ?? false),
        ];
    }

    public function nextChildAccountCode(Account $parent): string
    {
        $prefix = (string) $parent->account_code;
        $suffixes = Account::query()
            ->withTrashed()
            ->where('company_id', $parent->company_id)
            ->where('parent_id', $parent->getKey())
            ->pluck('account_code')
            ->map(function (string $accountCode) use ($prefix): ?int {
                $suffix = substr($accountCode, strlen($prefix));

                return $suffix !== '' && ctype_digit($suffix) ? (int) $suffix : null;
            })
            ->filter(fn (?int $suffix): bool => $suffix !== null)
            ->values();

        return $prefix.(string) (($suffixes->max() ?? 0) + 1);
    }

    public function assertVisible(Account $account): void
    {
        $this->assertBelongsToCompany($account, $this->companyIdForOperation($account));
    }

    private function companyIdForOperation(?Account $anchor = null): int
    {
        $companyId = $this->companies->currentCompanyId();

        if ($companyId !== null) {
            return $companyId;
        }

        if ($anchor instanceof Account && $anchor->company_id !== null) {
            return (int) $anchor->company_id;
        }

        return $this->companies->requireCompanyId();
    }

    private function assertBelongsToCompany(Account $account, int $companyId): void
    {
        if ((int) $account->company_id !== $companyId) {
            abort(404);
        }
    }

    private function derivedAccountType(array $data, ?Account $parent, ?AccountClassification $classification): string
    {
        if ($parent instanceof Account) {
            return $parent->account_type;
        }

        if ($classification instanceof AccountClassification && in_array($classification->account_type, Account::accountTypes(), true)) {
            return $classification->account_type;
        }

        $submitted = (string) ($data['account_type'] ?? '');

        return in_array($submitted, Account::accountTypes(), true) ? $submitted : Account::TypeAsset;
    }

    private function derivedStatementType(string $accountType, ?Account $parent, ?AccountClassification $classification): string
    {
        if ($parent instanceof Account) {
            return $parent->statement_type;
        }

        if ($classification instanceof AccountClassification && in_array($classification->statement_type, Account::statementTypes(), true)) {
            return $classification->statement_type;
        }

        return in_array($accountType, [Account::TypeRevenue, Account::TypeExpense], true)
            ? Account::StatementIncomeStatement
            : Account::StatementFinancialPosition;
    }

    private function defaultNormalBalance(string $accountType): string
    {
        return in_array($accountType, [Account::TypeAsset, Account::TypeExpense], true)
            ? Account::BalanceDebit
            : Account::BalanceCredit;
    }

    private function mustUseExpensesClassification(array $data, ?Account $parent, ?Account $current): bool
    {
        if ($parent instanceof Account) {
            return $this->isInExpensesTree($parent);
        }

        if ($current instanceof Account) {
            return $this->isInExpensesTree($current);
        }

        return (string) ($data['account_code'] ?? '') === '5';
    }

    private function isInExpensesTree(Account $account): bool
    {
        $visitedIds = [];
        $current = $account;

        while ($current instanceof Account && ! in_array((int) $current->getKey(), $visitedIds, true)) {
            $visitedIds[] = (int) $current->getKey();

            if ($current->parent_id === null) {
                return (string) $current->account_code === '5';
            }

            $current = Account::query()
                ->where('company_id', $account->company_id)
                ->find($current->parent_id);
        }

        return false;
    }
}
