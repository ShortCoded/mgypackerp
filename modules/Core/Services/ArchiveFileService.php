<?php

namespace Modules\Core\Services;

use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Core\Models\ArchiveFile;
use Modules\Core\Models\ArchiveFolder;
use Modules\Core\Models\Company;

class ArchiveFileService
{
    public function __construct(
        private readonly DocumentNumberService $documentNumberService,
        private readonly ArchiveAuditLogger $auditLogger,
        private readonly CrudAuditService $crudAudit,
        private readonly ArchiveFileUsageService $fileUsages,
        private readonly OperatingCompanyContextService $companyContext,
    ) {}

    /**
     * @param  list<UploadedFile>  $files
     * @return list<ArchiveFile>
     */
    public function upload(array $files, ?Model $attachable = null, ?string $module = null, ?string $recordType = null, ?string $recordDocNum = null, ?string $title = null, ?string $description = null, ?ArchiveFolder $folder = null, ?Request $request = null): array
    {
        $uploaded = DB::transaction(function () use ($files, $attachable, $module, $recordType, $recordDocNum, $title, $description, $folder): array {
            $uploaded = [];

            foreach ($files as $file) {
                $this->ensureNoDuplicateFileName($file, $folder);
                $uploaded[] = $this->storeOne($file, $attachable, $module, $recordType, $recordDocNum, $title, $description, $folder);
            }

            return $uploaded;
        });

        foreach ($uploaded as $file) {
            $this->auditLogger->fileUploaded(
                $file,
                $request,
                $attachable instanceof Company ? $attachable : null,
            );
        }

        return $uploaded;
    }

