<?php

namespace Modules\Core\Services;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Modules\Core\Models\ArchiveFile;
use Modules\Core\Models\ArchiveFolder;
use Modules\Core\Models\ArchivePublicLink;
use Modules\Core\Models\Company;
use Throwable;

class ArchiveAuditLogger
{
    public function __construct(
        private readonly ActivityLogger $activityLogger,
    ) {}

    public function fileUploaded(ArchiveFile $file, ?Request $request = null, ?Company $company = null): void
    {
        $this->log($request, 'archive.file.upload', $this->fileProperties($file, $company, includeUploader: true));
    }

    /**
     * @param  list<string>  $changedFields
     */
    public function fileUpdated(ArchiveFile $file, string $oldOriginalName, array $changedFields, ?Request $request = null, ?Company $company = null): void
    {
        $properties = array_merge($this->fileProperties($file, $company), [
            'old_original_name' => $oldOriginalName,
            'new_original_name' => $file->original_name,
            'changed_fields' => $changedFields,
        ]);

        $action = $changedFields === ['original_name']
            ? 'archive.file.rename'
            : 'archive.file.update';

        $this->log($request, $action, $properties);
    }

    public function fileDeleted(ArchiveFile $file, ?Request $request = null, ?Company $company = null): void
    {
        $this->log($request, 'archive.file.delete', $this->fileProperties($file, $company));
    }

    public function fileRestored(ArchiveFile $file, ?Request $request = null, ?Company $company = null): void
    {
        $this->log($request, 'archive.file.restore', $this->fileProperties($file, $company));
    }

    public function fileMoved(ArchiveFile $file, ?ArchiveFolder $oldFolder, ArchiveFolder $newFolder, ?Request $request = null, ?Company $company = null): void
    {
        $properties = array_merge($this->fileProperties($file, $company), [
            'old_folder_doc_num' => $oldFolder?->doc_num,
            'old_folder_name' => $oldFolder?->name,
            'new_folder_doc_num' => $newFolder->doc_num,
            'new_folder_name' => $newFolder->name,
        ]);

        $this->log($request, 'archive.file.move', $properties);
    }

    public function fileDownloaded(ArchiveFile $file, ?Request $request = null, ?Company $company = null): void
    {
        if (! (bool) config('archive.audit.log_downloads', true)) {
            return;
        }

        $this->log($request, 'archive.file.download', $this->fileProperties($file, $company));
    }

    public function filePreviewed(ArchiveFile $file, ?Request $request = null, ?Company $company = null): void
    {
        if (! (bool) config('archive.audit.log_previews', false)) {
            return;
        }

        $this->log($request, 'archive.file.preview', $this->fileProperties($file, $company));
    }

    /**
     * @param  EloquentCollection<int, ArchiveFile>  $files
     */
    public function filesBulkDeleted(EloquentCollection $files, ?Request $request = null, ?Company $company = null): void
    {
        $this->log($request, 'archive.files.bulk_delete', $this->bulkFileProperties($files, $company));
    }

    /**
     * @param  EloquentCollection<int, ArchiveFile>  $files
     */
    public function filesBulkRestored(EloquentCollection $files, ?Request $request = null, ?Company $company = null): void
    {
        $this->log($request, 'archive.files.bulk_restore', $this->bulkFileProperties($files, $company));
    }

    /**
     * @param  EloquentCollection<int, ArchiveFile>  $files
     */
    public function filesBulkDownloaded(EloquentCollection $files, int $totalSizeBytes, ?Request $request = null, ?Company $company = null): void
    {
        $properties = array_merge($this->bulkFileProperties($files, $company), [
            'total_size_bytes' => $totalSizeBytes,
        ]);

        $this->log($request, 'archive.files.bulk_download', $properties);
    }

    public function folderCreated(ArchiveFolder $folder, ?Request $request = null): void
    {
        $this->log($request, 'archive.folder.create', $this->folderProperties($folder));
    }

    public function folderRenamed(ArchiveFolder $folder, string $oldName, ?Request $request = null): void
    {
        $properties = array_merge($this->folderProperties($folder), [
            'old_folder_name' => $oldName,
            'new_folder_name' => $folder->name,
        ]);

        $this->log($request, 'archive.folder.rename', $properties);
    }

    public function folderDeleted(ArchiveFolder $folder, ?Request $request = null): void
    {
        $this->log($request, 'archive.folder.delete', $this->folderProperties($folder));
    }

    public function folderRestored(ArchiveFolder $folder, ?Request $request = null): void
    {
        $this->log($request, 'archive.folder.restore', $this->folderProperties($folder));
    }

    public function folderMoved(ArchiveFolder $folder, ?ArchiveFolder $oldFolder, ArchiveFolder $newFolder, ?Request $request = null): void
    {
        $properties = array_merge($this->folderProperties($folder), [
            'old_parent_folder_doc_num' => $oldFolder?->doc_num,
            'old_parent_folder_name' => $oldFolder?->name,
            'new_parent_folder_doc_num' => $newFolder->doc_num,
            'new_parent_folder_name' => $newFolder->name,
        ]);

        $this->log($request, 'archive.folder.move', $properties);
    }

    public function folderDeleteBlocked(ArchiveFolder $folder, string $reason, int $filesCount, int $subfoldersCount, ?Request $request = null): void
    {
        $properties = array_merge($this->folderProperties($folder), [
            'reason' => $reason,
            'files_count' => $filesCount,
            'subfolders_count' => $subfoldersCount,
        ]);

        $this->log($request, 'archive.folder.delete_blocked', $properties, 'blocked');
    }

