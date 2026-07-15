<?php

namespace Modules\Finance\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\AccountClassification;
use Modules\Accounting\Services\AccountService;
use Modules\Core\Services\OperatingCompanyContextService;

class CashboxChartAccountService
{
    public function __construct(
        private readonly AccountService $accounts,
        private readonly OperatingCompanyContextService $companies,
    ) {}

    public function mainCashboxesAccount(): Account
    {
        $account = Account::query()
            ->with('classification')
            ->forCompany($this->companies->requireCompanyId())
            ->whereHas('classification', fn ($query) => $query->where('code', 'cash'))
            ->where('account_code', '1111')
            ->where('status', 'active')
            ->first();

        if (! $account instanceof Account) {
            $account = $this->createMainCashboxesAccount();
        }

        if (! $account instanceof Account) {
            throw new DomainException(__('cashboxes.messages.main_cashbox_parent_missing'));
        }

        if (! $account->is_group || $account->is_postable) {
            $account->forceFill([
                'is_group' => true,
                'is_postable' => false,
            ])->save();
        }

        return $account->refresh();
    }

    /**
     * @return array{account: Account, changed: bool}
     */
    public function createOrUpdateLinkedAccount(?Account $currentAccount, Account $parent, array $data): array
    {
        if (! $this->isAllowedLinkedAccountParent($parent)) {
            throw new DomainException(__('cashboxes.messages.account_outside_cashboxes'));
        }

        $name = $this->linkedAccountName($data);

        if (! $currentAccount instanceof Account || ! $this->isManagedLinkedAccount($currentAccount)) {
            return [
                'account' => $this->createLinkedAccount($parent, $name, $data['notes'] ?? null),
                'changed' => true,
            ];
        }

        $values = [
            'account_code' => (int) $currentAccount->parent_id === (int) $parent->getKey()
                ? $currentAccount->account_code
                : $this->accounts->nextChildAccountCode($parent),
            'name' => $name,
            'name_en' => $currentAccount->name_en,
            'parent_doc_num' => $parent->doc_num,
            'classification_code' => 'cash',
            'account_type' => $parent->account_type,
            'statement_type' => $parent->statement_type,
            'normal_balance' => $parent->normal_balance,
            'is_group' => false,
            'is_postable' => true,
            'status' => 'active',
            'notes' => $currentAccount->notes,
        ];

        $result = $this->accounts->update($currentAccount, $values);

        return [
            'account' => $result['record'],
            'changed' => (bool) $result['changed'],
        ];
    }

    public function linkedAccountName(array $data): string
    {
        return trim((string) $data['name']);
    }

    public function isSelectableCashboxParent(Account $account): bool
    {
        $mainCashboxesAccount = $this->mainCashboxesAccount();

        return $account->status === 'active'
            && $account->is_group
            && ! $account->is_postable
            && $this->isCashAccount($account)
            && (int) $account->getKey() !== (int) $mainCashboxesAccount->getKey()
            && (int) $account->parent_id === (int) $mainCashboxesAccount->getKey();
    }

    public function createCashboxGroup(string $name, ?string $notes = null): Account
    {
        return $this->accounts->createChildFromParent($this->mainCashboxesAccount(), [
            'name' => $name,
            'name_en' => null,
            'classification_code' => 'cash',
            'is_group' => true,
            'is_postable' => false,
            'status' => 'active',
            'notes' => $notes,
        ]);
    }

    public function isManagedLinkedAccount(Account $account): bool
    {
        return ! $account->is_group
            && $account->is_postable
            && $this->isCashAccount($account)
            && $this->isDescendantOf($account, $this->mainCashboxesAccount());
    }

    public function linkedAccountParent(?Account $account): ?Account
    {
        if (! $account instanceof Account || ! $account->parent_id) {
            return null;
        }

        $parent = Account::query()
            ->with('classification')
            ->where('company_id', $account->company_id)
            ->find($account->parent_id);

        if ($parent instanceof Account && (int) $parent->getKey() === (int) $this->mainCashboxesAccount()->getKey()) {
            return null;
        }

        return $parent instanceof Account && $this->isSelectableCashboxParent($parent) ? $parent : null;
    }

    private function createLinkedAccount(Account $parent, string $name, ?string $notes = null): Account
    {
        return $this->accounts->createChildFromParent($parent, [
            'name' => $name,
            'name_en' => null,
            'classification_code' => 'cash',
            'is_group' => false,
            'is_postable' => true,
            'status' => 'active',
            'notes' => $notes,
        ]);
    }

    private function isCashAccount(Account $account): bool
    {
        if ($account->relationLoaded('classification') && $account->classification?->code === 'cash') {
            return true;
        }

        return Account::withTrashed()
            ->where('company_id', $account->company_id)
            ->whereKey($account->getKey())
            ->whereHas('classification', fn ($query) => $query->where('code', 'cash'))
            ->exists();
    }

    private function isAllowedLinkedAccountParent(Account $parent): bool
    {
        $mainCashboxesAccount = $this->mainCashboxesAccount();

        return (int) $parent->getKey() === (int) $mainCashboxesAccount->getKey()
            || $this->isSelectableCashboxParent($parent);
    }

    private function isDescendantOf(Account $account, Account $ancestor): bool
    {
        $parentId = $account->parent_id;

        while ($parentId !== null) {
            if ((int) $parentId === (int) $ancestor->getKey()) {
                return true;
            }

            $parentId = Account::query()->where('company_id', $account->company_id)->whereKey($parentId)->value('parent_id');
        }

        return false;
    }

    private function createMainCashboxesAccount(): ?Account
    {
        return DB::transaction(function (): ?Account {
            $parent = Account::query()
                ->forCompany($this->companies->requireCompanyId())
                ->where('account_code', '111')
                ->where('status', 'active')
                ->first();
            $classification = AccountClassification::query()
                ->where('code', 'cash')
                ->where('status', 'active')
                ->first();

            if (! $parent instanceof Account || ! $classification instanceof AccountClassification) {
                return null;
            }

            if (Account::query()->forCompany($this->companies->requireCompanyId())->where('account_code', '1111')->exists()) {
                return null;
            }

            return $this->accounts->create([
                'account_code' => '1111',
                'name' => 'الخزائن',
                'name_en' => 'Cashboxes',
                'parent_doc_num' => $parent->doc_num,
                'classification_code' => 'cash',
                'account_type' => $parent->account_type,
                'statement_type' => $parent->statement_type,
                'normal_balance' => $parent->normal_balance,
                'is_group' => true,
                'is_postable' => false,
                'status' => 'active',
                'notes' => null,
            ]);
        });
    }
}
