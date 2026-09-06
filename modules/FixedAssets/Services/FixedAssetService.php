<?php

namespace Modules\FixedAssets\Services;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\CostCenter;
use Modules\Accounting\Services\AccountCodeAllocator;
use Modules\Accounting\Services\BusinessPartnerAccountService;
use Modules\Core\Models\ArchiveFile;
use Modules\Core\Models\Branch;
use Modules\Core\Models\BranchHall;
use Modules\Core\Models\Currency;
use Modules\Core\Services\ArchiveFileUsageService;
use Modules\Core\Services\CrudAuditService;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\FilePickerService;
use Modules\Core\Services\NumericFormatService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\OperatingContextService;
use Modules\FixedAssets\Models\FixedAsset;

class FixedAssetService
{
    public function __construct(
        private readonly DocumentNumberService $documents,
        private readonly CrudAuditService $audit,
        private readonly BusinessPartnerAccountService $accounts,
        private readonly FixedAssetAccountingSyncService $accountingSync,
        private readonly OperatingCompanyContextService $companies,
        private readonly OperatingContextService $operatingContext,
        private readonly FixedAssetDepreciationCalculator $depreciationCalculator,
        private readonly FilePickerService $filePicker,
        private readonly ArchiveFileUsageService $fileUsages,
        private readonly NumericFormatService $numbers,
        private readonly AccountCodeAllocator $accountCodes,
    ) {}

    public function create(array $data): array
    {
        return $this->accountCodes->transaction(function () use ($data): array {
            $companyId = $this->companies->requireCompanyId();
            $parentAccount = $this->parentAccount($data);
            $linkedAccount = $this->accounts->createOrUpdateLinkedAccount(BusinessPartnerAccountService::FixedAsset, null, $parentAccount, $this->linkedAccountData($data))['account'];
            $values = $this->values($data, $companyId, $linkedAccount, $parentAccount);
            $selectedImageFile = $this->selectedArchiveImageFile($data['image_archive_file_doc_num'] ?? null, $companyId);

            if ($selectedImageFile instanceof ArchiveFile) {
                $values['image_path'] = (string) $selectedImageFile->path;
            }

            $record = FixedAsset::query()->create([
                ...$values,
                ...$this->document($data, $companyId),
                'created_by' => auth()->id(),
            ]);

            $this->audit->clearCreationUpdateAudit($record);

            if ($selectedImageFile instanceof ArchiveFile) {
                $this->fileUsages->replaceFileForRecord($selectedImageFile, $record, FixedAsset::ImageCollection, FixedAsset::MainImageRole);
            }

            return ['record' => $record->refresh()];
        });
    }

