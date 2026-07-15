<?php

namespace Modules\FixedAssets\Services;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\CostCenter;
use Modules\Accounting\Services\BusinessPartnerAccountService;
use Modules\Core\Models\ArchiveFile;
use Modules\Core\Models\Branch;
use Modules\Core\Models\BranchHall;
use Modules\Core\Models\Currency;
use Modules\Core\Services\ArchiveFileUsageService;
use Modules\Core\Services\CrudAuditService;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\FilePickerService;
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
    ) {}

    public function create(array $data): array
    {
        return DB::transaction(function () use ($data): array {
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
        return DB::transaction(function () use ($record, $data): array {
            $oldDocNumber = $record->doc_number === null ? null : (int) $record->doc_number;
            $oldDocNum = $record->doc_num;
            $parentAccount = $this->parentAccount($data);
            $this->assertLinkedAccountCanMove($record->account, $parentAccount);
            $chartResult = $this->syncsLinkedAccount($record, $data, $parentAccount)
                ? $this->accounts->createOrUpdateLinkedAccount(BusinessPartnerAccountService::FixedAsset, $record->account, $parentAccount, $this->linkedAccountData($data))
                : ['account' => $record->account, 'changed' => false];
            $values = $this->values($data, (int) $record->company_id, $chartResult['account'], $parentAccount);
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
        DB::transaction(function () use ($record): void {
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
        $purchaseValue = $this->nullableFloat($data['purchase_value'] ?? null);
        $isDepreciable = (bool) ($data['is_depreciable'] ?? true);
        $salvageValue = $isDepreciable ? ($this->nullableFloat($data['salvage_value'] ?? null) ?? 0.0) : 0.0;
        $previousDepreciation = $isDepreciable ? ($this->nullableFloat($data['previous_depreciation'] ?? null) ?? 0.0) : 0.0;
        $entryType = (string) ($data['entry_type'] ?? FixedAsset::EntryTypeNewAsset);
        $currency = $this->modelByDocNum(Currency::class, $companyId, $data['currency_doc_num'] ?? null);
        $exchangeRate = $currency?->is_main
            ? 1.0
            : $this->nullableFloat($data['exchange_rate'] ?? null);
        $depreciationMethod = $isDepreciable
            ? (trim((string) ($data['depreciation_method'] ?? '')) ?: null)
            : null;
        [$usefulLife, $annualDepreciationRate, $expectedUsageUnits] = $this->depreciationValues($data, $isDepreciable, $depreciationMethod);
        $branchId = $this->idByDocNum(Branch::class, $companyId, $data['branch_doc_num'] ?? null);
        $previousDepreciationUntilDate = $isDepreciable && $previousDepreciation > 0
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
            'asset_date' => $data['asset_date'],
            'asset_name' => $data['asset_name'],
            'description' => $data['description'] ?? null,
            'serial_number' => $data['serial_number'] ?? null,
            'purchase_date' => $data['purchase_date'] ?? null,
            'acquisition_date' => $data['acquisition_date'] ?? null,
            'operation_date' => $data['operation_date'] ?? null,
            'purchase_value' => $purchaseValue === null ? null : number_format($purchaseValue, 4, '.', ''),
            'salvage_value' => number_format($salvageValue, 4, '.', ''),
            'exchange_rate' => $exchangeRate === null ? null : number_format($exchangeRate, 6, '.', ''),
            'previous_depreciation' => number_format($previousDepreciation, 4, '.', ''),
            'previous_depreciation_until_date' => $previousDepreciationUntilDate,
            'depreciation_start_date' => $this->depreciationStartDate(
                $isDepreciable,
                $entryType,
                $data['operation_date'] ?? null,
                $previousDepreciation,
                $previousDepreciationUntilDate,
            ),
            'net_value' => $purchaseValue === null ? null : number_format($purchaseValue - $previousDepreciation, 4, '.', ''),
            'annual_depreciation_rate' => $annualDepreciationRate === null ? null : number_format($annualDepreciationRate, 4, '.', ''),
            'expected_usage_units' => $expectedUsageUnits === null ? null : number_format($expectedUsageUnits, 4, '.', ''),
            'useful_life' => $usefulLife === null ? null : number_format($usefulLife, 2, '.', ''),
            'is_depreciable' => $isDepreciable,
            'depreciation_method' => $depreciationMethod,
            'location_address' => $data['location_address'] ?? null,
            'status' => $data['status'] ?? 'active',
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
        ];
    }

    private function assertLinkedAccountCanMove(?Account $linkedAccount, Account $parentAccount): void
    {
        if (! $linkedAccount instanceof Account || (int) $linkedAccount->parent_id === (int) $parentAccount->getKey()) {
            return;
        }

        if ($linkedAccount->children()->exists()) {
            throw new DomainException(__('fixed_assets.messages.account_move_blocked_children'));
        }
    }

    private function syncsLinkedAccount(FixedAsset $record, array $data, Account $parentAccount): bool
    {
        if (! $record->account instanceof Account || ! $this->accounts->isManagedLinkedAccount(BusinessPartnerAccountService::FixedAsset, $record->account)) {
            return true;
        }

        return (int) $record->account->parent_id !== (int) $parentAccount->getKey()
            || trim((string) $record->account->name) !== trim((string) $data['asset_name'])
            || trim((string) $record->account->status) !== trim((string) ($data['status'] ?? 'active'));
    }

    private function changes(object $record, array $values): array
    {
        $changes = [];

        foreach ($values as $field => $value) {
            if ((string) $record->{$field} !== (string) $value) {
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
     * @return array{0: float|null, 1: float|null, 2: float|null}
     */
    private function depreciationValues(array $data, bool $isDepreciable, ?string $method): array
    {
        if (! $isDepreciable) {
            return [null, null, null];
        }

        $usefulLife = $this->nullableFloat($data['useful_life'] ?? null);
        $annualDepreciationRate = $this->nullableFloat($data['annual_depreciation_rate'] ?? null);
        $expectedUsageUnits = $this->nullableFloat($data['expected_usage_units'] ?? null);

        return $this->depreciationCalculator->normalizedMethodInputs((string) $method, $usefulLife, $annualDepreciationRate, $expectedUsageUnits);
    }

    private function depreciationStartDate(bool $isDepreciable, string $entryType, ?string $operationDate, float $previousDepreciation, ?string $previousDepreciationUntilDate): ?string
    {
        if (! $isDepreciable) {
            return null;
        }

        if ($entryType === FixedAsset::EntryTypeOpeningAsset && $previousDepreciation > 0 && $previousDepreciationUntilDate) {
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
