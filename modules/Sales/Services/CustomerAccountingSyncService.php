<?php

namespace Modules\Sales\Services;

use DomainException;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Services\BusinessPartnerAccountService;
use Modules\Core\Services\CrudAuditService;
use Modules\Sales\Models\Customer;

class CustomerAccountingSyncService
{
    public function __construct(
        private readonly CrudAuditService $audit,
        private readonly BusinessPartnerAccountService $accounts,
    ) {}

    public function softDeleteLinkedAccountForCustomer(Customer $customer): void
    {
        $account = $this->linkedAccount($customer);

        if (! $account instanceof Account || $account->trashed() || ! $this->accounts->isManagedLinkedAccount(BusinessPartnerAccountService::Customer, $account)) {
            return;
        }

        if ($this->accounts->hasFinancialMovements($account)) {
            throw new DomainException(__('customers.messages.delete_blocked_transactions'));
        }

        $this->assertAccountCanBeSoftDeleted($account);
        $this->audit->softDelete($account);
    }

    public function restoreLinkedAccountForCustomer(Customer $customer): void
    {
        $account = $this->linkedAccount($customer);

        if (! $account instanceof Account) {
            throw new DomainException(__('customers.messages.linked_account_missing'));
        }

        if (! $this->accounts->isManagedLinkedAccount(BusinessPartnerAccountService::Customer, $account) || ! $account->trashed()) {
            return;
        }

        $this->assertAccountCanBeRestored($account);
        $this->audit->restore($account, auth()->id());
    }

    public function softDeleteCustomerForAccount(Account $account): void
    {
        $customer = $this->owningCustomer($account);

        if (! $customer instanceof Customer || $customer->trashed() || ! $this->accounts->isManagedLinkedAccount(BusinessPartnerAccountService::Customer, $account)) {
            return;
        }

        $this->audit->softDelete($customer);
    }

    public function restoreCustomerForAccount(Account $account): void
    {
        $customer = $this->owningCustomer($account);

        if (! $customer instanceof Customer || ! $customer->trashed() || ! $this->accounts->isManagedLinkedAccount(BusinessPartnerAccountService::Customer, $account)) {
            return;
        }

        $this->assertCustomerCanBeRestored($customer);
        $this->audit->restore($customer, auth()->id());
    }

    public function syncCustomerForAccountUpdate(Account $account): void
    {
        $customer = $this->owningCustomer($account);

        if (! $customer instanceof Customer || $customer->trashed() || ! $this->accounts->isManagedLinkedAccount(BusinessPartnerAccountService::Customer, $account)) {
            return;
        }

        $values = [];
        $accountName = trim((string) $account->name);

        if ($accountName !== '' && trim((string) $customer->name) !== $accountName) {
            $values['name'] = $accountName;
        }

        if (in_array($account->status, ['active', 'inactive'], true) && $customer->status !== $account->status) {
            $values['status'] = $account->status;
        }

        $parent = $this->accounts->linkedAccountGroup(BusinessPartnerAccountService::Customer, $account);
        $groupId = $parent?->getKey();

        if ((string) ($customer->account_group_id ?? '') !== (string) ($groupId ?? '')) {
            $values['account_group_id'] = $groupId;
        }

        if ($values !== []) {
            $this->audit->saveUpdate($customer, $values);
        }
    }

    private function linkedAccount(Customer $customer): ?Account
    {
        if (! $customer->account_id) {
            return null;
        }

        return Account::withTrashed()->whereKey($customer->account_id)->first();
    }

    private function owningCustomer(Account $account): ?Customer
    {
        return Customer::withTrashed()
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

    private function assertCustomerCanBeRestored(Customer $customer): void
    {
        $conflicts = fn (string $column, mixed $value): bool => Customer::query()
            ->where('company_id', $customer->company_id)
            ->whereKeyNot($customer->getKey())
            ->where($column, $value)
            ->exists();

        if ($conflicts('account_id', $customer->account_id)
            || $conflicts('doc_num', $customer->doc_num)
            || $conflicts('doc_number', $customer->doc_number)) {
            throw new DomainException(__('customers.messages.restore_conflict'));
        }
    }
}
