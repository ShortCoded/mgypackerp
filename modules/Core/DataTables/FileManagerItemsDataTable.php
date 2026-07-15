<?php

namespace Modules\Core\DataTables;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Core\DataTables\Concerns\FormatsNullableColumns;
use Modules\Core\Models\ArchiveFolder;
use Modules\Core\Models\Company;
use Modules\Core\Models\Product;
use Modules\Core\Services\DataTableSearchService;
use Modules\Core\Services\SettingService;
use Modules\FixedAssets\Models\FixedAsset;
use stdClass;
use Yajra\DataTables\Facades\DataTables;

class FileManagerItemsDataTable
{
    use FormatsNullableColumns;

    public function __construct(
        private readonly DataTableSearchService $searchService,
    ) {}

    public function json(Request $request): JsonResponse
    {
        $dateTimeFormat = app(SettingService::class)->dateTimeFormat();
        $context = $this->routeContext();
        $trashFilter = $this->trashFilter($request);
        $query = $this->query($request, $trashFilter);
        $searchColumns = $this->searchColumns();
        $user = $request->user();
        $permissions = [
            'canView' => (bool) $user?->can($context['permissions']['preview']),
            'canDownload' => (bool) $user?->can($context['permissions']['download']),
            'canDeleteFiles' => (bool) $user?->can($context['permissions']['delete']),
            'canRestoreFiles' => (bool) $user?->can($context['permissions']['restore']),
            'canRenameFolders' => (bool) $user?->can($context['permissions']['folders_rename']),
            'canDeleteFolders' => (bool) $user?->can($context['permissions']['folders_delete']),
            'canRestoreFolders' => (bool) $user?->can($context['permissions']['folders_restore']),
            'canCreatePublicLink' => (bool) $user?->can($context['permissions']['public_links_create']),
            'canViewPublicLink' => (bool) $user?->can($context['permissions']['public_links_view']),
            'canRevokePublicLink' => (bool) $user?->can($context['permissions']['public_links_revoke']),
            'canUpdatePickerVisibility' => (bool) $user?->can($context['permissions']['update_picker_visibility']),
            'canMove' => (bool) $user?->can($context['permissions']['move']),
        ];

        return DataTables::query($query)
            ->filter(function (Builder $query) use ($request, $searchColumns): void {
                $search = $request->input('search.value');
                $terms = $this->searchService->terms(is_string($search) ? $search : null);

                if ($terms === []) {
                    return;
                }

                $this->searchService->applyMultiTermSearch($query, $terms, $searchColumns);
            })
            ->addColumn('checkbox', fn (stdClass $item): string => $this->checkbox($item, $trashFilter))
            ->addColumn('name', fn (stdClass $item): string => $this->name($item, $context))
            ->addColumn('original_name', fn (stdClass $item): string => $this->ellipsisText((string) $item->item_name))
            ->addColumn('folder_path', fn (stdClass $item): string => $this->folderPath($item, $context))
            ->editColumn('item_type', fn (stdClass $item): string => $this->itemType($item))
            ->editColumn('doc_num', fn (stdClass $item): string => $this->ellipsisText((string) $item->doc_num))
            ->editColumn('module', fn (stdClass $item): string => $this->ellipsisText($item->module ? __("archive.modules.{$item->module}") : null))
            ->addColumn('related_record', fn (stdClass $item): string => $this->relatedRecord($item))
            ->addColumn('usage', fn (stdClass $item): string => $this->usage($item))
            ->addColumn('picker_visibility', fn (stdClass $item): string => $this->pickerVisibility($item))
            ->editColumn('extension', fn (stdClass $item): string => $this->extension($item))
            ->addColumn('size', fn (stdClass $item): string => $item->item_type === 'file' ? e($this->formatBytes((int) $item->size_bytes)) : '')
            ->addColumn('created_by', fn (stdClass $item): string => $this->ellipsisText($item->created_by_name))
            ->editColumn('created_at', fn (stdClass $item): string => $this->plainText($item->created_at ? date($dateTimeFormat, strtotime((string) $item->created_at)) : ''))
            ->addColumn('updated_by', fn (stdClass $item): string => $this->ellipsisText($item->updated_by_name))
            ->editColumn('updated_at', fn (stdClass $item): string => $this->plainText($item->updated_at ? date($dateTimeFormat, strtotime((string) $item->updated_at)) : ''))
            ->addColumn('deleted_by', fn (stdClass $item): string => $this->ellipsisText($item->deleted_by_name))
            ->editColumn('deleted_at', fn (stdClass $item): string => $this->plainText($item->deleted_at ? date($dateTimeFormat, strtotime((string) $item->deleted_at)) : ''))
            ->addColumn('restored_by', fn (stdClass $item): string => $this->ellipsisText($item->restored_by_name))
            ->editColumn('restored_at', fn (stdClass $item): string => $this->plainText($item->restored_at ? date($dateTimeFormat, strtotime((string) $item->restored_at)) : ''))
            ->addColumn('actions', fn (stdClass $item): string => view('modules.core.archive.partials.file-manager-item-actions', [
                'item' => $item,
                'routes' => $context['routes'],
                'routeParameters' => $context['route_parameters'],
                ...$permissions,
            ])->render())
            ->orderColumn('item_type', 'sort_order $1')
            ->orderColumn('name', 'item_name $1')
            ->orderColumn('original_name', 'item_name $1')
            ->orderColumn('folder_path', 'folder_path $1')
            ->orderColumn('doc_num', 'doc_number $1')
            ->orderColumn('module', 'module $1')
            ->orderColumn('related_record', 'record_doc_num $1')
            ->orderColumn('usage', 'usage_count $1')
            ->orderColumn('picker_visibility', 'hidden_from_picker $1')
            ->orderColumn('extension', 'extension $1')
            ->orderColumn('size', 'size_bytes $1')
            ->orderColumn('created_by', 'created_by_name $1')
            ->orderColumn('created_at', 'created_at $1')
            ->orderColumn('updated_by', 'updated_by_name $1')
            ->orderColumn('updated_at', 'updated_at $1')
            ->orderColumn('deleted_by', 'deleted_by_name $1')
            ->orderColumn('deleted_at', 'deleted_at $1')
            ->orderColumn('restored_by', 'restored_by_name $1')
            ->orderColumn('restored_at', 'restored_at $1')
            ->removeColumn('doc_number')
            ->removeColumn('sort_order')
            ->removeColumn('item_name')
            ->removeColumn('record_type')
            ->removeColumn('record_doc_num')
            ->removeColumn('folder_doc_num')
            ->removeColumn('folder_name')
            ->removeColumn('company_name')
            ->removeColumn('title')
            ->removeColumn('description')
            ->removeColumn('hidden_from_picker')
            ->removeColumn('archive_file_id')
            ->removeColumn('usage_count')
            ->removeColumn('usage_summary')
            ->removeColumn('size_bytes')
            ->removeColumn('mime_type')
            ->removeColumn('created_by_name')
            ->removeColumn('updated_by_name')
            ->removeColumn('deleted_by_name')
            ->removeColumn('restored_by_name')
            ->removeColumn('is_trashed')
            ->rawColumns(['checkbox', 'name', 'original_name', 'folder_path', 'item_type', 'doc_num', 'module', 'related_record', 'usage', 'picker_visibility', 'extension', 'created_by', 'updated_by', 'deleted_by', 'restored_by', 'actions'])
            ->toJson();
    }

