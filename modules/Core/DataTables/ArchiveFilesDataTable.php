<?php

namespace Modules\Core\DataTables;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\DataTables\Concerns\FormatsNullableColumns;
use Modules\Core\Models\ArchiveFile;
use Modules\Core\Services\DataTableSearchService;
use Modules\Core\Services\SettingService;
use Yajra\DataTables\Facades\DataTables;

class ArchiveFilesDataTable
{
    use FormatsNullableColumns;

    public function __construct(
        private readonly DataTableSearchService $searchService,
    ) {}

    public function json(Request $request): JsonResponse
    {
        $dateTimeFormat = app(SettingService::class)->dateTimeFormat();
        $searchColumns = $this->searchColumns();

        $query = ArchiveFile::query()
            ->leftJoin('users as uploaded_users', 'uploaded_users.id', '=', 'archive_files.uploaded_by')
            ->select([
                'archive_files.id',
                'archive_files.doc_number',
                'archive_files.doc_num',
                'archive_files.module',
                'archive_files.record_type',
                'archive_files.record_doc_num',
                'archive_files.original_name',
                'archive_files.mime_type',
                'archive_files.extension',
                'archive_files.size_bytes',
                'archive_files.created_at',
                'uploaded_users.name as uploaded_by_name',
            ]);

        return DataTables::eloquent($query)
            ->filter(function ($query) use ($request, $searchColumns): void {
                $search = $request->input('search.value');
                $terms = $this->searchService->terms(is_string($search) ? $search : null);

                if ($terms === []) {
                    return;
                }

                $this->searchService->applyMultiTermSearch($query, $terms, $searchColumns);
            })
            ->editColumn('doc_num', fn (ArchiveFile $file): string => $this->ellipsisText($file->doc_num))
            ->editColumn('original_name', fn (ArchiveFile $file): string => $this->ellipsisText($file->original_name))
            ->editColumn('module', fn (ArchiveFile $file): string => $this->ellipsisText($file->module ? __("archive.modules.{$file->module}") : null))
            ->addColumn('related_record', fn (ArchiveFile $file): string => $this->relatedRecord($file))
            ->editColumn('extension', fn (ArchiveFile $file): string => $this->extensionBadge($file))
            ->editColumn('size_bytes', fn (ArchiveFile $file): string => e($this->formatBytes((int) $file->size_bytes)))
            ->addColumn('uploaded_by', fn (ArchiveFile $file): string => $this->ellipsisText($file->uploaded_by_name))
            ->editColumn('created_at', fn (ArchiveFile $file): string => $this->plainText($file->created_at?->format($dateTimeFormat) ?? ''))
            ->addColumn('actions', fn (ArchiveFile $file): string => view('modules.core.archive.partials.file-actions', [
                'file' => $file,
                'downloadUrl' => route('admin.file-manager.files.download', $file->doc_num),
                'previewUrl' => route('admin.file-manager.files.preview', $file->doc_num),
                'deleteUrl' => route('admin.file-manager.files.destroy', $file->doc_num),
                'canDownload' => auth()->user()?->can('file_manager.download'),
                'canDelete' => auth()->user()?->can('file_manager.delete'),
                'publicLinkCreateUrl' => route('admin.file-manager.files.public-link.store', $file->doc_num),
                'publicLinkShowUrl' => route('admin.file-manager.files.public-link.show', $file->doc_num),
                'publicLinkRevokeUrl' => route('admin.file-manager.files.public-link.destroy', $file->doc_num),
                'canCreatePublicLink' => auth()->user()?->can('file_manager.public_links.create'),
                'canViewPublicLink' => auth()->user()?->can('file_manager.public_links.view'),
                'canRevokePublicLink' => auth()->user()?->can('file_manager.public_links.revoke'),
            ])->render())
            ->orderColumn('doc_num', 'archive_files.doc_number $1')
            ->orderColumn('original_name', 'archive_files.original_name $1')
            ->orderColumn('module', 'archive_files.module $1')
            ->orderColumn('related_record', 'archive_files.record_doc_num $1')
            ->orderColumn('extension', 'archive_files.extension $1')
            ->orderColumn('size_bytes', 'archive_files.size_bytes $1')
            ->orderColumn('uploaded_by', 'uploaded_users.name $1')
            ->orderColumn('created_at', 'archive_files.created_at $1')
            ->removeColumn('id')
            ->rawColumns(['doc_num', 'original_name', 'module', 'related_record', 'extension', 'uploaded_by', 'actions'])
            ->toJson();
    }

    private function relatedRecord(ArchiveFile $file): string
    {
        if (! $file->record_doc_num) {
            return e(__('archive.general_file'));
        }

        return sprintf(
            '<span class="dt-ellipsis-content" title="%s">%s</span>',
            e($file->record_doc_num),
            e($file->record_doc_num),
        );
    }

    private function extensionBadge(ArchiveFile $file): string
    {
        $extension = trim((string) $file->extension);

        if ($extension === '') {
            return '';
        }

        return '<span class="badge rounded-pill badge-subtle-secondary">'.e(mb_strtoupper($extension)).'</span>';
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

    /**
     * @return array{text: list<string>, dates: list<string>, date_text: list<string>}
     */
    private function searchColumns(): array
    {
        return [
            'text' => [
                'archive_files.doc_num',
                'archive_files.original_name',
                'archive_files.module',
                'archive_files.record_type',
                'archive_files.record_doc_num',
                'archive_files.extension',
                'uploaded_users.name',
            ],
            'dates' => [
                'archive_files.created_at',
            ],
            'date_text' => [
                'archive_files.created_at',
            ],
        ];
    }
}
