<?php

namespace Modules\Accounting\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
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
    ) {}

    public function rootAccount(string $type): Account
    {
        $spec = $this->spec($type);
        $companyId = $this->companies->requireCompanyId();

        $account = Account::withTrashed()
            ->with('classification')
            ->forCompany($companyId)
            ->where('account_code', $spec['root_code'])
            ->first();

        if ($account instanceof Account && $account->trashed()) {
            $account->restore();
        }

        if (! $account instanceof Account) {
            $account = $this->createRootAccount($type);
        }

        if (! $account instanceof Account) {
            throw new DomainException(__($spec['messages']['root_missing']));
        }

        $classification = AccountClassification::query()
            ->where('code', $spec['classification_code'])
            ->where('status', 'active')
            ->first();

        $values = [
            'name' => $spec['root_name'],
            'name_en' => $spec['root_name_en'],
            'account_classification_id' => $classification?->getKey() ?? $account->account_classification_id,
            'is_group' => true,
            'is_postable' => false,
            'status' => 'active',
        ];

        $dirty = collect($values)->contains(fn (mixed $value, string $field): bool => (string) $account->{$field} !== (string) $value);

        if ($dirty) {
            $account->forceFill($values)->save();
        }

        return $account->refresh();
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

        return $this->accounts->createChildFromParent($this->rootAccount($type), [
            'name' => $name,
            'name_en' => null,
            'classification_code' => $spec['classification_code'],
            'is_group' => true,
            'is_postable' => false,
            'status' => 'active',
            'notes' => $notes,
        ]);
    }

    /**
     * @return array{account: Account, changed: bool}
     */
    public function createOrUpdateLinkedAccount(string $type, ?Account $currentAccount, Account $parent, array $data): array
    {
        $spec = $this->spec($type);

        if (! $this->isAllowedLinkedAccountParent($type, $parent)) {
            throw new DomainException(__($spec['messages']['group_unavailable']));
        }

        $name = $this->linkedAccountName($data);
        $status = in_array($data['status'] ?? 'active', ['active', 'inactive'], true) ? $data['status'] : 'active';

        if (! $currentAccount instanceof Account || ! $this->isManagedLinkedAccount($type, $currentAccount)) {
            return [
                'account' => $this->createLinkedAccount($type, $parent, $name, $status, $data['notes'] ?? null),
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
            'classification_code' => $spec['classification_code'],
            'account_type' => $parent->account_type,
            'statement_type' => $parent->statement_type,
            'normal_balance' => $parent->normal_balance,
            'is_group' => false,
            'is_postable' => true,
            'status' => $status,
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

    public function isSelectableGroup(string $type, Account $account): bool
    {
        $root = $this->rootAccount($type);

        return (int) $account->company_id === (int) $root->company_id
            && $account->status === 'active'
            && $account->is_group
            && ! $account->is_postable
            && (int) $account->getKey() !== (int) $root->getKey()
            && $this->isPartnerAccount($type, $account)
            && $this->isDescendantOf($account, $root);
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
     * @return array{
     *     root_code: string,
     *     parent_code: string,
     *     root_name: string,
     *     root_name_en: string,
     *     classification_code: string,
     *     messages: array<string, string>
     * }
     */
    private function spec(string $type): array
    {
        return match ($type) {
            self::Customer => [
                'root_code' => '1121',
                'parent_code' => '112',
                'root_name' => 'العملاء',
                'root_name_en' => 'Customers',
                'classification_code' => 'accounts_receivable',
                'messages' => [
                    'root_missing' => 'customers.messages.root_account_missing',
                    'group_unavailable' => 'customers.messages.customer_group_unavailable',
                ],
            ],
            self::Supplier => [
                'root_code' => '2111',
                'parent_code' => '211',
                'root_name' => 'الموردون',
                'root_name_en' => 'Suppliers',
                'classification_code' => 'accounts_payable',
                'messages' => [
                    'root_missing' => 'suppliers.messages.root_account_missing',
                    'group_unavailable' => 'suppliers.messages.supplier_group_unavailable',
                ],
            ],
            self::FixedAsset => [
                'root_code' => '121',
                'parent_code' => '12',
                'root_name' => 'الأصول الثابتة',
                'root_name_en' => 'Fixed Assets',
                'classification_code' => 'fixed_assets',
                'messages' => [
                    'root_missing' => 'fixed_assets.messages.root_account_missing',
                    'group_unavailable' => 'fixed_assets.messages.asset_category_unavailable',
                ],
            ],
            default => throw new DomainException('Unsupported business partner account type.'),
        };
    }

    private function createRootAccount(string $type): ?Account
    {
        return DB::transaction(function () use ($type): ?Account {
            $spec = $this->spec($type);
            $companyId = $this->companies->requireCompanyId();
            $parent = Account::query()
                ->forCompany($companyId)
                ->where('account_code', $spec['parent_code'])
                ->where('status', 'active')
                ->first();
            $classification = AccountClassification::query()
                ->where('code', $spec['classification_code'])
                ->where('status', 'active')
                ->first();

            if (! $parent instanceof Account || ! $classification instanceof AccountClassification) {
                return null;
            }

            if (Account::query()->forCompany($companyId)->where('account_code', $spec['root_code'])->exists()) {
                return null;
            }

            return $this->accounts->create([
                'account_code' => $spec['root_code'],
                'name' => $spec['root_name'],
                'name_en' => $spec['root_name_en'],
                'parent_doc_num' => $parent->doc_num,
                'classification_code' => $spec['classification_code'],
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
