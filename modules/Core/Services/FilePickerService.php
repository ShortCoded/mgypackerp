<?php

namespace Modules\Core\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Modules\Core\Models\ArchiveFile;
use Modules\Core\Models\ArchiveFolder;
use Modules\Core\Models\Company;

class FilePickerService
{
    public const AcceptImage = 'image';

    public const AcceptDocument = 'document';

    public function __construct(
        private readonly ArchiveFolderService $archiveFolders,
        private readonly OperatingCompanyContextService $companyContext,
    ) {}

    public function currentCompany(?Request $request = null): Company
    {
        $company = $this->companyContext->currentCompany($request);
        abort_unless($company instanceof Company, 409, __('operating_context.messages.required'));

        return $company;
    }

    public function folderFromPublicId(?string $publicId = null, ?Request $request = null): ArchiveFolder
    {
        $company = $this->currentCompany($request);

        $publicId = trim((string) $publicId);

        if ($publicId === '') {
            $root = $this->archiveFolders->generalRoot();
            abort_if($this->folderHiddenFromPicker($root), 404);

            return $root;
        }

        $folder = $this->accessibleFolderQuery($company)
            ->where('doc_num', $publicId)
            ->firstOrFail();

        abort_if($this->folderHiddenFromPicker($folder), 404);

        return $folder;
    }

