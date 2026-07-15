<?php

namespace Modules\Core\Services;

use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Models\ArchiveFile;
use Modules\Core\Models\ArchiveFileUsage;
use Modules\Core\Models\Company;
use Modules\Core\Models\Product;
use Modules\FixedAssets\Models\FixedAsset;

class ArchiveFileUsageService
{
    public function __construct(
        private readonly CrudAuditService $crudAudit,
        private readonly OperatingCompanyContextService $companyContext,
    ) {}

    /**
     * @param  array{single?: bool, sort_order?: int}  $options
     */
    public function attachFileToRecord(ArchiveFile $file, Model $record, string $collection, ?string $role = null, array $options = []): ArchiveFileUsage
    {
        $this->assertSameCompanyContext($file, $record);

        $single = (bool) ($options['single'] ?? ($role !== null));
        $sortOrder = (int) ($options['sort_order'] ?? 0);

        if ($single) {
            $existing = $this->activeUsageQuery($record, $collection, $role)
                ->where('archive_file_id', $file->getKey())
                ->lockForUpdate()
                ->first();

            $staleUsages = $this->activeUsageQuery($record, $collection, $role)
                ->when($existing instanceof ArchiveFileUsage, fn (Builder $query): Builder => $query->whereKeyNot($existing->getKey()))
                ->lockForUpdate()
                ->get();

            foreach ($staleUsages as $usage) {
                $this->crudAudit->softDelete($usage);
            }

            if ($existing instanceof ArchiveFileUsage) {
                return $existing->refresh();
            }
        }

        $usage = new ArchiveFileUsage([
            'collection' => $collection,
            'role' => $role,
            'sort_order' => $sortOrder,
            'company_attachable_type' => $file->attachable_type,
            'company_attachable_id' => $file->attachable_id,
            'created_by' => auth()->id(),
        ]);

        $usage->file()->associate($file);
        $usage->usable()->associate($record);
        $usage->save();
        $this->crudAudit->clearCreationUpdateAudit($usage);

        return $usage->refresh();
    }

    /**
     * @param  array{sort_order?: int}  $options
     */
    public function replaceFileForRecord(ArchiveFile $file, Model $record, string $collection, ?string $role = null, array $options = []): ArchiveFileUsage
    {
        return $this->attachFileToRecord($file, $record, $collection, $role, [
            ...$options,
            'single' => true,
        ]);
    }

    public function detachUsage(Model $record, string $collection, ?string $role = null): void
    {
        $usages = $this->activeUsageQuery($record, $collection, $role)
            ->lockForUpdate()
            ->get();

        foreach ($usages as $usage) {
            $this->crudAudit->softDelete($usage);
        }
    }

    public function activeUsageForRecord(Model $record, string $collection, ?string $role = null): ?ArchiveFileUsage
    {
        return $this->activeUsageQuery($record, $collection, $role)
            ->with('file')
            ->first();
    }

    public function recordHasActiveUsage(Model $record, string $collection, ?string $role = null): bool
    {
        return $this->activeUsageQuery($record, $collection, $role)->exists();
    }

    public function recordUsesFile(Model $record, ArchiveFile $file, string $collection, ?string $role = null): bool
    {
        return $this->activeUsageQuery($record, $collection, $role)
            ->where('archive_file_id', $file->getKey())
            ->exists();
    }

    public function detachFileFromRecord(Model $record, ArchiveFile $file, string $collection, ?string $role = null, ?int $userId = null): bool
    {
        $usages = $this->activeUsageQuery($record, $collection, $role)
            ->where('archive_file_id', $file->getKey())
            ->lockForUpdate()
            ->get();

        foreach ($usages as $usage) {
            $this->crudAudit->softDelete($usage, $userId);
        }

        return $usages->isNotEmpty();
    }

    /**
     * @return Collection<int, ArchiveFileUsage>
     */
    public function usagesForFile(ArchiveFile $file): Collection
    {
        return ArchiveFileUsage::query()
            ->with('usable')
            ->where('archive_file_id', $file->getKey())
            ->latest('created_at')
            ->get();
    }

    public function isFileUsed(ArchiveFile $file): bool
    {
        return ArchiveFileUsage::query()
            ->where('archive_file_id', $file->getKey())
            ->exists();
    }

    /**
     * @return list<string>
     */
    public function usageLabelsForFile(ArchiveFile $file): array
    {
        return $this->usagesForFile($file)
            ->map(fn (ArchiveFileUsage $usage): string => $this->usageLabel($usage))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public function assertFileCanBeDeleted(ArchiveFile $file): void
    {
        $labels = $this->usageLabelsForFile($file);

        if ($labels === []) {
            return;
        }

        throw new DomainException(__('archive.file_used_in', [
            'records' => implode(', ', $labels),
        ]));
    }

    private function assertSameCompanyContext(ArchiveFile $file, Model $record): void
    {
        $fileCompanyId = $this->fileCompanyId($file);
        $targetCompanyId = $this->targetCompanyId($record);
        $currentCompanyId = $this->companyContext->currentCompanyId();

        if (($currentCompanyId !== null || $targetCompanyId !== null) && $fileCompanyId === null) {
            throw new DomainException(__('products.validation.selected_file_unavailable'));
        }

        if ($currentCompanyId !== null && $fileCompanyId !== null && (int) $fileCompanyId !== $currentCompanyId) {
            throw new DomainException(__('products.validation.selected_file_unavailable'));
        }

        if ($currentCompanyId !== null && $targetCompanyId !== null && (int) $targetCompanyId !== $currentCompanyId) {
            throw new DomainException(__('products.validation.selected_file_unavailable'));
        }

        if ($fileCompanyId !== null && $targetCompanyId !== null && (int) $fileCompanyId !== (int) $targetCompanyId) {
            throw new DomainException(__('products.validation.selected_file_unavailable'));
        }
    }

    private function fileCompanyId(ArchiveFile $file): ?int
    {
        if ((string) $file->attachable_type !== (new Company)->getMorphClass()) {
            return null;
        }

        return $file->attachable_id === null ? null : (int) $file->attachable_id;
    }

    private function targetCompanyId(Model $record): ?int
    {
        if ($record instanceof Company) {
            return (int) $record->getKey();
        }

        $companyId = $record->getAttribute('company_id');

        return $companyId === null ? null : (int) $companyId;
    }

    /**
     * @return Builder<ArchiveFileUsage>
     */
    private function activeUsageQuery(Model $record, string $collection, ?string $role = null): Builder
    {
        return ArchiveFileUsage::query()
            ->where('usable_type', $record->getMorphClass())
            ->where('usable_id', $record->getKey())
            ->where('collection', $collection)
            ->when($role === null, fn (Builder $query): Builder => $query->whereNull('role'), fn (Builder $query): Builder => $query->where('role', $role));
    }

    private function usageLabel(ArchiveFileUsage $usage): string
    {
        if ($usage->usable instanceof Product) {
            return trim(implode(' / ', array_filter([
                __('products.singular'),
                $usage->usable->doc_num,
                $usage->usable->name,
            ])));
        }

        if ($usage->usable instanceof FixedAsset) {
            return trim(implode(' / ', array_filter([
                __('fixed_assets.singular'),
                $usage->usable->doc_num,
                $usage->usable->asset_name,
            ])));
        }

        return trim(implode(' / ', array_filter([
            $usage->collection,
            $usage->role,
        ])));
    }
}
