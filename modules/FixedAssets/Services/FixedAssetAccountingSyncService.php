<?php

namespace Modules\FixedAssets\Services;

use DomainException;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Services\BusinessPartnerAccountService;
use Modules\Core\Services\CrudAuditService;
use Modules\FixedAssets\Models\FixedAsset;

class FixedAssetAccountingSyncService
{
    public function __construct(
        private readonly CrudAuditService $audit,
        private readonly BusinessPartnerAccountService $accounts,
    ) {}

    public function softDeleteLinkedAccountForFixedAsset(FixedAsset $fixedAsset): void
    {
        $account = $this->linkedAccount($fixedAsset);

        if (! $account instanceof Account || $account->trashed() || ! $this->accounts->isManagedLinkedAccount(BusinessPartnerAccountService::FixedAsset, $account)) {
            return;
        }

        if ($this->accounts->hasFinancialMovements($account)) {
            throw new DomainException(__('fixed_assets.messages.delete_blocked_transactions'));
        }

        $this->assertAccountCanBeSoftDeleted($account);
        $this->audit->softDelete($account);
    }

    public function restoreLinkedAccountForFixedAsset(FixedAsset $fixedAsset): void
    {
        $account = $this->linkedAccount($fixedAsset);

        if (! $account instanceof Account) {
            throw new DomainException(__('fixed_assets.messages.linked_account_missing'));
        }

        if (! $this->accounts->isManagedLinkedAccount(BusinessPartnerAccountService::FixedAsset, $account) || ! $account->trashed()) {
            return;
        }

        $this->assertAccountCanBeRestored($account);
        $this->audit->restore($account, auth()->id());
    }

    public function softDeleteFixedAssetForAccount(Account $account): void
    {
        $fixedAsset = $this->owningFixedAsset($account);

        if (! $fixedAsset instanceof FixedAsset || $fixedAsset->trashed() || ! $this->accounts->isManagedLinkedAccount(BusinessPartnerAccountService::FixedAsset, $account)) {
            return;
        }

        $this->audit->softDelete($fixedAsset);
    }

    public function restoreFixedAssetForAccount(Account $account): void
    {
        $fixedAsset = $this->owningFixedAsset($account);

        if (! $fixedAsset instanceof FixedAsset || ! $fixedAsset->trashed() || ! $this->accounts->isManagedLinkedAccount(BusinessPartnerAccountService::FixedAsset, $account)) {
            return;
        }

        $this->assertFixedAssetCanBeRestored($fixedAsset);
        $this->audit->restore($fixedAsset, auth()->id());
    }

    public function syncFixedAssetForAccountUpdate(Account $account): void
    {
        $fixedAsset = $this->owningFixedAsset($account);

        if (! $fixedAsset instanceof FixedAsset || $fixedAsset->trashed() || ! $this->accounts->isManagedLinkedAccount(BusinessPartnerAccountService::FixedAsset, $account)) {
            return;
        }

        $values = [];
        $accountName = trim((string) $account->name);

        if ($accountName !== '' && trim((string) $fixedAsset->asset_name) !== $accountName) {
            $values['asset_name'] = $accountName;
        }

        if (in_array($account->status, ['active', 'inactive'], true) && $fixedAsset->status !== $account->status) {
            $values['status'] = $account->status;
        }

        $parent = $this->accounts->linkedAccountGroup(BusinessPartnerAccountService::FixedAsset, $account);
        $groupId = $parent?->getKey();

        if ((string) ($fixedAsset->asset_group_account_id ?? '') !== (string) ($groupId ?? '')) {
            $values['asset_group_account_id'] = $groupId;
        }

        if ($values !== []) {
            $this->audit->saveUpdate($fixedAsset, $values);
        }
    }

    private function linkedAccount(FixedAsset $fixedAsset): ?Account
    {
        if (! $fixedAsset->account_id) {
            return null;
        }

        return Account::withTrashed()->whereKey($fixedAsset->account_id)->first();
    }

    private function owningFixedAsset(Account $account): ?FixedAsset
    {
        return FixedAsset::withTrashed()
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

    private function assertFixedAssetCanBeRestored(FixedAsset $fixedAsset): void
    {
        $conflicts = fn (string $column, mixed $value): bool => FixedAsset::query()
            ->where('company_id', $fixedAsset->company_id)
            ->whereKeyNot($fixedAsset->getKey())
            ->where($column, $value)
            ->exists();

        if ($conflicts('account_id', $fixedAsset->account_id)
            || $conflicts('doc_num', $fixedAsset->doc_num)
            || $conflicts('doc_number', $fixedAsset->doc_number)) {
            throw new DomainException(__('fixed_assets.messages.restore_conflict'));
        }
    }
}