    /**
     * @return array<string, mixed>
     */
    public function folderContents(ArchiveFolder $folder, Company $company, string $accept = self::AcceptImage, ?string $search = null): array
    {
        $folder->loadMissing('attachable');
        abort_if($this->folderHiddenFromPicker($folder), 404);

        $search = trim((string) $search);
        $folders = $this->accessibleFolderQuery($company)
            ->where(function (Builder $query) use ($folder): void {
                $query->where('parent_id', $folder->getKey());

                if ($this->isGeneralRoot($folder)) {
                    $query->orWhere(function (Builder $query) use ($folder): void {
                        $query
                            ->whereNull('parent_id')
                            ->whereKeyNot($folder->getKey());
                    });
                }
            })
            ->where('hidden_from_picker', false)
            ->when($search !== '', fn (Builder $query): Builder => $query->where(function (Builder $query) use ($search): void {
                $query
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('doc_num', 'like', "%{$search}%");
            }))
            ->orderBy('name')
            ->get();

        $files = $this->companyFileQuery($company)
            ->where('archive_folder_id', $folder->getKey())
            ->where('hidden_from_picker', false)
            ->when($this->acceptsImages($accept), fn (Builder $query): Builder => $this->applyImageFilter($query))
            ->when($this->acceptsDocuments($accept), fn (Builder $query): Builder => $this->applyDocumentFilter($query))
            ->when($search !== '', fn (Builder $query): Builder => $query->where(function (Builder $query) use ($search): void {
                $query
                    ->where('original_name', 'like', "%{$search}%")
                    ->orWhere('doc_num', 'like', "%{$search}%")
                    ->orWhere('mime_type', 'like', "%{$search}%")
                    ->orWhere('extension', 'like', "%{$search}%")
                    ->orWhere('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            }))
            ->orderBy('original_name')
            ->get()
            ->filter(fn (ArchiveFile $file): bool => $this->isAvailableFile($file))
            ->values();

        return [
            'folder' => $this->folderResource($folder, root: $this->isGeneralRoot($folder)),
            'breadcrumbs' => $this->breadcrumbResources($folder),
            'folders' => $folders->map(fn (ArchiveFolder $child): array => $this->folderResource($child))->values()->all(),
            'files' => $files->map(fn (ArchiveFile $file): array => $this->fileResource($file))->values()->all(),
        ];
    }

    public function fileForCompany(string $publicId, int $companyId): ?ArchiveFile
    {
        $publicId = trim($publicId);

        if ($publicId === '') {
            return null;
        }

        return ArchiveFile::query()
            ->where('doc_num', $publicId)
            ->where('attachable_type', (new Company)->getMorphClass())
            ->where('attachable_id', $companyId)
            ->first();
    }

    public function selectableFileByPublicId(string $publicId, int $companyId, string $accept = self::AcceptImage): ?ArchiveFile
    {
        $file = $this->fileForCompany($publicId, $companyId);

        if (! $file instanceof ArchiveFile) {
            return null;
        }

        if (! $this->isAvailableFile($file)) {
            return null;
        }

        if ($this->fileHiddenFromPicker($file)) {
            return null;
        }

        if ($this->acceptsImages($accept) && ! $this->isImageFile($file)) {
            return null;
        }

        if ($this->acceptsDocuments($accept) && ! $this->isDocumentFile($file)) {
            return null;
        }

        return $file;
    }

    public function isAvailableFile(ArchiveFile $file): bool
    {
        return Storage::disk($file->disk)->exists($file->path);
    }

    public function isImageFile(ArchiveFile $file): bool
    {
        $extension = mb_strtolower((string) $file->extension);
        $mimeType = mb_strtolower((string) $file->mime_type);

        return in_array($mimeType, $this->imageMimeTypes(), true)
            && in_array($extension, $this->imageExtensions(), true);
    }

    public function isDocumentFile(ArchiveFile $file): bool
    {
        $extension = mb_strtolower((string) $file->extension);
        $mimeType = mb_strtolower((string) $file->mime_type);
        $maxSizeBytes = (int) config('archive.uploads.max_file_size_kib', 51200) * 1024;

        return in_array($extension, $this->documentExtensions(), true)
            && in_array($mimeType, $this->documentMimeTypes(), true)
            && (int) $file->size_bytes <= $maxSizeBytes;
    }

    public function fileHiddenFromPicker(ArchiveFile $file): bool
    {
        if ((bool) $file->hidden_from_picker) {
            return true;
        }

        $folder = $file->relationLoaded('folder')
            ? $file->folder
            : ($file->archive_folder_id ? ArchiveFolder::query()->whereKey($file->archive_folder_id)->first() : null);

        return $folder instanceof ArchiveFolder && $this->folderHiddenFromPicker($folder);
    }

    public function folderHiddenFromPicker(ArchiveFolder $folder): bool
    {
        $current = $folder;

        while ($current instanceof ArchiveFolder) {
            if ((bool) $current->hidden_from_picker) {
                return true;
            }

            if ($current->parent_id === null) {
                return false;
            }

            $current = ArchiveFolder::query()
                ->select(['id', 'parent_id', 'hidden_from_picker'])
                ->whereKey($current->parent_id)
                ->first();
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function folderResource(ArchiveFolder $folder, bool $root = false): array
    {
        return [
            'type' => 'folder',
            'public_id' => $root ? '' : $folder->doc_num,
            'name' => $root ? __('archive.file_manager') : $folder->name,
            'doc_num' => $folder->doc_num,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function fileResource(ArchiveFile $file): array
    {
        $url = route('admin.file-manager.files.preview', $file->doc_num);
        $canDelete = (bool) auth()->user()?->can('file_manager.delete');

        return [
            'type' => 'file',
            'public_id' => $file->doc_num,
            'name' => $file->original_name,
            'original_name' => $file->original_name,
            'url' => $url,
            'thumbnail_url' => $this->isImageFile($file) ? $url : null,
            'mime_type' => $file->mime_type,
            'extension' => $file->extension,
            'size' => (int) $file->size_bytes,
            'size_label' => $this->formatBytes((int) $file->size_bytes),
            'can_delete' => $canDelete,
            'delete_url' => $canDelete ? route('admin.file-manager.files.destroy', $file->doc_num) : null,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function breadcrumbResources(ArchiveFolder $folder): array
    {
        return collect($this->archiveFolders->breadcrumb($folder))
            ->values()
            ->map(fn (ArchiveFolder $breadcrumb): array => $this->folderResource($breadcrumb, root: $this->isGeneralRoot($breadcrumb)))
            ->all();
    }

    private function isGeneralRoot(ArchiveFolder $folder): bool
    {
        return $folder->parent_id === null
            && $folder->record_type === null
            && $folder->attachable_type === null
            && $folder->attachable_id === null;
    }

    /**
     * @return Builder<ArchiveFolder>
     */
    private function accessibleFolderQuery(Company $company): Builder
    {
        return ArchiveFolder::query()
            ->where(function (Builder $query) use ($company): void {
                $query
                    ->where(function (Builder $query): void {
                        $query->whereNull('attachable_type')->whereNull('attachable_id');
                    })
                    ->orWhere(function (Builder $query) use ($company): void {
                        $query
                            ->where('attachable_type', $company->getMorphClass())
                            ->where('attachable_id', $company->getKey());
                    });
            });
    }

    /**
     * @return Builder<ArchiveFile>
     */
    private function companyFileQuery(Company $company): Builder
    {
        return ArchiveFile::query()
            ->where('attachable_type', $company->getMorphClass())
            ->where('attachable_id', $company->getKey());
    }

    /**
     * @param  Builder<ArchiveFile>  $query
     * @return Builder<ArchiveFile>
     */
    private function applyImageFilter(Builder $query): Builder
    {
        return $query
            ->whereIn('mime_type', $this->imageMimeTypes())
            ->whereIn('extension', $this->imageExtensions());
    }

    /**
     * @param  Builder<ArchiveFile>  $query
     * @return Builder<ArchiveFile>
     */
    private function applyDocumentFilter(Builder $query): Builder
    {
        return $query
            ->whereIn('extension', $this->documentExtensions())
            ->whereIn('mime_type', $this->documentMimeTypes())
            ->where('size_bytes', '<=', (int) config('archive.uploads.max_file_size_kib', 51200) * 1024);
    }

    private function acceptsImages(string $accept): bool
    {
        return trim($accept) === self::AcceptImage;
    }

    private function acceptsDocuments(string $accept): bool
    {
        return trim($accept) === self::AcceptDocument;
    }

    /**
     * @return list<string>
     */
    private function imageExtensions(): array
    {
        return array_values(array_map(
            fn (string $extension): string => mb_strtolower(ltrim($extension, '.')),
            config('archive.logo.allowed_extensions', ['jpg', 'jpeg', 'png', 'webp']),
        ));
    }

    /**
     * @return list<string>
     */
    private function imageMimeTypes(): array
    {
        $configured = config('archive.logo.allowed_mime_types', []);

        if (is_array($configured) && $configured !== []) {
            return collect($configured)
                ->map(fn (mixed $mimeType): string => mb_strtolower(trim((string) $mimeType)))
                ->filter(fn (string $mimeType): bool => $mimeType !== '')
                ->unique()
                ->values()
                ->all();
        }

        $mimeTypes = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            'bmp' => ['image/bmp', 'image/x-ms-bmp'],
        ];

        return collect($this->imageExtensions())
            ->flatMap(function (string $extension) use ($mimeTypes): array {
                $mimeType = $mimeTypes[$extension] ?? null;

                return is_array($mimeType) ? $mimeType : array_filter([$mimeType]);
            })
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function documentExtensions(): array
    {
        return array_values(array_map(
            fn (string $extension): string => mb_strtolower(ltrim($extension, '.')),
            config('archive.documents.allowed_extensions', config('archive.allowed_extensions', [])),
        ));
    }

    /**
     * @return list<string>
     */
    private function documentMimeTypes(): array
    {
        return collect(config('archive.documents.allowed_mime_types', []))
            ->map(fn (mixed $mimeType): string => mb_strtolower(trim((string) $mimeType)))
            ->filter(fn (string $mimeType): bool => $mimeType !== '')
            ->unique()
            ->values()
            ->all();
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        if ($bytes < 1048576) {
            return number_format($bytes / 1024, 1).' KiB';
        }

        return number_format($bytes / 1048576, 1).' MiB';
    }
}
