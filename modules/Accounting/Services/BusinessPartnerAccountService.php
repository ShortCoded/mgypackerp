<?php

namespace Modules\Accounting\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\AccountClassification;
use Modules\Core\Services\OperatingCompanyContextService;

class BusinessPartnerAccountService
{
    public const Customer = 'customer';

    public const Supplier = 'supplier';

    public const FixedAsset = 'fixed_asset';

    public function __construct(
        private readonly AccountService $accounts,
        private readonly OperatingCompanyContextService $companies,
        private readonly AccountClassificationRegistry $classifications,
        private readonly FoundationalAccountResolver $foundationalAccounts,
        private readonly AccountCodeAllocator $accountCodes,
    ) {}

    public function rootAccount(string $type): Account
    {
        $spec = $this->spec($type);

        if ($type !== self::FixedAsset) {
            return $this->foundationalAccounts->resolveConfiguredCode(
                $this->companies->requireCompanyId(),
                $spec['classification_code'],
                $spec['root_code'],
                $spec['messages']['root_missing'],
                $spec['messages']['root_ambiguous'],
            );
        }

        return $this->foundationalAccounts->resolve(
            $this->companies->requireCompanyId(),
            $spec['classification_code'],
            $spec['messages']['root_missing'],
            $spec['messages']['root_ambiguous'],
        );
    }

    public function parentAccount(string $type, ?string $groupDocNum): Account
    {
        if ($groupDocNum === null || trim($groupDocNum) === '') {
            return $this->rootAccount($type);
        }

        $account = Account::query()
            ->with('classification')
            ->forCompany($this->companies->requireCompanyId())
            ->where('doc_num', $groupDocNum)
            ->first();

        if (! $account instanceof Account || ! $this->isSelectableGroup($type, $account)) {
            throw new DomainException(__($this->spec($type)['messages']['group_unavailable']));
        }

        return $account;
    }

