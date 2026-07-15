<?php

namespace Modules\Finance\Services;

use DomainException;
use Modules\Accounting\Models\Account;
use Modules\Core\Services\CrudAuditService;
use Modules\Finance\Models\BankAccount;

class BankAccountAccountingSyncService
{
    private bool $syncingFromBankAccount = false;

    private bool $syncingFromAccount = false;

    public function __construct(private readonly CrudAuditService $audit) {}

    public function softDeleteLinkedAccountForBankAccount(BankAccount $bankAccount): void
    {
        if ($this->syncingFromAccount) {
            return;
        }

        $account = $this->linkedAccount($bankAccount);

        if (! $account instanceof Account || $account->trashed()) {
            return;
        }

        $this->assertAccountCanBeSoftDeleted($account);

        $this->syncingFromBankAccount = true;

        try {
            $this->audit->softDelete($account);
        } finally {
            $this->syncingFromBankAccount = false;
        }
    }

    public function restoreLinkedAccountForBankAccount(BankAccount $bankAccount): void
    {
        if ($this->syncingFromAccount) {
            return;
        }

        $account = $this->linkedAccount($bankAccount);

        if (! $account instanceof Account) {
            throw new DomainException(__('bank_accounts.messages.linked_account_missing'));
        }

        if (! $account->trashed()) {
            return;
        }

        $this->assertAccountCanBeRestored($account);

        $this->syncingFromBankAccount = true;

        try {
            $this->audit->restore($account, auth()->id());
        } finally {
            $this->syncingFromBankAccount = false;
        }
    }

    public function softDeleteBankAccountForAccount(Account $account): void
    {
        if ($this->syncingFromBankAccount) {
            return;
        }

        $bankAccount = $this->owningBankAccount($account);

        if (! $bankAccount instanceof BankAccount || $bankAccount->trashed()) {
            return;
        }

        $this->syncingFromAccount = true;

        try {
            $this->audit->softDelete($bankAccount);
        } finally {
            $this->syncingFromAccount = false;
        }
    }

    public function restoreBankAccountForAccount(Account $account): void
    {
        if ($this->syncingFromBankAccount) {
            return;
        }

        $bankAccount = $this->owningBankAccount($account);

        if (! $bankAccount instanceof BankAccount || ! $bankAccount->trashed()) {
            return;
        }

        $this->assertBankAccountCanBeRestored($bankAccount);

        $this->syncingFromAccount = true;

        try {
            $this->audit->restore($bankAccount, auth()->id());
        } finally {
            $this->syncingFromAccount = false;
        }
    }

    private function linkedAccount(BankAccount $bankAccount): ?Account
    {
        if (! $bankAccount->account_id) {
            return null;
        }

        return Account::withTrashed()->whereKey($bankAccount->account_id)->first();
    }

    private function owningBankAccount(Account $account): ?BankAccount
    {
        return BankAccount::withTrashed()
            ->where('account_id', $account->getKey())
            ->first();
    }

    private function assertAccountCanBeSoftDeleted(Account $account): void
    {
        if ($account->is_system && $account->parent_id === null) {
            throw new DomainException(__('accounts.messages.delete_blocked_system'));
        }

        if ($account->children()->exists()) {
            throw new DomainException(__('accounts.messages.delete_blocked_children'));
        }
    }

    private function assertAccountCanBeRestored(Account $account): void
    {
        if (Account::query()->where('company_id', $account->company_id)->where('doc_num', $account->doc_num)->whereKeyNot($account->getKey())->exists()
            || Account::query()->where('company_id', $account->company_id)->where('doc_number', $account->doc_number)->whereKeyNot($account->getKey())->exists()
            || Account::query()->where('company_id', $account->company_id)->where('account_code', $account->account_code)->whereKeyNot($account->getKey())->exists()) {
            throw new DomainException(__('accounts.messages.restore_conflict'));
        }
    }

    private function assertBankAccountCanBeRestored(BankAccount $bankAccount): void
    {
        $conflicts = fn (string $column, mixed $value): bool => BankAccount::query()
            ->where('company_id', $bankAccount->company_id)
            ->whereKeyNot($bankAccount->getKey())
            ->where($column, $value)
            ->exists();

        if ($conflicts('account_id', $bankAccount->account_id)
            || $conflicts('doc_num', $bankAccount->doc_num)
            || $conflicts('doc_number', $bankAccount->doc_number)
            || ($bankAccount->account_number && $conflicts('account_number', $bankAccount->account_number))
            || ($bankAccount->iban && $conflicts('iban', $bankAccount->iban))) {
            throw new DomainException(__('bank_accounts.messages.restore_conflict'));
        }
    }
}