    private function query(Request $request, string $trashFilter): Builder
    {
        $folderDocNum = $request->string('folder_doc_num')->trim()->toString();
        $globalSearch = $request->boolean('global_search');

        if ($globalSearch || $folderDocNum === '') {
            return $this->globalFilesQuery($trashFilter);
        }

        $folder = ArchiveFolder::query()->where('doc_num', $folderDocNum)->firstOrFail();

        return $this->folderItemsQuery($folder, $trashFilter);
    }

    private function trashFilter(Request $request): string
    {
        if (! $request->user()?->can('file_manager.view_trashed')) {
            return 'active';
        }

        $filter = $request->string('trash_filter')->toString();

        return in_array($filter, ['active', 'trashed', 'all'], true) ? $filter : 'active';
    }

    private function applyTrashFilter(Builder $query, string $table, string $trashFilter): void
    {
        match ($trashFilter) {
            'trashed' => $query->whereNotNull("{$table}.deleted_at"),
            'all' => null,
            default => $query->whereNull("{$table}.deleted_at"),
        };
    }

    private function globalFilesQuery(string $trashFilter): Builder
    {
        $usageSummary = $this->usageSummarySubquery();

        $files = DB::query()
            ->from('archive_files')
            ->leftJoin('archive_folders', 'archive_folders.id', '=', 'archive_files.archive_folder_id')
            ->leftJoin('companies', function ($join): void {
                $join
                    ->on('companies.id', '=', 'archive_files.attachable_id')
                    ->where('archive_files.attachable_type', '=', (new Company)->getMorphClass());
            })
            ->leftJoinSub($usageSummary, 'archive_file_usage_summaries', function ($join): void {
                $join->on('archive_file_usage_summaries.archive_file_id', '=', 'archive_files.id');
            })
            ->leftJoin('users as uploaded_users', 'uploaded_users.id', '=', 'archive_files.uploaded_by')
            ->leftJoin('users as updated_users', 'updated_users.id', '=', 'archive_files.updated_by')
            ->leftJoin('users as deleted_users', 'deleted_users.id', '=', 'archive_files.deleted_by')
            ->leftJoin('users as restored_users', 'restored_users.id', '=', 'archive_files.restored_by');

        $this->applyTrashFilter($files, 'archive_files', $trashFilter);

        $files->select([
            DB::raw("'file' as item_type"),
            DB::raw('1 as sort_order'),
            DB::raw('CASE WHEN archive_files.deleted_at IS NULL THEN 0 ELSE 1 END as is_trashed'),
            'archive_files.doc_number',
            'archive_files.doc_num',
            'archive_files.original_name as item_name',
            'archive_files.module',
            'archive_files.record_type',
            'archive_files.record_doc_num',
            'archive_files.folder_doc_num',
            'archive_folders.name as folder_name',
            DB::raw('CASE WHEN archive_folders.parent_id IS NULL AND archive_folders.record_type IS NULL AND archive_folders.attachable_type IS NULL THEN NULL ELSE archive_folders.path_cache END as folder_path'),
            'companies.name as company_name',
            'archive_files.title',
            'archive_files.description',
            'archive_files.hidden_from_picker',
            DB::raw('COALESCE(archive_file_usage_summaries.usage_count, 0) as usage_count'),
            'archive_file_usage_summaries.usage_summary',
            'archive_files.extension',
            'archive_files.size_bytes',
            'archive_files.mime_type',
            'uploaded_users.name as created_by_name',
            'archive_files.created_at',
            'updated_users.name as updated_by_name',
            'archive_files.updated_at',
            'deleted_users.name as deleted_by_name',
            'archive_files.deleted_at',
            'restored_users.name as restored_by_name',
            'archive_files.restored_at',
        ]);

        return DB::query()
            ->fromSub($files, 'file_manager_items')
            ->select([
                'file_manager_items.*',
            ]);
    }

