<?php

namespace Modules\Finance\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\AccountClassification;
use Modules\Accounting\Services\AccountService;
use Modules\Core\Models\Currency;
use Modules\Core\Services\OperatingCompanyContextService;

class BankAccountChartAccountService
{
    public function __construct(
        private readonly AccountService $accounts,
        private readonly OperatingCompanyContextService $companies,
    ) {}

    public function mainBanksAccount(): Account
    {
        $companyId = $this->companies->requireCompanyId();
        $account = Account::query()
            ->with('classification')
            ->forCompany($companyId)
            ->whereHas('classification', fn ($query) => $query->where('code', 'bank'))
            ->where('account_code', '1112')
            ->where('status', 'active')
            ->first();

        if (! $account instanceof Account) {
            $account = Account::query()
                ->with('classification')
                ->forCompany($companyId)
                ->whereHas('classification', fn ($query) => $query->where('code', 'bank'))
                ->where('is_group', true)
                ->where('is_postable', false)
                ->where('status', 'active')
                ->orderByRaw('LENGTH(account_code), account_code')
                ->first();
        }

        if (! $account instanceof Account) {
            $account = $this->createMainBanksAccount();
        }

        if (! $account instanceof Account) {
            throw new DomainException(__('bank_accounts.messages.main_bank_parent_missing'));
        }

        return $account;
    }

    public function createBankAccount(string $name, ?string $notes = null): Account
    {
        return $this->accounts->createChildFromParent($this->mainBanksAccount(), [
            'name' => $name,
            'name_en' => null,
            'classification_code' => 'bank',
            'is_group' => true,
            'is_postable' => false,
            'status' => 'active',
            'notes' => $notes,
        ]);
    }

    public function createOrUpdateLinkedAccount(?Account $currentAccount, Account $bankGroup, array $data, Currency $currency): array
    {
        if (! $this->isSelectableBankGroup($bankGroup)) {
            throw new DomainException(__('bank_accounts.messages.child_account_failed'));
        }

        $name = $this->linkedAccountName($data, $currency);

        if (! $currentAccount instanceof Account || ! $this->isManagedLinkedAccount($currentAccount)) {
            return [
                'account' => $this->createLinkedAccount($bankGroup, $name, $data['notes'] ?? null),
                'changed' => true,
            ];
        }

        $values = [
            'account_code' => (int) $currentAccount->parent_id === (int) $bankGroup->getKey()
                ? $currentAccount->account_code
                : $this->accounts->nextChildAccountCode($bankGroup),
            'name' => $name,
            'name_en' => $currentAccount->name_en,
            'parent_doc_num' => $bankGroup->doc_num,
            'classification_code' => 'bank',
            'account_type' => $bankGroup->account_type,
            'statement_type' => $bankGroup->statement_type,
            'normal_balance' => $bankGroup->normal_balance,
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

    public function linkedAccountName(array $data, Currency $currency): string
    {
        return $this->linkedAccountDisplayName((string) $data['account_name'], $currency);
    }

    public function linkedAccountDisplayName(string $accountName, Currency $currency): string
    {
        return trim(implode(' - ', array_filter([
            trim($accountName),
            $this->currencyDisplayCode($currency),
        ])));
    }

    public function isSelectableBankGroup(Account $account): bool
    {
        return $account->status === 'active'
            && $account->is_group
            && ! $account->is_postable
            && $this->isBankAccount($account)
            && $account->getKey() !== $this->mainBanksAccount()->getKey()
            && (int) $account->parent_id === (int) $this->mainBanksAccount()->getKey();
    }

    public function isManagedLinkedAccount(Account $account): bool
    {
        return $account->status === 'active'
            && ! $account->is_group
            && $account->is_postable
            && $this->isBankAccount($account)
            && $this->isDescendantOf($account, $this->mainBanksAccount());
    }

    public function isSelectableBankAccount(Account $account): bool
    {
        return $this->isSelectableBankGroup($account);
    }

    public function linkedAccountBankGroup(?Account $account): ?Account
    {
        if (! $account instanceof Account) {
            return null;
        }

        if ($this->isSelectableBankGroup($account)) {
            return $account;
        }

        if ($this->isManagedLinkedAccount($account) && $account->parent_id) {
            $parent = Account::query()
                ->with('classification')
                ->where('company_id', $account->company_id)
                ->find($account->parent_id);

            return $parent instanceof Account && $this->isSelectableBankGroup($parent) ? $parent : null;
        }

        return null;
    }

    public function createLinkedAccount(Account $bankGroup, string $name, ?string $notes = null): Account
    {
        return $this->accounts->createChildFromParent($bankGroup, [
            'name' => $name,
            'name_en' => null,
            'classification_code' => 'bank',
            'is_group' => false,
            'is_postable' => true,
            'status' => 'active',
            'notes' => $notes,
        ]);
    }

    /**
     * @deprecated Use isSelectableBankGroup() for the Bank Accounts form selector.
     */
    public function isSelectableBankAccountLegacy(Account $account): bool
    {
        return $account->status === 'active'
            && $this->isBankAccount($account)
            && $account->getKey() !== $this->mainBanksAccount()->getKey()
            && $this->isDescendantOf($account, $this->mainBanksAccount());
    }

    public function isDescendantOf(Account $account, Account $ancestor): bool
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

    private function isBankAccount(Account $account): bool
    {
        if ($account->relationLoaded('classification') && $account->classification?->code === 'bank') {
            return true;
        }

        return Account::query()
            ->where('company_id', $account->company_id)
            ->whereKey($account->getKey())
            ->whereHas('classification', fn ($query) => $query->where('code', 'bank'))
            ->exists();
    }

    private function currencyDisplayCode(Currency $currency): string
    {
        $attributes = $currency->getAttributes();
        $symbol = array_key_exists('symbol', $attributes) ? trim((string) $attributes['symbol']) : '';

        return $symbol !== '' ? $symbol : trim((string) $currency->code);
    }

    private function createMainBanksAccount(): ?Account
    {
        return DB::transaction(function (): ?Account {
            $parent = Account::query()
                ->forCompany($this->companies->requireCompanyId())
                ->where('account_code', '111')
                ->where('status', 'active')
                ->first();
            $classification = AccountClassification::query()
                ->where('code', 'bank')
                ->where('status', 'active')
                ->first();

            if (! $parent instanceof Account || ! $classification instanceof AccountClassification) {
                return null;
            }

            if (Account::query()->forCompany($this->companies->requireCompanyId())->where('account_code', '1112')->exists()) {
                return null;
            }

            return $this->accounts->create([
                'account_code' => '1112',
                'name' => 'البنوك',
                'name_en' => 'Banks',
                'parent_doc_num' => $parent->doc_num,
                'classification_code' => 'bank',
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
