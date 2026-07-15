<?php

namespace Modules\Core\Http\Controllers;

use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Modules\Core\DataTables\FileManagerItemsDataTable;
use Modules\Core\Http\Requests\BulkArchiveDeleteRequest;
use Modules\Core\Http\Requests\BulkArchiveDownloadRequest;
use Modules\Core\Http\Requests\BulkArchiveRestoreRequest;
use Modules\Core\Http\Requests\StoreArchiveFilesRequest;
use Modules\Core\Http\Requests\StoreArchiveFolderRequest;
use Modules\Core\Http\Requests\StoreFilePickerUploadRequest;
use Modules\Core\Http\Requests\UpdateArchiveDocumentNumberSettingsRequest;
use Modules\Core\Http\Requests\UpdateArchiveFolderRequest;
use Modules\Core\Models\ArchiveFile;
use Modules\Core\Models\ArchiveFolder;
use Modules\Core\Models\Company;
use Modules\Core\Services\ActivityLogger;
use Modules\Core\Services\ArchiveDocumentNumberSettingsService;
use Modules\Core\Services\ArchiveFileService;
use Modules\Core\Services\ArchiveFolderService;
use Modules\Core\Services\ArchivePublicLinkService;
use Modules\Core\Services\BreadcrumbService;
use Modules\Core\Services\BulkArchiveDownloadService;
use Modules\Core\Services\FileManagerMoveService;
use Modules\Core\Services\FilePickerService;
use Modules\Core\Services\OperatingCompanyContextService;
use Throwable;

class FileManagerController extends Controller
{
    public function __construct(
        private readonly ArchiveFileService $archiveFiles,
        private readonly ArchiveFolderService $archiveFolders,
        private readonly ArchivePublicLinkService $publicLinks,
        private readonly BulkArchiveDownloadService $bulkDownloads,
        private readonly BreadcrumbService $breadcrumbs,
        private readonly ActivityLogger $activityLogger,
        private readonly FilePickerService $filePicker,
        private readonly FileManagerMoveService $moveService,
    ) {}

    public function index(): View
    {
        return $this->folderView($this->archiveFolders->generalRoot());
    }

    public function showFolder(ArchiveFolder $folder): View
    {
        return $this->folderView($folder);
    }

    public function data(Request $request, FileManagerItemsDataTable $dataTable): JsonResponse
    {
        return $dataTable->json($request);
    }

    public function pickerItems(Request $request): JsonResponse
    {
        $company = $this->filePicker->currentCompany($request);
        $folder = $this->filePicker->folderFromPublicId($request->string('folder')->toString(), $request);
        $contents = $this->filePicker->folderContents(
            $folder,
            $company,
            $request->string('accept')->trim()->toString() ?: FilePickerService::AcceptImage,
            $request->string('q')->toString(),
        );

        return response()->json([
            'success' => true,
            'data' => [
                ...$contents,
                'permissions' => [
                    'upload' => (bool) $request->user()?->can('file_manager.upload'),
                    'create_folder' => (bool) $request->user()?->can('file_manager.folders.create'),
                    'delete' => (bool) $request->user()?->can('file_manager.delete'),
                ],
            ],
        ]);
    }

    public function pickerUpload(StoreFilePickerUploadRequest $request): JsonResponse
    {
        $company = $this->filePicker->currentCompany($request);
        $folder = $this->filePicker->folderFromPublicId((string) $request->validated('folder'), $request);

        try {
            $files = $this->archiveFiles->upload(
                files: [$request->uploadedFile()],
                attachable: $company,
                module: 'core',
                recordType: 'company',
                recordDocNum: $company->doc_num,
                folder: $folder,
                request: $request,
            );
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        }

        /** @var ArchiveFile $file */
        $file = $files[0];

        return response()->json([
            'success' => true,
            'message' => $request->validated('accept') === FilePickerService::AcceptDocument
                ? __('archive.picker.file_uploaded')
                : __('archive.picker.image_uploaded'),
            'data' => [
                'file' => $this->filePicker->fileResource($file),
            ],
        ]);
    }