    public function delete(ArchiveFile $file, ?Request $request = null, bool $log = true): void
    {
        $company = $file->attachable instanceof Company ? $file->attachable : null;

        DB::transaction(function () use ($file): void {
            $lockedFile = ArchiveFile::query()
                ->with('attachable')
                ->whereKey($file->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->fileUsages->assertFileCanBeDeleted($lockedFile);
            $this->crudAudit->softDelete($lockedFile);
        });

        if ($log) {
            $this->auditLogger->fileDeleted($file, $request, $company);
        }
    }

    public function restore(ArchiveFile $file, ?Request $request = null): ArchiveFile
    {
        $restored = DB::transaction(function () use ($file, $request): ArchiveFile {
            $lockedFile = ArchiveFile::withTrashed()
                ->with(['folder', 'attachable'])
                ->whereKey($file->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertFileCanBeRestored($lockedFile, $request);
            $this->crudAudit->restore($lockedFile, auth()->id());

            return $lockedFile->refresh();
        });

        $this->auditLogger->fileRestored($restored, $request, $this->companyFromFile($restored));

        return $restored;
    }

    /**
     * @param  list<string>  $docNums
     */
    public function bulkDelete(array $docNums, ?Request $request = null, ?callable $filter = null, ?Company $company = null): int
    {
        $docNums = array_values(array_unique(array_filter(array_map(
            fn (string $docNum): string => trim($docNum),
            $docNums,
        ))));

        if ($docNums === []) {
            throw new DomainException(__('archive.no_files_selected'));
        }

        /** @var Collection<int, ArchiveFile> $files */
        $files = DB::transaction(function () use ($docNums, $filter): Collection {
            /** @var Collection<int, ArchiveFile> $files */
            $files = ArchiveFile::query()
                ->with(['folder', 'attachable'])
                ->whereIn('doc_num', $docNums)
                ->lockForUpdate()
                ->get();

            if ($files->count() !== count($docNums)) {
                throw new DomainException(__('archive.selected_files_invalid'));
            }

            if ($filter) {
                $files = $files->filter($filter)->values();

                if ($files->count() !== count($docNums)) {
                    throw new DomainException(__('archive.selected_files_invalid'));
                }
            }

            foreach ($files as $file) {
                $this->fileUsages->assertFileCanBeDeleted($file);
                $this->crudAudit->softDelete($file);
            }

            return $files;
        });

        $this->auditLogger->filesBulkDeleted($files, $request, $company);

        return $files->count();
    }

    /**
     * @param  list<string>  $docNums
     */
    public function bulkRestore(array $docNums, ?Request $request = null, ?callable $filter = null, ?Company $company = null): int
    {
        $docNums = array_values(array_unique(array_filter(array_map(
            fn (string $docNum): string => trim($docNum),
            $docNums,
        ))));

        if ($docNums === []) {
            throw new DomainException(__('archive.no_files_selected'));
        }

        /** @var Collection<int, ArchiveFile> $files */
        $files = DB::transaction(function () use ($docNums, $filter, $request): Collection {
            /** @var Collection<int, ArchiveFile> $files */
            $files = ArchiveFile::onlyTrashed()
                ->with(['folder', 'attachable'])
                ->whereIn('doc_num', $docNums)
                ->lockForUpdate()
                ->get();

            if ($files->count() !== count($docNums)) {
                throw new DomainException(__('archive.selected_files_invalid'));
            }

            if ($filter) {
                $files = $files->filter($filter)->values();

                if ($files->count() !== count($docNums)) {
                    throw new DomainException(__('archive.selected_files_invalid'));
                }
            }

            foreach ($files as $file) {
                $this->assertFileCanBeRestored($file, $request);
                $this->crudAudit->restore($file, auth()->id());
                $file->refresh();
            }

            return $files;
        });

        $this->auditLogger->filesBulkRestored($files, $request, $company);

        return $files->count();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateMetadata(ArchiveFile $file, array $attributes, ?Request $request = null): ArchiveFile
    {
        $allowed = collect($attributes)
            ->only(['original_name', 'title', 'description'])
            ->map(fn (mixed $value): ?string => $this->normalizeNullableString(is_string($value) ? $value : null))
            ->all();

        if (array_key_exists('original_name', $allowed) && $allowed['original_name'] !== null) {
            $allowed['original_name'] = mb_substr(str_replace(["\0", '/', '\\'], '', $allowed['original_name']), 0, 255);
        }

        if (($allowed['original_name'] ?? null) === '') {
            unset($allowed['original_name']);
        }

        $oldOriginalName = (string) $file->original_name;

        $file->fill($allowed);
        $changedFields = array_values(array_keys($file->getDirty()));

        if ($changedFields === []) {
            return $file;
        }

        $this->crudAudit->saveUpdate($file);

        $this->auditLogger->fileUpdated($file->refresh(), $oldOriginalName, $changedFields, $request);

        return $file;
    }

    public function updatePickerVisibility(ArchiveFile $file, bool $hiddenFromPicker, ?Request $request = null): ArchiveFile
    {
        if ((bool) $file->hidden_from_picker === $hiddenFromPicker) {
            return $file;
        }

        $oldOriginalName = (string) $file->original_name;

        $this->crudAudit->saveUpdate($file, [
            'hidden_from_picker' => $hiddenFromPicker,
        ]);

        $this->auditLogger->fileUpdated($file->refresh(), $oldOriginalName, ['hidden_from_picker'], $request);

        return $file;
    }

    public function logDownload(ArchiveFile $file, ?Request $request = null, ?Company $company = null): void
    {
        $this->auditLogger->fileDownloaded($file, $request, $company);
    }

    public function logPreview(ArchiveFile $file, ?Request $request = null, ?Company $company = null): void
    {
        $this->auditLogger->filePreviewed($file, $request, $company);
    }

    /**
     * @return Builder<ArchiveFile>
     */
    public function companyFileQuery(Company $company, ?ArchiveFolder $folder = null): Builder
    {
        $query = ArchiveFile::query()
            ->with('uploadedBy:id,name')
            ->forCompany($company);

        if ($folder) {
            $query->where('archive_folder_id', $folder->getKey());
        }

        return $query->latest('created_at');
    }

    private function storeOne(UploadedFile $file, ?Model $attachable, ?string $module, ?string $recordType, ?string $recordDocNum, ?string $title, ?string $description, ?ArchiveFolder $folder): ArchiveFile
    {
        $disk = (string) config('archive.disk', 'local');
        $documentNumber = $this->documentNumberService->next('archive_files', ArchiveFile::class);
        $extension = mb_strtolower($file->getClientOriginalExtension());
        $storedName = $this->storedName($documentNumber['doc_num'], $extension);
        $directory = 'archive/'.($folder?->doc_num ?: 'general').'/'.now()->format('Y/m');
        $path = $file->storeAs($directory, $storedName, $disk);

        $archiveFile = new ArchiveFile([
            'doc_number' => $documentNumber['doc_number'],
            'doc_num' => $documentNumber['doc_num'],
            'archive_folder_id' => $folder?->getKey(),
            'folder_doc_num' => $folder?->doc_num,
            'module' => $module,
            'record_type' => $recordType,
            'record_doc_num' => $recordDocNum,
            'title' => $this->normalizeNullableString($title),
            'description' => $this->normalizeNullableString($description),
            'original_name' => $this->safeOriginalName($file),
            'stored_name' => $storedName,
            'disk' => $disk,
            'path' => $path,
            'mime_type' => $file->getMimeType(),
            'extension' => $extension,
            'size_bytes' => $file->getSize() ?: 0,
            'checksum' => $this->checksum($disk, $path),
            'uploaded_by' => auth()->id(),
        ]);

        if ($attachable instanceof Model) {
            $archiveFile->attachable()->associate($attachable);
        }

        $archiveFile->save();
        $this->crudAudit->clearCreationUpdateAudit($archiveFile);

        return $archiveFile->refresh();
    }

    private function ensureNoDuplicateFileName(UploadedFile $file, ?ArchiveFolder $folder): void
    {
        if (! (bool) config('archive.duplicates.prevent_same_name_in_folder', true) || ! $folder) {
            return;
        }

        $originalName = $this->safeOriginalName($file);

        if (ArchiveFile::query()
            ->where('archive_folder_id', $folder->getKey())
            ->whereRaw('LOWER(original_name) = ?', [mb_strtolower($originalName)])
            ->exists()) {
            throw new DomainException(__('archive.file_already_exists_in_folder'));
        }
    }

    private function assertFileCanBeRestored(ArchiveFile $file, ?Request $request = null): void
    {
        $this->assertFileBelongsToCurrentCompanyScope($file, $request);

        if (! $file->trashed()) {
            throw new DomainException(__('archive.restore_not_allowed'));
        }

        if ($this->hasActiveFileConflict($file)) {
            throw new DomainException(__('archive.restore_conflict'));
        }
    }

    private function assertFileBelongsToCurrentCompanyScope(ArchiveFile $file, ?Request $request = null): void
    {
        $company = $this->companyContext->currentCompany($request);

        if (! $company instanceof Company) {
            return;
        }

        $fileCompanyId = $this->fileCompanyId($file);

        abort_if($fileCompanyId !== null && $fileCompanyId !== (int) $company->getKey(), 404);
    }

    private function hasActiveFileConflict(ArchiveFile $file): bool
    {
        if ($file->doc_num !== null && ArchiveFile::query()
            ->where('doc_num', $file->doc_num)
            ->whereKeyNot($file->getKey())
            ->exists()) {
            return true;
        }

        if ($file->doc_number !== null && ArchiveFile::query()
            ->where('doc_number', $file->doc_number)
            ->whereKeyNot($file->getKey())
            ->exists()) {
            return true;
        }

        $originalName = trim((string) $file->original_name);

        return $originalName !== '' && ArchiveFile::query()
            ->where('archive_folder_id', $file->archive_folder_id)
            ->whereRaw('LOWER(original_name) = ?', [mb_strtolower($originalName)])
            ->whereKeyNot($file->getKey())
            ->exists();
    }

    private function companyFromFile(ArchiveFile $file): ?Company
    {
        $file->loadMissing('attachable');

        return $file->attachable instanceof Company ? $file->attachable : null;
    }

    private function fileCompanyId(ArchiveFile $file): ?int
    {
        if ((string) $file->attachable_type !== (new Company)->getMorphClass()) {
            return null;
        }

        return $file->attachable_id === null ? null : (int) $file->attachable_id;
    }

    private function storedName(string $docNum, string $extension): string
    {
        $name = Str::slug($docNum).'-'.Str::uuid()->toString();

        return $extension === '' ? $name : "{$name}.{$extension}";
    }

    private function safeOriginalName(UploadedFile $file): string
    {
        $name = trim(str_replace(["\0", '/', '\\'], '', $file->getClientOriginalName()));

        return $name === '' ? __('archive.unknown_file') : mb_substr($name, 0, 255);
    }

    private function checksum(string $disk, string $path): ?string
    {
        try {
            $stream = Storage::disk($disk)->readStream($path);

            if (! is_resource($stream)) {
                return null;
            }

            $context = hash_init('sha256');
            hash_update_stream($context, $stream);
            fclose($stream);

            return hash_final($context);
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalizeNullableString(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
