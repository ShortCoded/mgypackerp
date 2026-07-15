<?php

namespace Modules\Purchases\Services;

use DomainException;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Services\BusinessPartnerAccountService;
use Modules\Core\Services\CrudAuditService;
use Modules\Purchases\Models\Supplier;

class SupplierAccountingSyncService
{
    public function __construct(
        private readonly CrudAuditService $audit,
        private readonly BusinessPartnerAccountService $accounts,
    ) {}

    public function softDeleteLinkedAccountForSupplier(Supplier $supplier): void
    {
        $account = $this->linkedAccount($supplier);

        if (! $account instanceof Account || $account->trashed() || ! $this->accounts->isManagedLinkedAccount(BusinessPartnerAccountService::Supplier, $account)) {
            return;
        }

        if ($this->accounts->hasFinancialMovements($account)) {
            throw new DomainException(__('suppliers.messages.delete_blocked_transactions'));
        }

        $this->assertAccountCanBeSoftDeleted($account);
        $this->audit->softDelete($account);
    }

    public function restoreLinkedAccountForSupplier(Supplier $supplier): void
    {
        $account = $this->linkedAccount($supplier);

        if (! $account instanceof Account) {
            throw new DomainException(__('suppliers.messages.linked_account_missing'));
        }

        if (! $this->accounts->isManagedLinkedAccount(BusinessPartnerAccountService::Supplier, $account) || ! $account->trashed()) {
            return;
        }

        $this->assertAccountCanBeRestored($account);
        $this->audit->restore($account, auth()->id());
    }

    public function softDeleteSupplierForAccount(Account $account): void
    {
        $supplier = $this->owningSupplier($account);

        if (! $supplier instanceof Supplier || $supplier->trashed() || ! $this->accounts->isManagedLinkedAccount(BusinessPartnerAccountService::Supplier, $account)) {
            return;
        }

        $this->audit->softDelete($supplier);
    }

    public function restoreSupplierForAccount(Account $account): void
    {
        $supplier = $this->owningSupplier($account);

        if (! $supplier instanceof Supplier || ! $supplier->trashed() || ! $this->accounts->isManagedLinkedAccount(BusinessPartnerAccountService::Supplier, $account)) {
            return;
        }

        $this->assertSupplierCanBeRestored($supplier);
        $this->audit->restore($supplier, auth()->id());
    }

    public function syncSupplierForAccountUpdate(Account $account): void
    {
        $supplier = $this->owningSupplier($account);

        if (! $supplier instanceof Supplier || $supplier->trashed() || ! $this->accounts->isManagedLinkedAccount(BusinessPartnerAccountService::Supplier, $account)) {
            return;
        }

        $values = [];
        $accountName = trim((string) $account->name);

        if ($accountName !== '' && trim((string) $supplier->name) !== $accountName) {
            $values['name'] = $accountName;
        }

        if (in_array($account->status, ['active', 'inactive'], true) && $supplier->status !== $account->status) {
            $values['status'] = $account->status;
        }

        $parent = $this->accounts->linkedAccountGroup(BusinessPartnerAccountService::Supplier, $account);
        $groupId = $parent?->getKey();

        if ((string) ($supplier->account_group_id ?? '') !== (string) ($groupId ?? '')) {
            $values['account_group_id'] = $groupId;
        }

        if ($values !== []) {
            $this->audit->saveUpdate($supplier, $values);
        }
    }

    private function linkedAccount(Supplier $supplier): ?Account
    {
        if (! $supplier->account_id) {
            return null;
        }

        return Account::withTrashed()->whereKey($supplier->account_id)->first();
    }

    private function owningSupplier(Account $account): ?Supplier
    {
        return Supplier::withTrashed()
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

    private function assertSupplierCanBeRestored(Supplier $supplier): void
    {
        $conflicts = fn (string $column, mixed $value): bool => Supplier::query()
            ->where('company_id', $supplier->company_id)
            ->whereKeyNot($supplier->getKey())
            ->where($column, $value)
            ->exists();

        if ($conflicts('account_id', $supplier->account_id)
            || $conflicts('doc_num', $supplier->doc_num)
            || $conflicts('doc_number', $supplier->doc_number)) {
            throw new DomainException(__('suppliers.messages.restore_conflict'));
        }
    }
}