    public function pickerStoreFolder(StoreArchiveFolderRequest $request): JsonResponse
    {
        $company = $this->filePicker->currentCompany($request);
        $parent = $this->filePicker->folderFromPublicId($request->string('parent_folder')->toString(), $request);

        try {
            $folder = $this->archiveFolders->createForCompanyScope($request->validated('name'), $parent, $company, $request);
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        }

        return response()->json([
            'success' => true,
            'message' => __('archive.picker.folder_created'),
            'data' => [
                'folder' => $this->filePicker->folderResource($folder),
            ],
        ]);
    }

    public function pickerFile(Request $request, ArchiveFile $file): JsonResponse
    {
        $companyId = app(OperatingCompanyContextService::class)->requireCompanyId($request);
        $selected = $this->filePicker->selectableFileByPublicId(
            (string) $file->doc_num,
            $companyId,
            $request->string('accept')->trim()->toString() ?: FilePickerService::AcceptImage,
        );

        abort_unless($selected instanceof ArchiveFile, 404);

        return response()->json([
            'success' => true,
            'data' => [
                'file' => $this->filePicker->fileResource($selected),
            ],
        ]);
    }

    public function folderOptions(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'options' => $this->moveService->folderOptions($request, $request->string('q')->toString()),
            ],
        ]);
    }

    public function move(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'item_type' => ['required', Rule::in(['file', 'folder'])],
            'item_doc_num' => ['required', 'string', 'max:255'],
            'destination_folder' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $result = $this->moveService->moveSingle(
                (string) $validated['item_type'],
                (string) $validated['item_doc_num'],
                $validated['destination_folder'] ?? null,
                $request,
            );
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        }

        return response()->json([
            'success' => true,
            'message' => $result['moved'] > 0
                ? __('archive.item_moved_successfully')
                : __('archive.item_already_in_destination'),
        ]);
    }

    public function bulkMove(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_type' => ['required', Rule::in(['file', 'folder'])],
            'items.*.item_doc_num' => ['required', 'string', 'max:255'],
            'destination_folder' => ['nullable', 'string', 'max:255'],
        ], [
            'items.required' => __('archive.select_one_item'),
            'items.min' => __('archive.select_one_item'),
        ]);

        try {
            $result = $this->moveService->moveItems(
                $validated['items'],
                $validated['destination_folder'] ?? null,
                $request,
            );
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        }

        return response()->json([
            'success' => true,
            'message' => $result['moved'] > 0
                ? __('archive.selected_items_moved_successfully')
                : __('archive.items_already_in_destination'),
        ]);
    }

    public function store(StoreArchiveFilesRequest $request): JsonResponse
    {
        $folder = $this->archiveFolders->generalRoot();
        $company = app(OperatingCompanyContextService::class)->currentCompany($request);

        try {
            $this->archiveFiles->upload(
                files: $request->archiveFiles(),
                attachable: $company,
                module: 'core',
                recordType: $company instanceof Company ? 'company' : null,
                recordDocNum: $company instanceof Company ? $company->doc_num : null,
                title: $request->string('title')->toString(),
                description: $request->string('description')->toString(),
                folder: $folder,
                request: $request,
            );
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        }

        return response()->json([
            'success' => true,
            'message' => __('archive.uploaded_successfully'),
        ]);
    }

    public function storeInFolder(StoreArchiveFilesRequest $request, ArchiveFolder $folder): JsonResponse
    {
        $folder->loadMissing('attachable');
        $company = $this->uploadCompanyForFolder($folder, $request);

        try {
            $this->archiveFiles->upload(
                files: $request->archiveFiles(),
                attachable: $company ?: $folder->attachable,
                module: $folder->module,
                recordType: $company instanceof Company ? 'company' : $folder->record_type,
                recordDocNum: $company instanceof Company ? $company->doc_num : $folder->record_doc_num,
                title: $request->string('title')->toString(),
                description: $request->string('description')->toString(),
                folder: $folder,
                request: $request,
            );
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        }

        return response()->json([
            'success' => true,
            'message' => __('archive.uploaded_successfully'),
        ]);
    }

    public function download(Request $request, ArchiveFile $file)
    {
        abort_unless(Storage::disk($file->disk)->exists($file->path), 404);

        $this->archiveFiles->logDownload($file, $request);

        return Storage::disk($file->disk)->download($file->path, $file->original_name);
    }

    public function preview(Request $request, ArchiveFile $file)
    {
        abort_unless($file->isPreviewable(), 415, __('archive.not_previewable'));
        abort_unless(Storage::disk($file->disk)->exists($file->path), 404);

        $this->archiveFiles->logPreview($file, $request);

        return response()->file(Storage::disk($file->disk)->path($file->path), [
            'Content-Type' => $file->mime_type ?: 'application/octet-stream',
            'Content-Disposition' => 'inline; filename="'.addslashes($file->original_name).'"',
        ]);
    }

    public function destroy(Request $request, ArchiveFile $file): JsonResponse
    {
        try {
            $this->archiveFiles->delete($file, $request);
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        }

        return response()->json([
            'success' => true,
            'message' => __('archive.deleted_successfully'),
        ]);
    }

    public function restoreFile(Request $request, string $file): JsonResponse
    {
        $archiveFile = $this->fileForRestore($file);

        try {
            $this->archiveFiles->restore($archiveFile, $request);
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        }

        return response()->json([
            'success' => true,
            'message' => __('archive.restored_successfully'),
        ]);
    }

    public function storeFolder(StoreArchiveFolderRequest $request): JsonResponse
    {
        $parent = $this->folderFromRequest($request) ?: $this->archiveFolders->generalRoot();
        $company = app(OperatingCompanyContextService::class)->currentCompany($request);

        try {
            $folder = $company instanceof Company
                ? $this->archiveFolders->createForCompanyScope($request->validated('name'), $parent, $company, $request)
                : $this->archiveFolders->create($request->validated('name'), $parent, $parent, $request);
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        }

        return response()->json([
            'success' => true,
            'message' => __('archive.folder_created_successfully'),
            'data' => [
                'doc_num' => $folder->doc_num,
                'url' => route('admin.file-manager.folder.show', $folder->doc_num),
            ],
        ]);
    }

    public function updateFolder(UpdateArchiveFolderRequest $request, ArchiveFolder $folder): JsonResponse
    {
        try {
            $folder = $this->archiveFolders->rename($folder, $request->validated('name'), $request);
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        }

        return response()->json([
            'success' => true,
            'message' => __('archive.folder_updated_successfully'),
            'data' => ['doc_num' => $folder->doc_num],
        ]);
    }

    public function updateFolderPickerVisibility(Request $request, ArchiveFolder $folder): JsonResponse
    {
        abort_unless($request->user()?->can('file_manager.update_picker_visibility'), 403);

        $validated = $request->validate([
            'hidden_from_picker' => ['required', 'boolean'],
        ]);

        $folder = $this->archiveFolders->updatePickerVisibility(
            $folder,
            (bool) $validated['hidden_from_picker'],
        );

        return $this->pickerVisibilityResponse($folder);
    }

    public function destroyFolder(Request $request, ArchiveFolder $folder): JsonResponse
    {
        abort_unless($request->user()?->can('file_manager.folders.delete'), 403);

        try {
            $this->archiveFolders->delete($folder, $request);
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        }

        return response()->json([
            'success' => true,
            'message' => __('archive.folder_deleted_successfully'),
        ]);
    }

    public function restoreFolder(Request $request, string $folder): JsonResponse
    {
        $archiveFolder = $this->folderForRestore($folder);

        try {
            $this->archiveFolders->restore($archiveFolder, $request);
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        }

        return response()->json([
            'success' => true,
            'message' => __('archive.folder_restored_successfully'),
        ]);
    }

    public function updateFilePickerVisibility(Request $request, ArchiveFile $file): JsonResponse
    {
        abort_unless($request->user()?->can('file_manager.update_picker_visibility'), 403);

        $validated = $request->validate([
            'hidden_from_picker' => ['required', 'boolean'],
        ]);

        $file = $this->archiveFiles->updatePickerVisibility(
            $file,
            (bool) $validated['hidden_from_picker'],
            $request,
        );

        return $this->pickerVisibilityResponse($file);
    }

    public function showFilePublicLink(Request $request, ArchiveFile $file): JsonResponse
    {
        abort_unless($request->user()?->can('file_manager.public_links.view'), 403);

        return $this->publicLinkResponse($file);
    }

    public function createFilePublicLink(Request $request, ArchiveFile $file): JsonResponse
    {
        abort_unless($request->user()?->can('file_manager.public_links.create'), 403);

        return $this->createPublicLinkResponse($file, $request);
    }

    public function revokeFilePublicLink(Request $request, ArchiveFile $file): JsonResponse
    {
        abort_unless($request->user()?->can('file_manager.public_links.revoke'), 403);

        return $this->revokePublicLinkResponse($file, $request);
    }

    public function showFolderPublicLink(Request $request, ArchiveFolder $folder): JsonResponse
    {
        abort_unless($request->user()?->can('file_manager.public_links.view'), 403);

        return $this->publicLinkResponse($folder);
    }

    public function createFolderPublicLink(Request $request, ArchiveFolder $folder): JsonResponse
    {
        abort_unless($request->user()?->can('file_manager.public_links.create'), 403);

        return $this->createPublicLinkResponse($folder, $request);
    }

    public function revokeFolderPublicLink(Request $request, ArchiveFolder $folder): JsonResponse
    {
        abort_unless($request->user()?->can('file_manager.public_links.revoke'), 403);

        return $this->revokePublicLinkResponse($folder, $request);
    }

    public function bulkDownload(BulkArchiveDownloadRequest $request)
    {
        try {
            $zip = $this->bulkDownloads->buildZip($request->validated('file_doc_nums'), request: $request);
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        }

        return response()->download($zip['path'], $zip['name'])->deleteFileAfterSend();
    }

    public function bulkDelete(BulkArchiveDeleteRequest $request): JsonResponse
    {
        $fileDocNums = $request->validated('file_doc_nums');
        $company = app(OperatingCompanyContextService::class)->currentCompany($request);

        try {
            $deleted = $this->archiveFiles->bulkDelete(
                $fileDocNums,
                $request,
                filter: fn (ArchiveFile $file): bool => $this->fileBelongsToCurrentCompanyScope($file, $company),
                company: $company,
            );
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        }

        return response()->json([
            'success' => true,
            'message' => __('archive.bulk_deleted_successfully', ['count' => $deleted]),
        ]);
    }

    public function bulkRestore(BulkArchiveRestoreRequest $request): JsonResponse
    {
        $fileDocNums = $request->validated('file_doc_nums');
        $company = app(OperatingCompanyContextService::class)->currentCompany($request);

        try {
            $restored = $this->archiveFiles->bulkRestore(
                $fileDocNums,
                $request,
                filter: fn (ArchiveFile $file): bool => $this->fileBelongsToCurrentCompanyScope($file, $company),
                company: $company,
            );
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        }

        return response()->json([
            'success' => true,
            'message' => __('archive.bulk_restored_successfully', ['count' => $restored]),
        ]);
    }

    public function updateDocumentNumberSettings(
        UpdateArchiveDocumentNumberSettingsRequest $request,
        ArchiveDocumentNumberSettingsService $documentNumberSettings
    ): JsonResponse {
        $result = $documentNumberSettings->update(
            $request->validated('archive_files_prefix'),
            (int) $request->validated('archive_files_padding'),
            $request->validated('archive_folders_prefix'),
            (int) $request->validated('archive_folders_padding'),
        );

        $this->logActivity($request, 'archive.document_number_settings.update', [
            'old_archive_files_prefix' => $result['old']['archive_files']['prefix'],
            'new_archive_files_prefix' => $result['new']['archive_files']['prefix'],
            'old_archive_files_padding' => $result['old']['archive_files']['padding'],
            'new_archive_files_padding' => $result['new']['archive_files']['padding'],
            'old_archive_folders_prefix' => $result['old']['archive_folders']['prefix'],
            'new_archive_folders_prefix' => $result['new']['archive_folders']['prefix'],
            'old_archive_folders_padding' => $result['old']['archive_folders']['padding'],
            'new_archive_folders_padding' => $result['new']['archive_folders']['padding'],
        ]);

        return response()->json([
            'success' => true,
            'message' => __('common.document_number_settings.updated_successfully'),
            'data' => $result['new'],
        ]);
    }

    private function folderView(ArchiveFolder $folder): View
    {
        $listUrl = $folder->parent_id === null && $folder->record_type === null
            ? route('admin.file-manager.index')
            : route('admin.file-manager.folder.show', $folder->doc_num);
        $uploadUrl = $folder->parent_id === null && $folder->record_type === null
            ? route('admin.file-manager.files.store')
            : route('admin.file-manager.folder.files.store', $folder->doc_num);

        return view('modules.core.file-manager.index', [
            'breadcrumbs' => $this->folderPageBreadcrumbs($folder),
            'currentFolder' => $folder,
            'folderBreadcrumbs' => $this->visibleFolderBreadcrumbs($folder),
            'documentNumberSettings' => app(ArchiveDocumentNumberSettingsService::class)->current(),
            'uploadUrl' => $uploadUrl,
            'listUrl' => $listUrl,
            'folderStoreUrl' => route('admin.file-manager.folders.store'),
            'folderUpdateRoute' => 'admin.file-manager.folders.update',
            'folderDeleteRoute' => 'admin.file-manager.folders.destroy',
            'bulkDownloadUrl' => auth()->user()?->can('file_manager.download') ? route('admin.file-manager.bulk-download') : null,
            'bulkMoveUrl' => auth()->user()?->can('file_manager.move') ? route('admin.file-manager.bulk-move') : null,
            'moveUrl' => auth()->user()?->can('file_manager.move') ? route('admin.file-manager.move') : null,
            'folderOptionsUrl' => auth()->user()?->can('file_manager.move') ? route('admin.file-manager.folder-options') : null,
        ]);
    }

    private function folderFromRequest(Request $request): ?ArchiveFolder
    {
        $docNum = $request->string('parent_folder')->trim()->toString();

        if ($docNum === '') {
            return null;
        }

        $folder = ArchiveFolder::query()->where('doc_num', $docNum)->firstOrFail();
        $company = app(OperatingCompanyContextService::class)->currentCompany($request);

        if ($company instanceof Company) {
            $folderCompanyId = $this->folderCompanyId($folder);

            abort_if($folderCompanyId !== null && $folderCompanyId !== (int) $company->getKey(), 404);
        }

        return $folder;
    }

    private function fileForRestore(string $docNum): ArchiveFile
    {
        return ArchiveFile::onlyTrashed()
            ->where('doc_num', $docNum)
            ->latest('deleted_at')
            ->first()
            ?? ArchiveFile::query()
                ->where('doc_num', $docNum)
                ->firstOrFail();
    }

    private function folderForRestore(string $docNum): ArchiveFolder
    {
        return ArchiveFolder::onlyTrashed()
            ->where('doc_num', $docNum)
            ->latest('deleted_at')
            ->first()
            ?? ArchiveFolder::query()
                ->where('doc_num', $docNum)
                ->firstOrFail();
    }

    private function fileBelongsToCurrentCompanyScope(ArchiveFile $file, ?Company $company): bool
    {
        if (! $company instanceof Company) {
            return true;
        }

        $fileCompanyId = $this->fileCompanyId($file);

        return $fileCompanyId === null || $fileCompanyId === (int) $company->getKey();
    }

    private function fileCompanyId(ArchiveFile $file): ?int
    {
        if ((string) $file->attachable_type !== (new Company)->getMorphClass()) {
            return null;
        }

        return $file->attachable_id === null ? null : (int) $file->attachable_id;
    }

    private function folderCompanyId(ArchiveFolder $folder): ?int
    {
        if ((string) $folder->attachable_type !== (new Company)->getMorphClass()) {
            return null;
        }

        return $folder->attachable_id === null ? null : (int) $folder->attachable_id;
    }

    private function uploadCompanyForFolder(ArchiveFolder $folder, Request $request): ?Company
    {
        $company = app(OperatingCompanyContextService::class)->currentCompany($request);

        if (! $company instanceof Company) {
            return $folder->attachable instanceof Company ? $folder->attachable : null;
        }

        if ($folder->attachable instanceof Company) {
            abort_unless((int) $folder->attachable->getKey() === (int) $company->getKey(), 404);
        }

        return $company;
    }

    private function publicLinkResponse(Model $item): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->publicLinks->summary($this->publicLinks->activeFor($item)),
        ]);
    }

    private function pickerVisibilityResponse(Model $item): JsonResponse
    {
        $hiddenFromPicker = (bool) $item->getAttribute('hidden_from_picker');

        return response()->json([
            'success' => true,
            'message' => __('archive.picker_visibility_updated'),
            'data' => [
                'doc_num' => $item->getAttribute('doc_num'),
                'hidden_from_picker' => $hiddenFromPicker,
                'label' => $hiddenFromPicker ? __('archive.hidden_from_picker') : __('archive.visible_in_picker'),
            ],
        ]);
    }

    private function createPublicLinkResponse(Model $item, Request $request): JsonResponse
    {
        $link = $this->publicLinks->create(
            $item,
            $request,
            allowPreview: $request->boolean('allow_preview', true),
            allowDownload: $request->boolean('allow_download', false),
        );

        return response()->json([
            'success' => true,
            'message' => __('archive.public_links.created_successfully'),
            'data' => $this->publicLinks->summary($link),
        ]);
    }

    private function revokePublicLinkResponse(Model $item, Request $request): JsonResponse
    {
        $this->publicLinks->revoke($item, $request);

        return response()->json([
            'success' => true,
            'message' => __('archive.public_links.revoked_successfully'),
            'data' => $this->publicLinks->summary(null),
        ]);
    }

    /**
     * @return array<int, array{label: string, url?: string|null, active?: bool}>
     */
    private function folderPageBreadcrumbs(ArchiveFolder $folder): array
    {
        $extra = collect($this->visibleFolderBreadcrumbs($folder))
            ->map(fn (ArchiveFolder $breadcrumbFolder): array => [
                'label' => $breadcrumbFolder->name,
                'url' => $folder->is($breadcrumbFolder) ? null : route('admin.file-manager.folder.show', $breadcrumbFolder->doc_num),
                'active' => $folder->is($breadcrumbFolder),
            ])
            ->values()
            ->all();

        return $this->breadcrumbs->forMenuRoute('admin.file-manager.index', $extra);
    }

    /**
     * @return list<ArchiveFolder>
     */
    private function visibleFolderBreadcrumbs(ArchiveFolder $folder): array
    {
        return collect($this->archiveFolders->breadcrumb($folder))
            ->reject(fn (ArchiveFolder $breadcrumbFolder): bool => $this->isInternalGeneralRoot($breadcrumbFolder))
            ->values()
            ->all();
    }

    private function isInternalGeneralRoot(ArchiveFolder $folder): bool
    {
        return $folder->parent_id === null
            && $folder->record_type === null
            && $folder->attachable_type === null
            && $folder->attachable_id === null;
    }

    private function domainError(DomainException $exception): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $exception->getMessage(),
        ], 422);
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function logActivity(Request $request, string $action, array $properties = [], string $status = 'success'): void
    {
        try {
            $this->activityLogger->log($request, 'core', $action, $status, [
                'properties_only' => true,
                'properties' => $properties,
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
