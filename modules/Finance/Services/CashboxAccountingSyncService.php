<?php

namespace Modules\Finance\Services;

use DomainException;
use Modules\Accounting\Models\Account;
use Modules\Core\Services\CrudAuditService;
use Modules\Finance\Models\Cashbox;

class CashboxAccountingSyncService
{
    private bool $syncingFromCashbox = false;

    private bool $syncingFromAccount = false;

    public function __construct(
        private readonly CrudAuditService $audit,
        private readonly CashboxChartAccountService $chartAccounts,
    ) {}

    public function softDeleteLinkedAccountForCashbox(Cashbox $cashbox): void
    {
        if ($this->syncingFromAccount) {
            return;
        }

        $account = $this->linkedAccount($cashbox);

        if (! $account instanceof Account || $account->trashed() || ! $this->chartAccounts->isManagedLinkedAccount($account)) {
            return;
        }

        $this->assertAccountCanBeSoftDeleted($account);

        $this->syncingFromCashbox = true;

        try {
            $this->audit->softDelete($account);
        } finally {
            $this->syncingFromCashbox = false;
        }
    }

    public function restoreLinkedAccountForCashbox(Cashbox $cashbox): void
    {
        if ($this->syncingFromAccount) {
            return;
        }

        $account = $this->linkedAccount($cashbox);

        if (! $account instanceof Account) {
            throw new DomainException(__('cashboxes.messages.linked_account_missing'));
        }

        if (! $this->chartAccounts->isManagedLinkedAccount($account)) {
            return;
        }

        if (! $account->trashed()) {
            return;
        }

        $this->assertAccountCanBeRestored($account);

        $this->syncingFromCashbox = true;

        try {
            $this->audit->restore($account, auth()->id());
        } finally {
            $this->syncingFromCashbox = false;
        }
    }

    public function softDeleteCashboxForAccount(Account $account): void
    {
        if ($this->syncingFromCashbox) {
            return;
        }

        $cashbox = $this->owningCashbox($account);

        if (! $cashbox instanceof Cashbox || $cashbox->trashed() || ! $this->chartAccounts->isManagedLinkedAccount($account)) {
            return;
        }

        $this->syncingFromAccount = true;

        try {
            $cashbox->currencies()->delete();
            $this->audit->softDelete($cashbox);
        } finally {
            $this->syncingFromAccount = false;
        }
    }

    public function restoreCashboxForAccount(Account $account): void
    {
        if ($this->syncingFromCashbox) {
            return;
        }

        $cashbox = $this->owningCashbox($account);

        if (! $cashbox instanceof Cashbox || ! $cashbox->trashed() || ! $this->chartAccounts->isManagedLinkedAccount($account)) {
            return;
        }

        $this->assertCashboxCanBeRestored($cashbox);

        $this->syncingFromAccount = true;

        try {
            $cashbox->currencies()->withTrashed()->restore();
            $this->audit->restore($cashbox, auth()->id());
        } finally {
            $this->syncingFromAccount = false;
        }
    }

    public function syncCashboxForAccountUpdate(Account $account): void
    {
        if ($this->syncingFromCashbox) {
            return;
        }

        $cashbox = $this->owningCashbox($account);

        if (! $cashbox instanceof Cashbox || $cashbox->trashed() || ! $this->chartAccounts->isManagedLinkedAccount($account)) {
            return;
        }

        $values = [];
        $accountName = trim((string) $account->name);

        if ($accountName !== '' && trim((string) $cashbox->name) !== $accountName) {
            $values['name'] = $accountName;
        }

        if (in_array($account->status, ['active', 'inactive'], true) && $cashbox->status !== $account->status) {
            $values['status'] = $account->status;
        }

        if ($values === []) {
            return;
        }

        $this->syncingFromAccount = true;

        try {
            $this->audit->saveUpdate($cashbox, $values);
        } finally {
            $this->syncingFromAccount = false;
        }
    }

    private function linkedAccount(Cashbox $cashbox): ?Account
    {
        if (! $cashbox->account_id) {
            return null;
        }

        return Account::withTrashed()->whereKey($cashbox->account_id)->first();
    }

    private function owningCashbox(Account $account): ?Cashbox
    {
        return Cashbox::withTrashed()
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

    private function assertCashboxCanBeRestored(Cashbox $cashbox): void
    {
        $conflicts = fn (string $column, mixed $value): bool => Cashbox::query()
            ->where('company_id', $cashbox->company_id)
            ->whereKeyNot($cashbox->getKey())
            ->where($column, $value)
            ->exists();

        if ($conflicts('account_id', $cashbox->account_id)
            || $conflicts('doc_num', $cashbox->doc_num)
            || $conflicts('doc_number', $cashbox->doc_number)) {
            throw new DomainException(__('cashboxes.messages.restore_conflict'));
        }
    }
}
