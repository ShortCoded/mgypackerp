<?php

namespace Modules\Purchases\Services;

use DomainException;
use Modules\Core\Models\ArchiveFile;
use Modules\Core\Models\Company;
use Modules\Core\Services\FilePickerService;
use Modules\Purchases\Models\GoodsReceiptInspection;
use Modules\Purchases\Models\SupplierQuotation;

class ProcurementAttachmentService
{
    public function __construct(private readonly FilePickerService $filePicker) {}

    /** @param list<string> $fileDocNums */
    public function attach(
        SupplierQuotation|GoodsReceiptInspection $record,
        array $fileDocNums,
        string $collection,
        int $companyId,
    ): void {
        $existingIds = $record->archiveFileUsages()
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

            $record->archiveFileUsages()->create([
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
    }
}
