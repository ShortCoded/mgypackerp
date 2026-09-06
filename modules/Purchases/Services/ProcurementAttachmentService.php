<?php

namespace Modules\Purchases\Services;

use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Models\ArchiveFile;
use Modules\Core\Models\ArchiveFileUsage;
use Modules\Core\Models\Company;
use Modules\Core\Services\FilePickerService;

class ProcurementAttachmentService
{
    public function __construct(private readonly FilePickerService $filePicker) {}

    public const OperationalCollection = 'procurement_documents';

    public const LineCollection = 'procurement_document_lines';

    public function documents(Model $record, string $collection = self::OperationalCollection, ?int $companyId = null): Collection
    {
        $companyId ??= (int) $record->getAttribute('company_id');

        return ArchiveFileUsage::query()->whereMorphedTo('usable', $record)
            ->where('collection', $collection)
            ->whereHas('file', fn ($query) => $query->where('attachable_type', (new Company)->getMorphClass())->where('attachable_id', $companyId))
            ->with('file')->orderBy('sort_order')->get();
    }

    /** @param list<string> $fileDocNums */
    public function attachLine(Model $line, array $fileDocNums, int $companyId): bool
    {
        return $this->attach($line, $fileDocNums, self::LineCollection, $companyId);
    }

    /** @param list<string> $fileDocNums */
    public function attach(
        Model $record,
        array $fileDocNums,
        string $collection,
        int $companyId,
    ): bool {
        $changed = false;
        $existingIds = ArchiveFileUsage::query()->whereMorphedTo('usable', $record)
            ->where('collection', $collection)
            ->pluck('archive_file_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        foreach (array_values(array_unique($fileDocNums)) as $index => $fileDocNum) {
            $file = $this->filePicker->selectableFileByPublicId($fileDocNum, $companyId, FilePickerService::AcceptDocument);
            if (! $file instanceof ArchiveFile) {
                throw new DomainException(__('The selected procurement attachment is unavailable.'));
            }
            if (in_array((int) $file->getKey(), $existingIds, true)) {
                continue;
            }

            $changed = true;
            ArchiveFileUsage::query()->create([
                'usable_type' => $record->getMorphClass(), 'usable_id' => $record->getKey(),
                'archive_file_id' => $file->getKey(),
                'company_attachable_type' => (new Company)->getMorphClass(),
                'company_attachable_id' => $companyId,
                'collection' => $collection,
                'role' => null,
                'sort_order' => $index,
                'created_by' => auth()->id(),
            ]);
            $existingIds[] = (int) $file->getKey();
        }

        return $changed;
    }
}