    public function createGroup(string $type, string $name, ?string $notes = null): Account
    {
        $spec = $this->spec($type);

        return $this->accountCodes->transaction(function () use ($type, $name, $notes, $spec): Account {
            $root = $this->rootAccount($type);
            $root = Account::query()
                ->with('classification')
                ->forCompany((int) $root->company_id)
                ->whereKey($root->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($type === self::FixedAsset && $this->foundationalAccounts->hasFoundationalLabel($root, $name)) {
                throw new DomainException(__('fixed_assets.messages.asset_category_reserved_root_name'));
            }

            $duplicateExists = Account::query()
                ->forCompany((int) $root->company_id)
                ->where('parent_id', $root->getKey())
                ->where('status', 'active')
                ->get(['name'])
                ->contains(fn (Account $account): bool => Str::lower(trim($account->name)) === Str::lower(trim($name)));

            if ($duplicateExists) {
                throw new DomainException(__('erp_errors.duplicate_name'));
            }

            return $this->accounts->createChildFromParent($root, [
                'name' => $name,
                'name_en' => null,
                'classification_code' => $spec['classification_code'],
                'is_group' => true,
                'is_postable' => false,
                'status' => 'active',
                'notes' => $notes,
            ]);
        });
    }

    /**
     * @return array{account: Account, changed: bool}
     */
    public function createOrUpdateLinkedAccount(string $type, ?Account $currentAccount, Account $parent, array $data): array
    {
        if (! $currentAccount instanceof Account || ! $this->isManagedLinkedAccount($type, $currentAccount)) {
            $this->assertAllowedLinkedAccountParent($type, $parent);

            return [
                'account' => $this->createLinkedAccount(
                    $type,
                    $parent,
                    $this->linkedAccountName($data),
                    $this->linkedAccountStatus($data),
                    $data['notes'] ?? null,
                ),
                'changed' => true,
            ];
        }

        return $this->updateLinkedAccount($type, $currentAccount, $parent, $data);
    }

    /**
     * @return array{account: Account, changed: bool}
     */
    public function updateLinkedAccount(string $type, Account $currentAccount, Account $parent, array $data): array
    {
        $spec = $this->spec($type);
        $this->assertAllowedLinkedAccountParent($type, $parent);

        $values = [
            'account_code' => (int) $currentAccount->parent_id === (int) $parent->getKey()
                ? $currentAccount->account_code
                : $this->accounts->nextChildAccountCode($parent),
            'name' => $this->linkedAccountName($data),
            'name_en' => $currentAccount->name_en,
            'parent_doc_num' => $parent->doc_num,
            'classification_code' => $spec['classification_code'],
            'account_type' => $parent->account_type,
            'statement_type' => $parent->statement_type,
            'normal_balance' => $parent->normal_balance,
            'is_group' => false,
            'is_postable' => true,
            'status' => $this->linkedAccountStatus($data),
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
        return trim((string) ($data['name'] ?? ''));
    }

    private function linkedAccountStatus(array $data): string
    {
        return in_array($data['status'] ?? 'active', ['active', 'inactive'], true)
            ? $data['status']
            : 'active';
    }

    public function isSelectableGroup(string $type, Account $account): bool
    {
        return in_array((int) $account->getKey(), $this->selectableGroupIds($type), true);
    }

    /**
     * @return list<int>
     */
    public function selectableGroupIds(string $type): array
    {
        $root = $this->rootAccount($type);
        $directGroups = Account::query()
            ->with('classification')
            ->forCompany((int) $root->company_id)
            ->where('parent_id', $root->getKey())
            ->where('status', 'active')
            ->where('is_group', true)
            ->where('is_postable', false)
            ->whereHas('classification', fn ($query) => $query
                ->where('code', $this->spec($type)['classification_code'])
                ->where('status', 'active'))
            ->oldest('id')
            ->get()
            ->reject(fn (Account $account): bool => $type === self::FixedAsset
                && ($this->foundationalAccounts->hasFoundationalLabel($root, (string) $account->name)
                    || ($account->name_en !== null && $this->foundationalAccounts->hasFoundationalLabel($root, $account->name_en))));
        $directGroupIds = $directGroups
            ->map(fn (Account $account): int => (int) $account->getKey())
            ->all();

        if ($directGroupIds === []) {
            return [];
        }

        $branchIds = $this->descendantIdsFromParents($root, $directGroupIds);

        return Account::query()
            ->where('company_id', $root->company_id)
            ->whereIn('id', $branchIds)
            ->where('status', 'active')
            ->where('is_group', true)
            ->where('is_postable', false)
            ->whereHas('classification', fn ($query) => $query
                ->where('code', $this->spec($type)['classification_code'])
                ->where('status', 'active'))
            ->pluck('id')
            ->map(fn (int|string $id): int => (int) $id)
            ->all();
    }

    public function ensureFixedAssetBaselineForCompany(int $companyId): void
    {
        $this->ensureFixedAssetClassification();
    }

    public function isManagedLinkedAccount(string $type, Account $account): bool
    {
        return ! $account->is_group
            && $account->is_postable
            && $this->isPartnerAccount($type, $account)
            && $this->isDescendantOf($account, $this->rootAccount($type));
    }

    public function linkedAccountGroup(string $type, ?Account $account): ?Account
    {
        if (! $account instanceof Account || ! $account->parent_id || ! $this->isManagedLinkedAccount($type, $account)) {
            return null;
        }

        $parent = Account::query()
            ->with('classification')
            ->where('company_id', $account->company_id)
            ->find($account->parent_id);

        if (! $parent instanceof Account) {
            return null;
        }

        if ((int) $parent->getKey() === (int) $this->rootAccount($type)->getKey()) {
            return null;
        }

        return $this->isSelectableGroup($type, $parent) ? $parent : null;
    }

    public function hasFinancialMovements(Account $account): bool
    {
        foreach (['journal_entry_lines', 'opening_balance_lines'] as $table) {
            if (DB::getSchemaBuilder()->hasTable($table)
                && DB::table($table)->where('account_id', $account->getKey())->exists()) {
                return true;
            }
        }

        return false;
    }

    public function isDescendantOf(Account $account, Account $ancestor): bool
    {
        $parentId = $account->parent_id;

        while ($parentId !== null) {
            if ((int) $parentId === (int) $ancestor->getKey()) {
                return true;
            }

            $parentId = Account::query()
                ->where('company_id', $account->company_id)
                ->whereKey($parentId)
                ->value('parent_id');
        }

        return false;
    }

    /**
     * @return list<int>
     */
    private function descendantIdsFromParents(Account $root, array $directGroupIds): array
    {
        $descendantIds = $directGroupIds;
        $parentIds = $directGroupIds;

        while ($parentIds !== []) {
            $childIds = Account::query()
                ->where('company_id', $root->company_id)
                ->whereIn('parent_id', $parentIds)
                ->pluck('id')
                ->map(fn (int|string $id): int => (int) $id)
                ->all();
            $newChildIds = array_values(array_diff($childIds, $descendantIds));

            if ($newChildIds === []) {
                break;
            }

            $descendantIds = [...$descendantIds, ...$newChildIds];
            $parentIds = $newChildIds;
        }

        return $descendantIds;
    }

    private function ensureFixedAssetClassification(): AccountClassification
    {
        $classification = $this->classifications->activeRecord(AccountClassification::FixedAssets);

        if (! $classification instanceof AccountClassification) {
            throw new DomainException('The fixed_assets account classification is not registered and active.');
        }

        return $classification;
    }

    /**
     * @return array{
     *     classification_code: string,
     *     root_code: string|null,
     *     messages: array<string, string>
     * }
     */
    private function spec(string $type): array
    {
        return match ($type) {
            self::Customer => [
                'classification_code' => 'accounts_receivable',
                'root_code' => '1121',
                'messages' => [
                    'root_missing' => 'customers.messages.root_account_missing',
                    'root_ambiguous' => 'customers.messages.root_account_ambiguous',
                    'group_unavailable' => 'customers.messages.customer_group_unavailable',
                ],
            ],
            self::Supplier => [
                'classification_code' => 'accounts_payable',
                'root_code' => '2111',
                'messages' => [
                    'root_missing' => 'suppliers.messages.root_account_missing',
                    'root_ambiguous' => 'suppliers.messages.root_account_ambiguous',
                    'group_unavailable' => 'suppliers.messages.supplier_group_unavailable',
                ],
            ],
            self::FixedAsset => [
                'classification_code' => AccountClassification::FixedAssets,
                'root_code' => null,
                'messages' => [
                    'root_missing' => 'fixed_assets.messages.root_account_missing',
                    'root_ambiguous' => 'fixed_assets.messages.root_account_ambiguous',
                    'group_unavailable' => 'fixed_assets.messages.asset_category_unavailable',
                ],
            ],
            default => throw new DomainException('Unsupported business partner account type.'),
        };
    }

    private function createLinkedAccount(string $type, Account $parent, string $name, string $status, ?string $notes = null): Account
    {
        return $this->accounts->createChildFromParent($parent, [
            'name' => $name,
            'name_en' => null,
            'classification_code' => $this->spec($type)['classification_code'],
            'is_group' => false,
            'is_postable' => true,
            'status' => $status,
            'notes' => $notes,
        ]);
    }

    private function isAllowedLinkedAccountParent(string $type, Account $parent): bool
    {
        $root = $this->rootAccount($type);

        return (int) $parent->getKey() === (int) $root->getKey()
            || $this->isSelectableGroup($type, $parent);
    }

    private function assertAllowedLinkedAccountParent(string $type, Account $parent): void
    {
        if (! $this->isAllowedLinkedAccountParent($type, $parent)) {
            throw new DomainException(__($this->spec($type)['messages']['group_unavailable']));
        }
    }

    private function isPartnerAccount(string $type, Account $account): bool
    {
        $classificationCode = $this->spec($type)['classification_code'];

        if ($account->relationLoaded('classification') && $account->classification?->code === $classificationCode) {
            return true;
        }

        return Account::withTrashed()
            ->where('company_id', $account->company_id)
            ->whereKey($account->getKey())
            ->whereHas('classification', fn ($query) => $query->where('code', $classificationCode))
            ->exists();
    }
}