    private function folderItemsQuery(ArchiveFolder $folder, string $trashFilter): Builder
    {
        $usageSummary = $this->usageSummarySubquery();

        $folders = DB::table('archive_folders')
            ->leftJoin('users as created_users', 'created_users.id', '=', 'archive_folders.created_by')
            ->leftJoin('users as updated_users', 'updated_users.id', '=', 'archive_folders.updated_by')
            ->leftJoin('users as deleted_users', 'deleted_users.id', '=', 'archive_folders.deleted_by')
            ->leftJoin('users as restored_users', 'restored_users.id', '=', 'archive_folders.restored_by')
            ->where(function (Builder $query) use ($folder): void {
                $query->where('archive_folders.parent_id', $folder->getKey());

                if ($folder->parent_id === null && $folder->record_type === null) {
                    $query->orWhere(function (Builder $rootQuery) use ($folder): void {
                        $rootQuery
                            ->whereNull('archive_folders.parent_id')
                            ->where('archive_folders.id', '<>', $folder->getKey());
                    });
                }
            });

        $this->applyTrashFilter($folders, 'archive_folders', $trashFilter);

        $folders->select([
            DB::raw("'folder' as item_type"),
            DB::raw('0 as sort_order'),
            DB::raw('CASE WHEN archive_folders.deleted_at IS NULL THEN 0 ELSE 1 END as is_trashed'),
            'archive_folders.doc_number',
            'archive_folders.doc_num',
            'archive_folders.name as item_name',
            'archive_folders.module',
            'archive_folders.record_type',
            'archive_folders.record_doc_num',
            'archive_folders.doc_num as folder_doc_num',
            'archive_folders.name as folder_name',
            DB::raw('CASE WHEN archive_folders.parent_id IS NULL AND archive_folders.record_type IS NULL AND archive_folders.attachable_type IS NULL THEN NULL ELSE archive_folders.path_cache END as folder_path'),
            DB::raw('NULL as company_name'),
            DB::raw('NULL as title'),
            DB::raw('NULL as description'),
            'archive_folders.hidden_from_picker',
            DB::raw('0 as usage_count'),
            DB::raw('NULL as usage_summary'),
            DB::raw('NULL as extension'),
            DB::raw('NULL as size_bytes'),
            DB::raw('NULL as mime_type'),
            'created_users.name as created_by_name',
            'archive_folders.created_at',
            'updated_users.name as updated_by_name',
            'archive_folders.updated_at',
            'deleted_users.name as deleted_by_name',
            'archive_folders.deleted_at',
            'restored_users.name as restored_by_name',
            'archive_folders.restored_at',
        ]);

        $files = DB::table('archive_files')
            ->leftJoin('archive_folders', 'archive_folders.id', '=', 'archive_files.archive_folder_id')
            ->leftJoin('companies', function ($join): void {
                $join
                    ->on('companies.id', '=', 'archive_files.attachable_id')
                    ->where('archive_files.attachable_type', '=', (new Company)->getMorphClass());
            })
            ->leftJoinSub($usageSummary, 'archive_file_usage_summaries', function ($join): void {
                $join->on('archive_file_usage_summaries.archive_file_id', '=', 'archive_files.id');
            })
            ->leftJoin('users as uploaded_users', 'uploaded_users.id', '=', 'archive_files.uploaded_by')
            ->leftJoin('users as updated_users', 'updated_users.id', '=', 'archive_files.updated_by')
            ->leftJoin('users as deleted_users', 'deleted_users.id', '=', 'archive_files.deleted_by')
            ->leftJoin('users as restored_users', 'restored_users.id', '=', 'archive_files.restored_by')
            ->where('archive_files.archive_folder_id', $folder->getKey());

        $this->applyTrashFilter($files, 'archive_files', $trashFilter);

        $files->select([
            DB::raw("'file' as item_type"),
            DB::raw('1 as sort_order'),
            DB::raw('CASE WHEN archive_files.deleted_at IS NULL THEN 0 ELSE 1 END as is_trashed'),
            'archive_files.doc_number',
            'archive_files.doc_num',
            'archive_files.original_name as item_name',
            'archive_files.module',
            'archive_files.record_type',
            'archive_files.record_doc_num',
            'archive_files.folder_doc_num',
            'archive_folders.name as folder_name',
            'archive_folders.path_cache as folder_path',
            'companies.name as company_name',
            'archive_files.title',
            'archive_files.description',
            'archive_files.hidden_from_picker',
            DB::raw('COALESCE(archive_file_usage_summaries.usage_count, 0) as usage_count'),
            'archive_file_usage_summaries.usage_summary',
            'archive_files.extension',
            'archive_files.size_bytes',
            'archive_files.mime_type',
            'uploaded_users.name as created_by_name',
            'archive_files.created_at',
            'updated_users.name as updated_by_name',
            'archive_files.updated_at',
            'deleted_users.name as deleted_by_name',
            'archive_files.deleted_at',
            'restored_users.name as restored_by_name',
            'archive_files.restored_at',
        ]);

        return DB::query()
            ->fromSub($folders->unionAll($files), 'file_manager_items')
            ->select('file_manager_items.*');
    }

