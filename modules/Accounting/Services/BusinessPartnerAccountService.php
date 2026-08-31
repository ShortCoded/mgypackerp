<?php

namespace Modules\Accounting\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\AccountClassification;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\OperatingCompanyContextService;

class BusinessPartnerAccountService
{
    public const Customer = 'customer';

    public const Supplier = 'supplier';

    public const FixedAsset = 'fixed_asset';

    public function __construct(
        private readonly AccountService $accounts,
        private readonly OperatingCompanyContextService $companies,
        private readonly DocumentNumberService $documentNumbers,
        private readonly AccountClassificationRegistry $classifications,
    ) {}

    public function rootAccount(string $type): Account
    {
        $spec = $this->spec($type);
        $companyId = $this->companies->requireCompanyId();

        $account = Account::query()
            ->with('classification')
            ->forCompany($companyId)
            ->where('account_code', $spec['root_code'])
            ->first();

        if (! $account instanceof Account) {
            $account = Account::onlyTrashed()
                ->with('classification')
                ->forCompany($companyId)
                ->where('account_code', $spec['root_code'])
                ->where('is_system', true)
                ->orderBy('id')
                ->first();

            if ($account instanceof Account && $this->canRestoreAccount($account)) {
                $account->restore();
            } else {
                $account = null;
            }
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

        if ($type === self::FixedAsset) {
            $parent = Account::query()
                ->forCompany($companyId)
                ->where('account_code', $spec['parent_code'])
                ->first();

            if (! $parent instanceof Account || ! $this->isSuitableFoundationGroup($account, $parent)) {
                throw new DomainException(__($spec['messages']['root_missing']));
            }

            $values = [
                'account_classification_id' => $classification?->getKey() ?? $account->account_classification_id,
            ];
        } else {
            $values = [
                'name' => $spec['root_name'],
                'name_en' => $spec['root_name_en'],
                'account_classification_id' => $classification?->getKey() ?? $account->account_classification_id,
                'is_group' => true,
                'is_postable' => false,
                'status' => 'active',
            ];
        }

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
        return in_array((int) $account->getKey(), $this->selectableGroupIds($type), true);
    }

    /**
     * @return list<int>
     */
    public function selectableGroupIds(string $type): array
    {
        $root = $this->rootAccount($type);
        $descendantIds = $this->descendantIds($root);

        if ($descendantIds === []) {
            return [];
        }

        return Account::query()
            ->where('company_id', $root->company_id)
            ->whereIn('id', $descendantIds)
            ->where('status', 'active')
            ->where('is_group', true)
            ->where('is_postable', false)
            ->whereHas('classification', fn ($query) => $query->where('code', $this->spec($type)['classification_code']))
            ->pluck('id')
            ->map(fn (int|string $id): int => (int) $id)
            ->all();
    }

    public function ensureFixedAssetBaselineForCompany(int $companyId): void
    {
        DB::transaction(function () use ($companyId): void {
            $classification = $this->ensureFixedAssetClassification();
            $assets = $this->ensureFoundationAccount($companyId, '1', 'الأصول', 'Assets', null, null, 'assets');

            if (! $this->isSuitableFoundationGroup($assets)) {
                return;
            }

            $nonCurrentAssets = $this->ensureFoundationAccount($companyId, '12', 'الأصول غير المتداولة', 'Non-current Assets', $assets, null, 'non_current_assets');

            if (! $this->isSuitableFoundationGroup($nonCurrentAssets, $assets)) {
                return;
            }

            $root = $this->ensureFoundationAccount($companyId, '121', 'الأصول الثابتة', 'Fixed Assets', $nonCurrentAssets, $classification, 'fixed_assets');

            if (! $this->isSuitableFoundationGroup($root, $nonCurrentAssets)) {
                return;
            }

            $this->linkFixedAssetClassification($root, $classification);

            $candidates = Account::query()
                ->where('company_id', $companyId)
                ->where('parent_id', $root->getKey())
                ->where('status', 'active')
                ->where('is_group', true)
                ->where('is_postable', false)
                ->orderBy('id')
                ->get();
            $usedIds = [];

            foreach ($this->fixedAssetBaselineCategories() as $key => $category) {
                $systemKey = "system:fixed_asset_category:{$key}";
                $existing = $this->fixedAssetCategoryBySystemKey($companyId, $systemKey, $root, $usedIds);

                if (! $existing instanceof Account) {
                    $existing = $candidates->first(function (Account $account) use ($category, $root, $usedIds): bool {
                        if (! $this->isSuitableFixedAssetCategory($account, $root)
                            || in_array((int) $account->getKey(), $usedIds, true)) {
                            return false;
                        }

                        $haystack = Str::lower(trim($account->name.' '.$account->name_en.' '.$account->notes));

                        return collect($category['aliases'])->contains(
                            fn (string $alias): bool => Str::contains($haystack, Str::lower($alias)),
                        );
                    });
                }

                if (! $existing instanceof Account) {
                    $preferredCodeOwner = Account::query()
                        ->where('company_id', $companyId)
                        ->where('account_code', $category['preferred_code'])
                        ->first();

                    if ($preferredCodeOwner instanceof Account
                        && $this->isSuitableFixedAssetCategory($preferredCodeOwner, $root)
                        && ! in_array((int) $preferredCodeOwner->getKey(), $usedIds, true)) {
                        $existing = $preferredCodeOwner;
                    }
                }

                if ($existing instanceof Account) {
                    $this->linkFixedAssetClassification($existing, $classification);
                    $usedIds[] = (int) $existing->getKey();

                    continue;
                }

                $accountCode = $this->availableFixedAssetCategoryCode($root, $category['preferred_code']);
                $document = $this->documentNumbers->nextForCompany('accounts', Account::class, $companyId);
                $created = Account::query()->create([
                    ...$document,
                    'company_id' => $companyId,
                    'account_code' => $accountCode,
                    'name' => $category['name'],
                    'name_en' => $category['name_en'],
                    'parent_id' => $root->getKey(),
                    'level' => (int) $root->level + 1,
                    'account_classification_id' => $classification->getKey(),
                    'account_type' => Account::TypeAsset,
                    'statement_type' => Account::StatementFinancialPosition,
                    'normal_balance' => Account::BalanceDebit,
                    'is_group' => true,
                    'is_postable' => false,
                    'is_system' => true,
                    'status' => 'active',
                    'notes' => $systemKey,
                ]);
                $candidates->push($created);
                $usedIds[] = (int) $created->getKey();
            }
        });
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
    private function descendantIds(Account $ancestor): array
    {
        $descendantIds = [];
        $parentIds = [(int) $ancestor->getKey()];

        while ($parentIds !== []) {
            $childIds = Account::query()
                ->where('company_id', $ancestor->company_id)
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
        $classification = AccountClassification::query()->where('code', 'fixed_assets')->first();

        if (! $classification instanceof AccountClassification) {
            $classification = AccountClassification::onlyTrashed()
                ->where('code', 'fixed_assets')
                ->where('is_system', true)
                ->orderBy('id')
                ->first();

            if ($classification instanceof AccountClassification) {
                $classification->restore();
            }
        }

        if (! $classification instanceof AccountClassification) {
            $definition = $this->classifications->definition('fixed_assets');

            if ($definition === null) {
                throw new DomainException('The fixed_assets account classification is not registered.');
            }

            return AccountClassification::query()->create([
                ...$this->documentNumbers->next('account_classifications', AccountClassification::class),
                ...$definition,
            ]);
        }

        $classification->forceFill(['is_system' => true, 'status' => 'active'])->save();

        return $classification;
    }

    private function ensureFoundationAccount(
        int $companyId,
        string $accountCode,
        string $name,
        string $nameEn,
        ?Account $parent,
        ?AccountClassification $classification,
        string $systemKey,
    ): Account {
        $systemIdentity = "system:fixed_asset_foundation:{$systemKey}";
        $canonical = Account::query()
            ->where('company_id', $companyId)
            ->where('notes', $systemIdentity)
            ->orderBy('id')
            ->first();

        if ($canonical instanceof Account) {
            return $canonical;
        }

        $account = Account::query()
            ->where('company_id', $companyId)
            ->where('account_code', $accountCode)
            ->first();

        if ($account instanceof Account) {
            return $account;
        }

        $canonical = Account::onlyTrashed()
            ->where('company_id', $companyId)
            ->where('notes', $systemIdentity)
            ->orderBy('id')
            ->first();

        if (! $canonical instanceof Account) {
            $canonical = Account::onlyTrashed()
                ->where('company_id', $companyId)
                ->where('account_code', $accountCode)
                ->where('is_system', true)
                ->orderBy('id')
                ->first();
        }

        if ($canonical instanceof Account && $this->canRestoreAccount($canonical)) {
            $canonical->restore();

            return $canonical;
        }

        return Account::query()->create([
            ...$this->documentNumbers->nextForCompany('accounts', Account::class, $companyId),
            'company_id' => $companyId,
            'account_code' => $accountCode,
            'name' => $name,
            'name_en' => $nameEn,
            'parent_id' => $parent?->getKey(),
            'level' => $parent instanceof Account ? (int) $parent->level + 1 : 1,
            'account_classification_id' => $classification?->getKey(),
            'account_type' => Account::TypeAsset,
            'statement_type' => Account::StatementFinancialPosition,
            'normal_balance' => Account::BalanceDebit,
            'is_group' => true,
            'is_postable' => false,
            'is_system' => true,
            'status' => 'active',
            'notes' => $systemIdentity,
        ]);
    }

    /**
     * @param  list<int>  $usedIds
     */
    private function fixedAssetCategoryBySystemKey(int $companyId, string $systemKey, Account $root, array $usedIds): ?Account
    {
        $category = Account::query()
            ->where('company_id', $companyId)
            ->where('notes', $systemKey)
            ->orderBy('id')
            ->first();

        if ($category instanceof Account) {
            return $this->isSuitableFixedAssetCategory($category, $root)
                && ! in_array((int) $category->getKey(), $usedIds, true)
                    ? $category
                    : null;
        }

        $category = Account::onlyTrashed()
            ->where('company_id', $companyId)
            ->where('notes', $systemKey)
            ->orderBy('id')
            ->first();

        if (! $category instanceof Account
            || ! $this->isSuitableFixedAssetCategory($category, $root)
            || ! $this->canRestoreAccount($category)) {
            return null;
        }

        $category->restore();

        return $this->isSuitableFixedAssetCategory($category, $root) ? $category : null;
    }

    private function isSuitableFoundationGroup(Account $account, ?Account $parent = null): bool
    {
        return $account->status === 'active'
            && $account->is_group
            && ! $account->is_postable
            && $account->account_type === Account::TypeAsset
            && $account->statement_type === Account::StatementFinancialPosition
            && ($parent instanceof Account
                ? (int) $account->parent_id === (int) $parent->getKey()
                : $account->parent_id === null);
    }

    private function isSuitableFixedAssetCategory(Account $account, Account $root): bool
    {
        return $this->isSuitableFoundationGroup($account, $root);
    }

    private function linkFixedAssetClassification(Account $account, AccountClassification $classification): void
    {
        if ((int) $account->account_classification_id === (int) $classification->getKey()) {
            return;
        }

        $account->forceFill(['account_classification_id' => $classification->getKey()])->save();
    }

    private function canRestoreAccount(Account $account): bool
    {
        return ! Account::query()
            ->where('company_id', $account->company_id)
            ->where(function ($query) use ($account): void {
                $query->where('account_code', $account->account_code);

                if ($account->doc_num !== null) {
                    $query->orWhere('doc_num', $account->doc_num);
                }

                if ($account->doc_number !== null) {
                    $query->orWhere('doc_number', $account->doc_number);
                }
            })
            ->exists();
    }

    private function availableFixedAssetCategoryCode(Account $root, string $preferredCode): string
    {
        if (! Account::withTrashed()->where('company_id', $root->company_id)->where('account_code', $preferredCode)->exists()) {
            return $preferredCode;
        }

        $accountCode = $this->accounts->nextChildAccountCode($root);

        while (Account::withTrashed()->where('company_id', $root->company_id)->where('account_code', $accountCode)->exists()) {
            $accountCode = (string) ((int) $accountCode + 1);
        }

        return $accountCode;
    }

    /**
     * @return array<string, array{name: string, name_en: string, preferred_code: string, aliases: list<string>}>
     */
    private function fixedAssetBaselineCategories(): array
    {
        return [
            'machinery' => ['name' => 'الآلات والمعدات', 'name_en' => 'Machinery and Equipment', 'preferred_code' => '1216', 'aliases' => ['system:fixed_asset_category:machinery', 'machinery', 'production equipment', 'injection molding']],
            'vehicles' => ['name' => 'وسائل النقل', 'name_en' => 'Vehicles', 'preferred_code' => '1217', 'aliases' => ['system:fixed_asset_category:vehicles', 'vehicle', 'transport', 'material handling']],
            'it_office' => ['name' => 'تقنية المعلومات والمعدات المكتبية', 'name_en' => 'IT and Office Equipment', 'preferred_code' => '1218', 'aliases' => ['system:fixed_asset_category:it_office', 'it & office', 'it and office', 'office equipment', 'computer']],
            'furniture' => ['name' => 'الأثاث والتجهيزات', 'name_en' => 'Furniture and Fixtures', 'preferred_code' => '1219', 'aliases' => ['system:fixed_asset_category:furniture', 'furniture', 'fixtures']],
            'land' => ['name' => 'الأراضي', 'name_en' => 'Land', 'preferred_code' => '12110', 'aliases' => ['system:fixed_asset_category:land', 'land']],
            'buildings' => ['name' => 'المباني', 'name_en' => 'Buildings', 'preferred_code' => '12111', 'aliases' => ['system:fixed_asset_category:buildings', 'building']],
        ];
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