    public function update(FixedAsset $record, array $data): array
    {
        return $this->accountCodes->transaction(function () use ($record, $data): array {
            $record = FixedAsset::query()
                ->forCompany($this->companies->requireCompanyId())
                ->whereKey($record->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            app(FixedAssetAccessService::class)->assertAsset($record);
            $oldDocNumber = $record->doc_number === null ? null : (int) $record->doc_number;
            $oldDocNum = $record->doc_num;
            $parentAccount = $this->parentAccount($data);
            $linkedAccount = $this->lockCanonicalLinkedAccount($record);
            $this->assertLinkedAccountCanMove($linkedAccount, $parentAccount);
            $chartResult = $this->syncsLinkedAccount($linkedAccount, $data, $parentAccount)
                ? $this->accounts->updateLinkedAccount(BusinessPartnerAccountService::FixedAsset, $linkedAccount, $parentAccount, $this->linkedAccountData($data))
                : ['account' => $linkedAccount, 'changed' => false];
            $values = $this->values($data, (int) $record->company_id, $chartResult['account'], $parentAccount);
            $values['period_id'] = $record->period_id;
            if ($record->isMasterLocked()) {
                $values['net_value'] = $record->net_value;
            }
            $selectedImageFile = $this->selectedArchiveImageFile($data['image_archive_file_doc_num'] ?? null, (int) $record->company_id);
            $detachesImage = ($data['remove_image'] ?? false) === true;

            if ($selectedImageFile instanceof ArchiveFile) {
                $values['image_path'] = (string) $selectedImageFile->path;
            } elseif ($detachesImage) {
                $values['image_path'] = null;
            }

            if (array_key_exists('doc_number', $data)) {
                $values = [...$values, ...$this->document($data, (int) $record->company_id)];
            }

            $changes = $this->changes($record, $values);
            $this->assertMasterUpdateAllowed($record, $changes);
            $usageChangeNeeded = $selectedImageFile instanceof ArchiveFile
                && ! $this->fileUsages->recordUsesFile($record, $selectedImageFile, FixedAsset::ImageCollection, FixedAsset::MainImageRole);
            $detachChangeNeeded = $detachesImage
                && $this->fileUsages->recordHasActiveUsage($record, FixedAsset::ImageCollection, FixedAsset::MainImageRole);

            if (($usageChangeNeeded || $detachChangeNeeded) && ! array_key_exists('image_path', $changes)) {
                $changes['image_path'] = [
                    'old' => $record->image_path ? 'image_present' : null,
                    'new' => $selectedImageFile instanceof ArchiveFile ? 'image_updated' : null,
                ];
            }

            if ($changes === [] && ! $chartResult['changed']) {
                return [
                    'record' => $record->refresh(),
                    'changed' => false,
                    'changes' => [],
                    'old_doc_number' => $oldDocNumber,
                    'old_doc_num' => $oldDocNum,
                ];
            }

            if ($changes !== []) {
                $this->audit->saveUpdate($record, $values);
            }

            if ($selectedImageFile instanceof ArchiveFile) {
                $this->fileUsages->replaceFileForRecord($selectedImageFile, $record, FixedAsset::ImageCollection, FixedAsset::MainImageRole);
            } elseif ($detachesImage) {
                $this->fileUsages->detachUsage($record, FixedAsset::ImageCollection, FixedAsset::MainImageRole);
            }

            return [
                'record' => $record->refresh(),
                'changed' => true,
                'changes' => $changes,
                'old_doc_number' => $oldDocNumber,
                'old_doc_num' => $oldDocNum,
            ];
        });
    }

    public function delete(FixedAsset $record): void
    {
        app(FixedAssetAccessService::class)->assertAsset($record);
        DB::transaction(function () use ($record): void {
            $record = FixedAsset::query()->forCompany($this->companies->requireCompanyId())->whereKey($record->getKey())->lockForUpdate()->firstOrFail();

            if ($record->isMasterLocked()) {
                throw new DomainException(__('fixed_assets.messages.delete_blocked_lifecycle'));
            }

            $this->accountingSync->softDeleteLinkedAccountForFixedAsset($record);
            $this->audit->softDelete($record);
        });
    }

    public function bulkDelete(array $docNums): int
    {
        return DB::transaction(function () use ($docNums): int {
            $deleted = 0;

            foreach (FixedAsset::query()->forCompany($this->companies->requireCompanyId())->whereIn('doc_num', $docNums)->get() as $record) {
                $this->delete($record);
                $deleted++;
            }

            return $deleted;
        });
    }

    public function restore(FixedAsset $record): FixedAsset
    {
        return DB::transaction(function () use ($record): FixedAsset {
            $record = FixedAsset::withTrashed()
                ->forCompany($this->companies->requireCompanyId())
                ->whereKey($record->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (FixedAsset::query()->forCompany((int) $record->company_id)->where('account_id', $record->account_id)->whereKeyNot($record->getKey())->exists()) {
                throw new DomainException(__('fixed_assets.messages.restore_conflict'));
            }

            $this->accountingSync->restoreLinkedAccountForFixedAsset($record);
            $this->audit->restore($record, auth()->id());

            return $record->refresh();
        });
    }

    private function document(array $data, int $companyId): array
    {
        return array_key_exists('doc_number', $data) && $data['doc_number']
            ? ['doc_number' => (int) $data['doc_number'], 'doc_num' => $this->documents->format('fixed_assets', (int) $data['doc_number'])]
            : $this->documents->nextForCompany('fixed_assets', FixedAsset::class, $companyId);
    }

    private function values(array $data, int $companyId, Account $linkedAccount, Account $parentAccount): array
    {
        $root = $this->accounts->rootAccount(BusinessPartnerAccountService::FixedAsset);
        $purchaseValueDecimal = $this->numbers->normalizeToScale($data['purchase_value'] ?? null, 4);
        $isDepreciable = (bool) ($data['is_depreciable'] ?? true);
        $salvageValueDecimal = $isDepreciable
            ? ($this->numbers->normalizeToScale($data['salvage_value'] ?? null, 4) ?? '0.0000')
            : '0.0000';
        $previousDepreciationDecimal = $isDepreciable
            ? ($this->numbers->normalizeToScale($data['previous_depreciation'] ?? null, 4) ?? '0.0000')
            : '0.0000';
        $hasPreviousDepreciation = ! $this->numbers->equivalent($previousDepreciationDecimal, 0);
        $entryType = (string) ($data['entry_type'] ?? FixedAsset::EntryTypeNewAsset);
        $currency = $this->modelByDocNum(Currency::class, $companyId, $data['currency_doc_num'] ?? null);
        $exchangeRate = $currency?->is_main
            ? '1.000000'
            : $this->numbers->normalizeToScale($data['exchange_rate'] ?? null, 6);
        $effectiveExchangeRate = $exchangeRate ?? '1.000000';
        $depreciationMethod = $isDepreciable
            ? (trim((string) ($data['depreciation_method'] ?? '')) ?: null)
            : null;
        [$usefulLife, $annualDepreciationRate, $expectedUsageUnits] = $this->depreciationValues($data, $isDepreciable, $depreciationMethod);
        $branchId = $this->idByDocNum(Branch::class, $companyId, $data['branch_doc_num'] ?? null);
        $previousDepreciationUntilDate = $isDepreciable && $entryType === FixedAsset::EntryTypeOpeningAsset
            ? ($data['previous_depreciation_until_date'] ?? null)
            : null;

        return [
            'company_id' => $companyId,
            'period_id' => Arr::get($this->operatingContext->snapshot(request()), 'financial_period_id'),
            'branch_id' => $branchId,
            'branch_hall_id' => $this->branchHallId($companyId, $branchId, $data['branch_hall_uuid'] ?? null),
            'account_id' => $linkedAccount->getKey(),
            'asset_group_account_id' => (int) $parentAccount->getKey() === (int) $root->getKey() ? null : $parentAccount->getKey(),
            'credit_account_id' => $this->idByDocNum(Account::class, $companyId, $data['credit_account_doc_num'] ?? null),
            'cost_center_id' => $this->idByDocNum(CostCenter::class, $companyId, $data['cost_center_doc_num'] ?? null),
            'currency_id' => $currency?->getKey(),
            'entry_type' => $entryType,
            'source_type' => $data['source_type'] ?? null,
            'source_id' => isset($data['source_id']) ? (int) $data['source_id'] : null,
            'source_doc_num' => $data['source_doc_num'] ?? null,
            'asset_date' => $data['asset_date'],
            'asset_name' => $data['asset_name'],
            'description' => $data['description'] ?? null,
            'serial_number' => $data['serial_number'] ?? null,
            'purchase_date' => $data['purchase_date'] ?? null,
            'acquisition_date' => $data['acquisition_date'] ?? null,
            'operation_date' => $data['operation_date'] ?? null,
            'purchase_value' => $purchaseValueDecimal,
            'base_acquisition_value' => $purchaseValueDecimal === null ? null : bcmul($purchaseValueDecimal, $effectiveExchangeRate, 4),
            'salvage_value' => $salvageValueDecimal,
            'exchange_rate' => $exchangeRate,
            'previous_depreciation' => $previousDepreciationDecimal,
            'previous_depreciation_until_date' => $previousDepreciationUntilDate,
            'depreciation_start_date' => $isDepreciable && ! empty($data['depreciation_start_date']) ? $data['depreciation_start_date'] : $this->depreciationStartDate(
                $isDepreciable,
                $entryType,
                $data['operation_date'] ?? null,
                false,
                null,
            ),
            'net_value' => $purchaseValueDecimal === null ? null : bcsub($purchaseValueDecimal, $previousDepreciationDecimal, 4),
            'annual_depreciation_rate' => $annualDepreciationRate,
            'expected_usage_units' => $expectedUsageUnits,
            'useful_life' => $usefulLife,
            'is_depreciable' => $isDepreciable,
            'depreciation_method' => $depreciationMethod,
            'location_address' => $data['location_address'] ?? null,
            'status' => $data['status'] ?? FixedAsset::StatusActive,
            'notes' => $data['notes'] ?? null,
        ];
    }

    private function parentAccount(array $data): Account
    {
        return $this->accounts->parentAccount(BusinessPartnerAccountService::FixedAsset, $data['asset_group_account_doc_num'] ?? null);
    }

    private function linkedAccountData(array $data): array
    {
        return [
            ...$data,
            'name' => $data['asset_name'] ?? '',
            'status' => in_array($data['status'] ?? FixedAsset::StatusActive, [FixedAsset::StatusDraft, FixedAsset::StatusActive, FixedAsset::StatusSuspended, FixedAsset::StatusFullyDepreciated], true)
                ? 'active'
                : 'inactive',
        ];
    }

    /**
     * @param  array<string, array{old: mixed, new: mixed}>  $changes
     */
    private function assertMasterUpdateAllowed(FixedAsset $record, array $changes): void
    {
        if (! $record->isMasterLocked()) {
            return;
        }

        $protected = [
            'asset_date',
            'branch_id',
            'branch_hall_id',
            'cost_center_id',
            'account_id',
            'asset_group_account_id',
            'credit_account_id',
            'currency_id',
            'entry_type',
            'source_type',
            'source_id',
            'source_doc_num',
            'purchase_date',
            'acquisition_date',
            'operation_date',
            'purchase_value',
            'base_acquisition_value',
            'salvage_value',
            'exchange_rate',
            'previous_depreciation',
            'previous_depreciation_until_date',
            'depreciation_start_date',
            'annual_depreciation_rate',
            'expected_usage_units',
            'useful_life',
            'is_depreciable',
            'depreciation_method',
            'location_address',
            'status',
        ];

        if (array_intersect(array_keys($changes), $protected) !== []) {
            throw new DomainException(__('fixed_assets.messages.master_locked'));
        }
    }

    private function lockCanonicalLinkedAccount(FixedAsset $record): Account
    {
        if (! $record->account_id) {
            throw new DomainException(__('fixed_assets.messages.linked_account_missing'));
        }

        $linkedAccount = Account::query()
            ->forCompany((int) $record->company_id)
            ->whereKey($record->account_id)
            ->lockForUpdate()
            ->first();

        if (! $linkedAccount instanceof Account || $linkedAccount->is_group || ! $linkedAccount->is_postable) {
            throw new DomainException(__('fixed_assets.messages.linked_account_missing'));
        }

        $record->setRelation('account', $linkedAccount);

        return $linkedAccount;
    }

    private function assertLinkedAccountCanMove(Account $linkedAccount, Account $parentAccount): void
    {
        if ((int) $linkedAccount->parent_id === (int) $parentAccount->getKey()) {
            return;
        }

        if ((int) $linkedAccount->getKey() === (int) $parentAccount->getKey()
            || $linkedAccount->children()->exists()
            || $this->accounts->isDescendantOf($parentAccount, $linkedAccount)
        ) {
            throw new DomainException(__('fixed_assets.messages.account_move_blocked_children'));
        }
    }

    private function syncsLinkedAccount(Account $linkedAccount, array $data, Account $parentAccount): bool
    {
        $linkedAccountStatus = in_array($data['status'] ?? FixedAsset::StatusActive, [FixedAsset::StatusDraft, FixedAsset::StatusActive, FixedAsset::StatusSuspended, FixedAsset::StatusFullyDepreciated], true)
            ? 'active'
            : 'inactive';

        return (int) $linkedAccount->parent_id !== (int) $parentAccount->getKey()
            || trim((string) $linkedAccount->name) !== trim((string) $data['asset_name'])
            || trim((string) $linkedAccount->status) !== $linkedAccountStatus;
    }

    private function changes(object $record, array $values): array
    {
        $changes = [];
        $candidate = clone $record;
        $candidate->fill($values);
        foreach ($values as $field => $value) {
            if ($candidate->isDirty($field)) {
                $changes[$field] = ['old' => $record->{$field}, 'new' => $value];
            }
        }

        return $changes;
    }

    /**
     * @param  class-string<Model>  $model
     */
    private function idByDocNum(string $model, int $companyId, ?string $docNum): ?int
    {
        $docNum = trim((string) $docNum);

        if ($docNum === '') {
            return null;
        }

        $id = $model::query()
            ->where('company_id', $companyId)
            ->where('doc_num', $docNum)
            ->whereNull('deleted_at')
            ->value('id');

        return $id ? (int) $id : null;
    }

    /**
     * @param  class-string<Model>  $model
     */
    private function modelByDocNum(string $model, int $companyId, ?string $docNum): ?Model
    {
        $docNum = trim((string) $docNum);

        if ($docNum === '') {
            return null;
        }

        return $model::query()
            ->where('company_id', $companyId)
            ->where('doc_num', $docNum)
            ->whereNull('deleted_at')
            ->first();
    }

    private function branchHallId(int $companyId, ?int $branchId, ?string $publicUuid): ?int
    {
        $publicUuid = trim((string) $publicUuid);

        if ($branchId === null || $publicUuid === '') {
            return null;
        }

        $id = BranchHall::query()
            ->where('branch_id', $branchId)
            ->where('public_uuid', $publicUuid)
            ->whereNull('deleted_at')
            ->whereHas('branch', fn ($query) => $query->where('company_id', $companyId)->whereNull('deleted_at'))
            ->value('id');

        return $id ? (int) $id : null;
    }

    private function nullableFloat(mixed $value): ?float
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return (float) $value;
    }

    /**
     * @return array{0: string|null, 1: string|null, 2: string|null}
     */
    private function depreciationValues(array $data, bool $isDepreciable, ?string $method): array
    {
        if (! $isDepreciable) {
            return [null, null, null];
        }

        $usefulLifeInput = $this->numbers->normalizeToScale($data['useful_life'] ?? null, 2);
        $annualDepreciationRateInput = $this->numbers->normalizeToScale($data['annual_depreciation_rate'] ?? null, 4);
        $expectedUsageUnitsInput = $this->numbers->normalizeToScale($data['expected_usage_units'] ?? null, 4);
        [$usefulLife, $annualDepreciationRate, $expectedUsageUnits] = $this->depreciationCalculator->normalizedMethodInputs(
            (string) $method,
            $this->nullableFloat($usefulLifeInput),
            $this->nullableFloat($annualDepreciationRateInput),
            $this->nullableFloat($expectedUsageUnitsInput),
        );

        $usesDirectUsefulLife = in_array($method, [
            FixedAsset::DepreciationMethodStraightLine,
            FixedAsset::DepreciationMethodDoubleDecliningBalance,
            FixedAsset::DepreciationMethodSumOfYearsDigits,
        ], true);
        $usesDirectAnnualRate = in_array($method, [
            FixedAsset::DepreciationMethodStraightLine,
            FixedAsset::DepreciationMethodDecliningBalance,
        ], true);

        return [
            $usefulLife === null
                ? null
                : ($usesDirectUsefulLife && $usefulLifeInput !== null
                    ? $usefulLifeInput
                    : number_format($usefulLife, 2, '.', '')),
            $annualDepreciationRate === null
                ? null
                : ($usesDirectAnnualRate && $annualDepreciationRateInput !== null
                    ? $annualDepreciationRateInput
                    : number_format($annualDepreciationRate, 4, '.', '')),
            $expectedUsageUnits === null
                ? null
                : ($expectedUsageUnitsInput ?? number_format($expectedUsageUnits, 4, '.', '')),
        ];
    }

    private function depreciationStartDate(bool $isDepreciable, string $entryType, ?string $operationDate, bool $hasPreviousDepreciation, ?string $previousDepreciationUntilDate): ?string
    {
        if (! $isDepreciable) {
            return null;
        }

        if ($entryType === FixedAsset::EntryTypeOpeningAsset && $hasPreviousDepreciation && $previousDepreciationUntilDate) {
            return Carbon::parse($previousDepreciationUntilDate)->addDay()->toDateString();
        }

        return $operationDate ?: null;
    }

    private function selectedArchiveImageFile(mixed $publicId, int $companyId): ?ArchiveFile
    {
        $publicId = trim((string) $publicId);

        if ($publicId === '') {
            return null;
        }

        $file = $this->filePicker->selectableFileByPublicId($publicId, $companyId, FilePickerService::AcceptImage);

        if (! $file instanceof ArchiveFile) {
            throw new DomainException(__('fixed_assets.validation.selected_file_unavailable'));
        }

        return $file;
    }
}
