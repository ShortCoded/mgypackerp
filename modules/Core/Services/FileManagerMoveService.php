<?php

namespace Modules\Core\Services;

use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Core\Models\ArchiveFile;
use Modules\Core\Models\ArchiveFolder;
use Modules\Core\Models\Company;
use Modules\Core\Models\Product;
use Modules\FixedAssets\Models\FixedAsset;
use Throwable;

class FileManagerMoveService
{
    public function __construct(
        private readonly ArchiveFolderService $folders,
        private readonly ArchiveAuditLogger $auditLogger,
        private readonly CrudAuditService $crudAudit,
        private readonly OperatingCompanyContextService $companyContext,
    ) {}

    /**
     * @return list<array{id: string, text: string}>
     */
    public function folderOptions(?Request $request = null, ?string $search = null): array
    {
        $company = $this->companyContext->currentCompany($request);
        $root = $this->folders->generalRoot();
        $search = trim((string) $search);

        /** @var Collection<int, ArchiveFolder> $folders */
        $folders = ArchiveFolder::query()
            ->whereKeyNot($root->getKey())
            ->whereNotNull('parent_id')
            ->where(function ($query) use ($company): void {
                $query
                    ->where(function ($query): void {
                        $query->whereNull('attachable_type')->whereNull('attachable_id');
                    })
                    ->when($company instanceof Company, function ($query) use ($company): void {
                        $query->orWhere(function ($query) use ($company): void {
                            $query
                                ->where('attachable_type', $company->getMorphClass())
                                ->where('attachable_id', $company->getKey());
                        });
                    });
            })
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('doc_num', 'like', "%{$search}%")
                        ->orWhere('path_cache', 'like', "%{$search}%");
                });
            })
            ->orderBy('path_cache')
            ->limit(250)
            ->get();

        return [
            [
                'id' => '',
                'text' => __('archive.file_manager'),
            ],
            ...$folders
                ->map(fn (ArchiveFolder $folder): array => [
                    'id' => (string) $folder->doc_num,
                    'text' => $folder->displayPath(),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array{moved: int, skipped: int}
     */
    public function moveSingle(string $itemType, string $itemDocNum, ?string $destinationFolderDocNum, ?Request $request = null): array
    {
        return $this->moveItems([
            [
                'item_type' => $itemType,
                'item_doc_num' => $itemDocNum,
            ],
        ], $destinationFolderDocNum, $request);
    }

    /**
     * @param  list<array{item_type: string, item_doc_num: string}>  $items
     * @return array{moved: int, skipped: int}
     */
    public function moveItems(array $items, ?string $destinationFolderDocNum, ?Request $request = null): array
    {
        $items = $this->normalizeItems($items);

        if ($items === []) {
            throw new DomainException(__('archive.select_one_item'));
        }

        $storageMoves = [];

        try {
            $movedItems = DB::transaction(function () use ($items, $destinationFolderDocNum, $request, &$storageMoves): array {
                $company = $this->companyContext->currentCompany($request);
                $destination = $this->destinationFolder($destinationFolderDocNum, $company);
                $resolvedItems = $this->resolveItems($items);
                $operations = $this->validatedOperations($resolvedItems, $destination, $company);
                $movedItems = [];

                foreach ($operations as $operation) {
                    $item = $operation['item'];

                    if ($operation['skip']) {
                        continue;
                    }

                    if ($item instanceof ArchiveFile) {
                        $movedItems[] = $this->moveFile($item, $destination, $operation['old_folder'], $storageMoves);

                        continue;
                    }

                    if ($item instanceof ArchiveFolder) {
                        $movedItems[] = $this->moveFolder($item, $destination, $operation['old_folder']);
                    }
                }

                return $movedItems;
            });
        } catch (Throwable $exception) {
            $this->rollbackStorageMoves($storageMoves);

            throw $exception;
        }

        foreach ($movedItems as $movedItem) {
            $item = $movedItem['item'];

            if ($item instanceof ArchiveFile) {
                $this->auditLogger->fileMoved($item, $movedItem['old_folder'], $movedItem['destination'], $request);
            }

            if ($item instanceof ArchiveFolder) {
                $this->auditLogger->folderMoved($item, $movedItem['old_folder'], $movedItem['destination'], $request);
            }
        }

        return [
            'moved' => count($movedItems),
            'skipped' => count($items) - count($movedItems),
        ];
    }

    /**
     * @param  list<array{item_type: string, item_doc_num: string}>  $items
     * @return list<array{item_type: string, item_doc_num: string}>
     */
    private function normalizeItems(array $items): array
    {
        $normalized = [];

        foreach ($items as $item) {
            $itemType = trim((string) ($item['item_type'] ?? ''));
            $itemDocNum = trim((string) ($item['item_doc_num'] ?? ''));

            if (! in_array($itemType, ['file', 'folder'], true) || $itemDocNum === '') {
                continue;
            }

            $normalized["{$itemType}:{$itemDocNum}"] = [
                'item_type' => $itemType,
                'item_doc_num' => $itemDocNum,
            ];
        }

        return array_values($normalized);
    }

    private function destinationFolder(?string $destinationFolderDocNum, ?Company $company): ArchiveFolder
    {
        $destinationFolderDocNum = trim((string) $destinationFolderDocNum);

        $folder = $destinationFolderDocNum === ''
            ? $this->folders->generalRoot()
            : ArchiveFolder::query()->where('doc_num', $destinationFolderDocNum)->firstOrFail();

        $this->assertFolderBelongsToCurrentCompanyScope($folder, $company);

        return $folder;
    }

    /**
     * @param  list<array{item_type: string, item_doc_num: string}>  $items
     * @return list<ArchiveFile|ArchiveFolder>
     */
    private function resolveItems(array $items): array
    {
        $files = collect($items)
            ->where('item_type', 'file')
            ->pluck('item_doc_num')
            ->values()
            ->all();
        $folders = collect($items)
            ->where('item_type', 'folder')
            ->pluck('item_doc_num')
            ->values()
            ->all();

        /** @var Collection<int, ArchiveFile> $resolvedFiles */
        $resolvedFiles = ArchiveFile::query()
            ->with(['folder', 'attachable'])
            ->whereIn('doc_num', $files)
            ->lockForUpdate()
            ->get();

        /** @var Collection<int, ArchiveFolder> $resolvedFolders */
        $resolvedFolders = ArchiveFolder::query()
            ->with(['parent', 'attachable'])
            ->whereIn('doc_num', $folders)
            ->lockForUpdate()
            ->get();

        if ($resolvedFiles->count() !== count($files) || $resolvedFolders->count() !== count($folders)) {
            throw new DomainException(__('archive.selected_items_invalid'));
        }

        $resolved = collect($items)
            ->map(function (array $item) use ($resolvedFiles, $resolvedFolders): ArchiveFile|ArchiveFolder|null {
                if ($item['item_type'] === 'file') {
                    return $resolvedFiles->firstWhere('doc_num', $item['item_doc_num']);
                }

                return $resolvedFolders->firstWhere('doc_num', $item['item_doc_num']);
            })
            ->filter()
            ->values()
            ->all();

        if (count($resolved) !== count($items)) {
            throw new DomainException(__('archive.selected_items_invalid'));
        }

        return $resolved;
    }

    /**
     * @param  list<ArchiveFile|ArchiveFolder>  $items
     * @return list<array{item: ArchiveFile|ArchiveFolder, old_folder: ArchiveFolder|null, skip: bool}>
     */
    private function validatedOperations(array $items, ArchiveFolder $destination, ?Company $company): array
    {
        $operations = [];

        foreach ($items as $item) {
            $this->assertItemBelongsToCurrentCompanyScope($item, $company);
            $this->assertSameDestinationContext($item, $destination);

            if ($item instanceof ArchiveFile) {
                $this->assertNoFileConflict($item, $destination);

                $operations[] = [
                    'item' => $item,
                    'old_folder' => $item->folder,
                    'skip' => (int) $item->archive_folder_id === (int) $destination->getKey(),
                ];

                continue;
            }

            $this->assertMovableFolder($item, $destination);
            $this->assertNoFolderConflict($item, $destination);

            $operations[] = [
                'item' => $item,
                'old_folder' => $item->parent,
                'skip' => (int) $item->parent_id === (int) $destination->getKey(),
            ];
        }

        return $operations;
    }

    private function moveFile(ArchiveFile $file, ArchiveFolder $destination, ?ArchiveFolder $oldFolder, array &$storageMoves): array
    {
        $oldPath = (string) $file->path;
        $newPath = $this->filePathForDestination($file, $destination);

        if ($newPath !== $oldPath) {
            if (! Storage::disk($file->disk)->exists($oldPath)) {
                throw new DomainException(__('archive.selected_items_invalid'));
            }

            if (Storage::disk($file->disk)->exists($newPath)) {
                throw new DomainException(__('archive.destination_item_conflict'));
            }

            Storage::disk($file->disk)->move($oldPath, $newPath);
            $storageMoves[] = [
                'disk' => (string) $file->disk,
                'from' => $oldPath,
                'to' => $newPath,
            ];
        }

        $this->crudAudit->saveUpdate($file, [
            'archive_folder_id' => $destination->getKey(),
            'folder_doc_num' => $destination->doc_num,
            'path' => $newPath,
        ]);

        $this->updateDirectImagePathReferences($oldPath, $newPath);

        return [
            'item' => $file->refresh()->loadMissing(['folder', 'attachable']),
            'old_folder' => $oldFolder,
            'destination' => $destination,
        ];
    }

    private function moveFolder(ArchiveFolder $folder, ArchiveFolder $destination, ?ArchiveFolder $oldFolder): array
    {
        $this->crudAudit->saveUpdate($folder, [
            'parent_id' => $destination->getKey(),
            'path_cache' => $this->pathFor($folder, $destination),
        ]);

        $folder = $folder->refresh()->loadMissing(['parent', 'attachable']);
        $this->refreshDescendantPaths($folder);

        return [
            'item' => $folder,
            'old_folder' => $oldFolder,
            'destination' => $destination,
        ];
    }

    private function assertFolderBelongsToCurrentCompanyScope(ArchiveFolder $folder, ?Company $company): void
    {
        if (! $company instanceof Company) {
            return;
        }

        $folderCompanyId = $this->companyId($folder);

        abort_if($folderCompanyId !== null && $folderCompanyId !== (int) $company->getKey(), 404);
    }

    private function assertItemBelongsToCurrentCompanyScope(ArchiveFile|ArchiveFolder $item, ?Company $company): void
    {
        if (! $company instanceof Company) {
            return;
        }

        $itemCompanyId = $this->companyId($item);

        abort_if($itemCompanyId !== null && $itemCompanyId !== (int) $company->getKey(), 404);
    }

    private function assertSameDestinationContext(ArchiveFile|ArchiveFolder $item, ArchiveFolder $destination): void
    {
        if ($this->isGeneralRoot($destination)) {
            return;
        }

        if ($this->companyId($item) !== $this->companyId($destination)) {
            throw new DomainException(__('archive.invalid_destination_folder'));
        }
    }

    private function assertMovableFolder(ArchiveFolder $folder, ArchiveFolder $destination): void
    {
        if ($folder->parent_id === null) {
            throw new DomainException(__('archive.protected_folder_move_not_allowed'));
        }

        if ($folder->is($destination) || $this->isDescendantOf($destination, $folder)) {
            throw new DomainException(__('archive.invalid_folder_move'));
        }
    }

    private function assertNoFileConflict(ArchiveFile $file, ArchiveFolder $destination): void
    {
        if ((int) $file->archive_folder_id === (int) $destination->getKey()) {
            return;
        }

        $name = mb_strtolower(trim((string) $file->original_name));

        if ($name !== '' && ArchiveFile::query()
            ->where('archive_folder_id', $destination->getKey())
            ->whereRaw('LOWER(original_name) = ?', [$name])
            ->whereKeyNot($file->getKey())
            ->exists()) {
            throw new DomainException(__('archive.destination_item_conflict'));
        }
    }

    private function assertNoFolderConflict(ArchiveFolder $folder, ArchiveFolder $destination): void
    {
        if ((int) $folder->parent_id === (int) $destination->getKey()) {
            return;
        }

        $name = mb_strtolower(trim((string) $folder->name));

        if ($name !== '' && ArchiveFolder::query()
            ->where('parent_id', $destination->getKey())
            ->where('module', $folder->module)
            ->where('record_type', $folder->record_type)
            ->where('record_doc_num', $folder->record_doc_num)
            ->whereRaw('LOWER(name) = ?', [$name])
            ->whereKeyNot($folder->getKey())
            ->exists()) {
            throw new DomainException(__('archive.destination_item_conflict'));
        }
    }

    private function filePathForDestination(ArchiveFile $file, ArchiveFolder $destination): string
    {
        $oldPath = trim((string) $file->path);
        $storedName = trim((string) $file->stored_name) ?: basename($oldPath);
        $destinationSegment = trim((string) $destination->doc_num) ?: 'general';
        $parts = explode('/', $oldPath);

        if (count($parts) >= 4 && $parts[0] === 'archive') {
            return 'archive/'.$destinationSegment.'/'.implode('/', array_slice($parts, 2));
        }

        return 'archive/'.$destinationSegment.'/'.now()->format('Y/m').'/'.$storedName;
    }

    private function updateDirectImagePathReferences(string $oldPath, string $newPath): void
    {
        if ($oldPath === $newPath) {
            return;
        }

        Product::query()
            ->where('image_path', $oldPath)
            ->update(['image_path' => $newPath]);

        FixedAsset::query()
            ->where('image_path', $oldPath)
            ->update(['image_path' => $newPath]);
    }

    private function refreshDescendantPaths(ArchiveFolder $folder): void
    {
        /** @var Collection<int, ArchiveFolder> $children */
        $children = ArchiveFolder::query()
            ->where('parent_id', $folder->getKey())
            ->orderBy('name')
            ->get();

        foreach ($children as $child) {
            $child->timestamps = false;
            $child->forceFill([
                'path_cache' => $this->pathFor($child, $folder),
            ])->save();

            $this->refreshDescendantPaths($child->refresh());
        }
    }

    private function pathFor(ArchiveFolder $folder, ArchiveFolder $parent): string
    {
        return trim(($parent->path_cache ? $parent->path_cache.' / ' : '').$folder->name);
    }

    private function isDescendantOf(ArchiveFolder $folder, ArchiveFolder $ancestor): bool
    {
        $current = $folder;

        while ($current->parent_id !== null) {
            if ((int) $current->parent_id === (int) $ancestor->getKey()) {
                return true;
            }

            $current = ArchiveFolder::query()
                ->select(['id', 'parent_id'])
                ->whereKey($current->parent_id)
                ->first();

            if (! $current instanceof ArchiveFolder) {
                return false;
            }
        }

        return false;
    }

    private function isGeneralRoot(ArchiveFolder $folder): bool
    {
        return $folder->parent_id === null
            && $folder->record_type === null
            && $folder->attachable_type === null
            && $folder->attachable_id === null;
    }

    private function companyId(Model $item): ?int
    {
        if ((string) $item->getAttribute('attachable_type') !== (new Company)->getMorphClass()) {
            return null;
        }

        $companyId = $item->getAttribute('attachable_id');

        return $companyId === null ? null : (int) $companyId;
    }

    /**
     * @param  list<array{disk: string, from: string, to: string}>  $storageMoves
     */
    private function rollbackStorageMoves(array $storageMoves): void
    {
        foreach (array_reverse($storageMoves) as $move) {
            try {
                if (Storage::disk($move['disk'])->exists($move['to']) && ! Storage::disk($move['disk'])->exists($move['from'])) {
                    Storage::disk($move['disk'])->move($move['to'], $move['from']);
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }
}