    public function publicLinkCreated(ArchivePublicLink $link, ?Request $request = null): void
    {
        $this->log($request, 'archive.public_link.create', $this->publicLinkProperties($link));
    }

    public function publicLinkRevoked(ArchivePublicLink $link, ?Request $request = null): void
    {
        $this->log($request, 'archive.public_link.revoke', $this->publicLinkProperties($link));
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function log(?Request $request, string $action, array $properties, string $status = 'success'): void
    {
        try {
            $this->activityLogger->log($request ?: request(), 'core', $action, $status, [
                'properties_only' => true,
                'properties' => $properties,
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function fileProperties(ArchiveFile $file, ?Company $company = null, bool $includeUploader = false): array
    {
        $file->loadMissing(['folder', 'uploadedBy', 'attachable']);
        $company = $company ?: $this->companyFromFile($file);

        $properties = [
            'file_doc_num' => $file->doc_num,
            'original_name' => $file->original_name,
            'extension' => $file->extension,
            'mime_type' => $file->mime_type,
            'size_bytes' => $file->size_bytes,
            'folder_doc_num' => $file->folder?->doc_num ?? $file->folder_doc_num,
            'folder_name' => $file->folder?->name,
            'module' => $file->module,
            'record_type' => $file->record_type,
            'record_doc_num' => $file->record_doc_num,
            'company_doc_num' => $company?->doc_num,
            'company_name' => $company?->name,
        ];

        if ($includeUploader) {
            $properties['uploaded_by_name'] = $file->uploadedBy?->name;
        }

        return $properties;
    }

    /**
     * @param  EloquentCollection<int, ArchiveFile>  $files
     * @return array<string, mixed>
     */
    private function bulkFileProperties(EloquentCollection $files, ?Company $company = null): array
    {
        $files->loadMissing(['folder', 'attachable']);
        $companyDocNum = $company?->doc_num ?: $this->commonValue($files, fn (ArchiveFile $file): ?string => $this->companyFromFile($file)?->doc_num);
        $companyName = $company?->name ?: $this->commonValue($files, fn (ArchiveFile $file): ?string => $this->companyFromFile($file)?->name);

        return [
            'file_doc_nums' => $files->pluck('doc_num')->values()->all(),
            'count' => $files->count(),
            'folder_doc_num' => $this->commonValue($files, fn (ArchiveFile $file): ?string => $file->folder?->doc_num ?? $file->folder_doc_num),
            'module' => $this->commonValue($files, fn (ArchiveFile $file): ?string => $file->module),
            'record_type' => $this->commonValue($files, fn (ArchiveFile $file): ?string => $file->record_type),
            'record_doc_num' => $this->commonValue($files, fn (ArchiveFile $file): ?string => $file->record_doc_num),
            'company_doc_num' => $companyDocNum,
            'company_name' => $companyName,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function folderProperties(ArchiveFolder $folder): array
    {
        $folder->loadMissing(['parent', 'attachable']);
        $company = $this->companyFromFolder($folder);

        return [
            'folder_doc_num' => $folder->doc_num,
            'folder_name' => $folder->name,
            'parent_folder_doc_num' => $folder->parent?->doc_num,
            'parent_folder_name' => $folder->parent?->name,
            'module' => $folder->module,
            'record_type' => $folder->record_type,
            'record_doc_num' => $folder->record_doc_num,
            'company_doc_num' => $company?->doc_num,
            'company_name' => $company?->name,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function publicLinkProperties(ArchivePublicLink $link): array
    {
        $link->loadMissing('linkable');
        $linkable = $link->linkable;
        $company = null;
        $properties = [
            'item_type' => $link->item_type,
            'item_doc_num' => $link->linkable_doc_num,
            'allow_preview' => $link->allow_preview,
            'allow_download' => $link->allow_download,
            'revoked' => $link->revoked_at !== null,
        ];

        if ($linkable instanceof ArchiveFile) {
            $linkable->loadMissing(['folder', 'attachable']);
            $company = $this->companyFromFile($linkable);

            return array_merge($properties, [
                'file_doc_num' => $linkable->doc_num,
                'original_name' => $linkable->original_name,
                'folder_doc_num' => $linkable->folder?->doc_num ?? $linkable->folder_doc_num,
                'folder_name' => $linkable->folder?->name,
                'company_doc_num' => $company?->doc_num,
                'company_name' => $company?->name,
            ]);
        }

        if ($linkable instanceof ArchiveFolder) {
            $linkable->loadMissing(['parent', 'attachable']);
            $company = $this->companyFromFolder($linkable);

            return array_merge($properties, [
                'folder_doc_num' => $linkable->doc_num,
                'folder_name' => $linkable->name,
                'parent_folder_doc_num' => $linkable->parent?->doc_num,
                'parent_folder_name' => $linkable->parent?->name,
                'company_doc_num' => $company?->doc_num,
                'company_name' => $company?->name,
            ]);
        }

        return $properties;
    }

    /**
     * @param  EloquentCollection<int, ArchiveFile>  $files
     */
    private function commonValue(EloquentCollection $files, callable $callback): mixed
    {
        $values = $files
            ->map($callback)
            ->filter(fn (mixed $value): bool => $value !== null && $value !== '')
            ->unique()
            ->values();

        return $values->count() === 1 ? $values->first() : null;
    }

    private function companyFromFile(ArchiveFile $file): ?Company
    {
        $attachable = $file->attachable;

        return $attachable instanceof Company ? $attachable : null;
    }

    private function companyFromFolder(ArchiveFolder $folder): ?Company
    {
        $attachable = $folder->attachable;

        return $attachable instanceof Company ? $attachable : null;
    }
}