    private function checkbox(stdClass $item, string $trashFilter): string
    {
        return view('modules.core.archive.partials.file-manager-item-checkbox', compact('item', 'trashFilter'))->render();
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function name(stdClass $item, array $context): string
    {
        $name = trim((string) $item->item_name);
        $docNum = trim((string) $item->doc_num);
        $icon = $item->item_type === 'folder'
            ? '<span class="fas fa-folder text-warning me-2"></span>'
            : '<span class="fas fa-file-alt text-600 me-2"></span>';
        $label = $this->ellipsisText($name);
        $url = $this->primaryUrl($item, $context);

        if ($url) {
            $label = sprintf('<a class="fw-semibold text-decoration-none" href="%s">%s</a>', e($url), $label);
        } else {
            $label = '<span class="fw-semibold">'.$label.'</span>';
        }

        return $icon.$label.'<div class="fs-11 text-600">'.e($docNum).'</div>';
    }

    private function itemType(stdClass $item): string
    {
        if ($item->item_type === 'folder') {
            return '<span class="badge rounded-pill badge-subtle-warning">'.e(__('archive.folder')).'</span>';
        }

        return '<span class="badge rounded-pill badge-subtle-info">'.e(__('archive.file')).'</span>';
    }

    private function relatedRecord(stdClass $item): string
    {
        if (! $item->record_doc_num) {
            return $item->item_type === 'folder'
                ? ''
                : e(__('archive.general_file'));
        }

        $record = $this->ellipsisText((string) $item->record_doc_num);
        $companyName = trim((string) ($item->company_name ?? ''));

        if ($companyName === '') {
            return $record;
        }

        return $record.'<div class="fs-11 text-600">'.$this->ellipsisText($companyName).'</div>';
    }

    private function usage(stdClass $item): string
    {
        if ($item->item_type !== 'file' || (int) ($item->usage_count ?? 0) < 1) {
            return '';
        }

        $summary = trim((string) ($item->usage_summary ?? ''));
        $badge = '<span class="badge rounded-pill badge-subtle-success">'.e(__('archive.used')).'</span>';
        $count = '<span class="badge rounded-pill badge-subtle-secondary ms-1" title="'.e(__('archive.usage_count')).'" data-bs-title="'.e(__('archive.usage_count')).'">'.e((string) $item->usage_count).'</span>';

        if ($summary === '') {
            return $badge.$count;
        }

        return $badge.$count.'<div class="fs-11 text-600 dt-ellipsis-content" title="'.e($summary).'" dir="auto">'.e(__('archive.used_in')).': '.e($summary).'</div>';
    }

    private function pickerVisibility(stdClass $item): string
    {
        if ((bool) ($item->hidden_from_picker ?? false)) {
            return '<span class="badge rounded-pill badge-subtle-secondary">'.e(__('archive.hidden_from_picker')).'</span>';
        }

        return '<span class="badge rounded-pill badge-subtle-success">'.e(__('archive.visible_in_picker')).'</span>';
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function folderPath(stdClass $item, array $context): string
    {
        $segments = ArchiveFolder::displayPathSegmentsFromCache($item->folder_path ?? null);
        $path = implode(' / ', $segments);
        $label = $this->folderPathLabel($segments, $path);

        $folderDocNum = trim((string) ($item->folder_doc_num ?? ''));

        if ($folderDocNum === '' || (bool) ($item->is_trashed ?? false)) {
            return $label;
        }

        return sprintf(
            '<a class="dt-folder-path dt-ellipsis-content text-decoration-none" title="%s" href="%s" dir="auto">%s</a>',
            e($path),
            e($this->routeFor($context, 'folder_show', 'folder', $folderDocNum)),
            $this->folderPathSegmentsHtml($segments),
        );
    }

    /**
     * @param  list<string>  $segments
     */
    private function folderPathLabel(array $segments, string $path): string
    {
        return sprintf(
            '<span class="dt-folder-path dt-ellipsis-content" title="%s" dir="auto">%s</span>',
            e($path),
            $this->folderPathSegmentsHtml($segments),
        );
    }

    /**
     * @param  list<string>  $segments
     */
    private function folderPathSegmentsHtml(array $segments): string
    {
        $html = [];

        foreach ($segments as $index => $segment) {
            if ($index > 0) {
                $html[] = '<span class="dt-folder-path-separator" aria-hidden="true">/</span>';
            }

            $html[] = sprintf(
                '<span class="dt-folder-path-segment">%s</span>',
                e($segment),
            );
        }

        return implode('', $html);
    }

    private function extension(stdClass $item): string
    {
        if ($item->item_type === 'folder') {
            return '';
        }

        $extension = trim((string) $item->extension);

        if ($extension === '') {
            return '';
        }

        return '<span class="badge rounded-pill badge-subtle-secondary">'.e(mb_strtoupper($extension)).'</span>';
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function primaryUrl(stdClass $item, array $context): ?string
    {
        if ((bool) ($item->is_trashed ?? false)) {
            return null;
        }

        if ($item->item_type === 'folder') {
            return $this->routeFor($context, 'folder_show', 'folder', (string) $item->doc_num);
        }

        if ($this->isPreviewable($item) && auth()->user()?->can($context['permissions']['preview'])) {
            return $this->routeFor($context, 'file_preview', 'file', (string) $item->doc_num);
        }

        if (auth()->user()?->can($context['permissions']['download'])) {
            return $this->routeFor($context, 'file_download', 'file', (string) $item->doc_num);
        }

        return null;
    }

    private function usageSummarySubquery(): Builder
    {
        $productMorph = (new Product)->getMorphClass();
        $fixedAssetMorph = (new FixedAsset)->getMorphClass();

        return DB::table('archive_file_usages')
            ->leftJoin('products', function ($join) use ($productMorph): void {
                $join
                    ->on('products.id', '=', 'archive_file_usages.usable_id')
                    ->where('archive_file_usages.usable_type', '=', $productMorph);
            })
            ->leftJoin('fixed_assets', function ($join) use ($fixedAssetMorph): void {
                $join
                    ->on('fixed_assets.id', '=', 'archive_file_usages.usable_id')
                    ->where('archive_file_usages.usable_type', '=', $fixedAssetMorph);
            })
            ->whereNull('archive_file_usages.deleted_at')
            ->select('archive_file_usages.archive_file_id')
            ->selectRaw('COUNT(*) as usage_count')
            ->selectRaw($this->usageSummaryAggregateExpression().' as usage_summary')
            ->groupBy('archive_file_usages.archive_file_id');
    }

    private function usageSummaryAggregateExpression(): string
    {
        $label = $this->usageSummaryLabelExpression();

        return match (DB::getDriverName()) {
            'pgsql' => "STRING_AGG({$label}, ', ')",
            'mysql', 'mariadb' => "GROUP_CONCAT({$label} SEPARATOR ', ')",
            default => "GROUP_CONCAT({$label}, ', ')",
        };
    }

    private function usageSummaryLabelExpression(): string
    {
        $productMorph = DB::getPdo()->quote((new Product)->getMorphClass());
        $productLabel = DB::getPdo()->quote(__('products.singular'));
        $fixedAssetMorph = DB::getPdo()->quote((new FixedAsset)->getMorphClass());
        $fixedAssetLabel = DB::getPdo()->quote(__('fixed_assets.singular'));

        return match (DB::getDriverName()) {
            'pgsql', 'mysql', 'mariadb' => "CASE WHEN archive_file_usages.usable_type = {$productMorph} THEN CONCAT({$productLabel}, ' / ', COALESCE(products.doc_num, ''), ' / ', COALESCE(products.name, '')) WHEN archive_file_usages.usable_type = {$fixedAssetMorph} THEN CONCAT({$fixedAssetLabel}, ' / ', COALESCE(fixed_assets.doc_num, ''), ' / ', COALESCE(fixed_assets.asset_name, '')) ELSE CONCAT(archive_file_usages.collection, CASE WHEN archive_file_usages.role IS NULL THEN '' ELSE CONCAT(' / ', archive_file_usages.role) END) END",
            default => "CASE WHEN archive_file_usages.usable_type = {$productMorph} THEN {$productLabel} || ' / ' || COALESCE(products.doc_num, '') || ' / ' || COALESCE(products.name, '') WHEN archive_file_usages.usable_type = {$fixedAssetMorph} THEN {$fixedAssetLabel} || ' / ' || COALESCE(fixed_assets.doc_num, '') || ' / ' || COALESCE(fixed_assets.asset_name, '') ELSE archive_file_usages.collection || CASE WHEN archive_file_usages.role IS NULL THEN '' ELSE ' / ' || archive_file_usages.role END END",
        };
    }

    /**
     * @return array{routes: array<string, string>, route_parameters: array<string, string>, permissions: array<string, string>}
     */
    private function routeContext(): array
    {
        return [
            'routes' => [
                'folder_show' => 'admin.file-manager.folder.show',
                'folder_update' => 'admin.file-manager.folders.update',
                'folder_picker_visibility_update' => 'admin.file-manager.folders.picker-visibility.update',
                'folder_delete' => 'admin.file-manager.folders.destroy',
                'folder_restore' => 'admin.file-manager.folders.restore',
                'file_preview' => 'admin.file-manager.files.preview',
                'file_download' => 'admin.file-manager.files.download',
                'file_picker_visibility_update' => 'admin.file-manager.files.picker-visibility.update',
                'file_delete' => 'admin.file-manager.files.destroy',
                'file_restore' => 'admin.file-manager.files.restore',
                'folder_public_link_show' => 'admin.file-manager.folders.public-link.show',
                'folder_public_link_create' => 'admin.file-manager.folders.public-link.store',
                'folder_public_link_revoke' => 'admin.file-manager.folders.public-link.destroy',
                'file_public_link_show' => 'admin.file-manager.files.public-link.show',
                'file_public_link_create' => 'admin.file-manager.files.public-link.store',
                'file_public_link_revoke' => 'admin.file-manager.files.public-link.destroy',
                'move' => 'admin.file-manager.move',
            ],
            'route_parameters' => [],
            'permissions' => [
                'preview' => 'file_manager.view',
                'download' => 'file_manager.download',
                'delete' => 'file_manager.delete',
                'restore' => 'file_manager.restore',
                'move' => 'file_manager.move',
                'update_picker_visibility' => 'file_manager.update_picker_visibility',
                'folders_rename' => 'file_manager.folders.rename',
                'folders_delete' => 'file_manager.folders.delete',
                'folders_restore' => 'file_manager.folders.restore',
                'public_links_create' => 'file_manager.public_links.create',
                'public_links_view' => 'file_manager.public_links.view',
                'public_links_revoke' => 'file_manager.public_links.revoke',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function routeFor(array $context, string $routeKey, string $parameterName, string $docNum): string
    {
        return route($context['routes'][$routeKey], [
            ...$context['route_parameters'],
            $parameterName => $docNum,
        ]);
    }

    private function isPreviewable(stdClass $item): bool
    {
        return str_starts_with((string) $item->mime_type, 'image/')
            || $item->mime_type === 'application/pdf';
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
                'item_name',
                'doc_num',
                'extension',
                'mime_type',
                'title',
                'description',
                'folder_name',
                'folder_path',
                'company_name',
                'module',
                'record_type',
                'record_doc_num',
                'usage_summary',
                'created_by_name',
                'updated_by_name',
                'deleted_by_name',
                'restored_by_name',
            ],
            'dates' => [
                'created_at',
                'updated_at',
                'deleted_at',
                'restored_at',
            ],
            'date_text' => [
                'created_at',
                'updated_at',
                'deleted_at',
                'restored_at',
            ],
        ];
    }
}
