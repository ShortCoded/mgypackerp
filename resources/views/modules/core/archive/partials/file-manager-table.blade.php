@php
    $folderBreadcrumbs = $folderBreadcrumbs ?? [];
    $currentFolder = $currentFolder ?? null;
    $tableId = $tableId ?? 'archive-files-table';
    $domIdPrefix = $domIdPrefix ?? 'file_manager';
    $title = $title ?? __('archive.file_manager');
    $rootUrl = $rootUrl ?? route('admin.file-manager.index');
    $rootLabel = $rootLabel ?? __('archive.file_manager');
    $dataUrl = $dataUrl ?? route('admin.file-manager.data');
    $folderShowRoute = $folderShowRoute ?? 'admin.file-manager.folder.show';
    $folderRouteParameters = $folderRouteParameters ?? [];
    $bulkDownloadUrl = $bulkDownloadUrl ?? (auth()->user()?->can('file_manager.download') ? route('admin.file-manager.bulk-download') : null);
    $bulkDeleteUrl = $bulkDeleteUrl ?? (auth()->user()?->can('file_manager.delete') ? route('admin.file-manager.bulk-delete') : null);
    $bulkRestoreUrl = $bulkRestoreUrl ?? (auth()->user()?->can('file_manager.restore') ? route('admin.file-manager.bulk-restore') : null);
    $bulkMoveUrl = $bulkMoveUrl ?? (auth()->user()?->can('file_manager.move') ? route('admin.file-manager.bulk-move') : null);
    $moveUrl = $moveUrl ?? (auth()->user()?->can('file_manager.move') ? route('admin.file-manager.move') : null);
    $folderOptionsUrl = $folderOptionsUrl ?? (auth()->user()?->can('file_manager.move') ? route('admin.file-manager.folder-options') : null);
    $enableGlobalSearch = (bool) ($enableGlobalSearch ?? true);
    $canBulkDownload = ! empty($bulkDownloadUrl);
    $canBulkDelete = ! empty($bulkDeleteUrl);
    $canBulkRestore = ! empty($bulkRestoreUrl);
    $canBulkMove = ! empty($bulkMoveUrl);
    $bulkActionsBarId = "{$domIdPrefix}_bulk_actions_bar";
    $bulkActionSelectId = "{$domIdPrefix}_bulk_action_select";
    $bulkActionApplyId = "{$domIdPrefix}_bulk_action_apply";
    $selectedCountId = "{$domIdPrefix}_selected_count";
    $selectAllId = "{$domIdPrefix}_select_all";
    $trashFilterId = "{$domIdPrefix}_trash_filter";
    $folderUrl = static fn ($folder): string => route($folderShowRoute, [
        ...$folderRouteParameters,
        'folder' => $folder->doc_num,
    ]);
@endphp

<div class="card erp-datatable-card file-manager-datatable-card js-archive-browser">
    <div class="card-header">
        <div class="row flex-between-center g-2">
            <div class="col-12 col-xl-auto">
                <h5 class="fs-9 mb-2 text-nowrap py-2 py-xl-0">{{ $title }}</h5>
                @if (! empty($folderBreadcrumbs))
                    <nav aria-label="{{ __('archive.folders') }}">
                        <ol class="breadcrumb mb-0" style="--falcon-breadcrumb-divider: '/';">
                            <li class="breadcrumb-item">
                                <a href="{{ $rootUrl }}">{{ $rootLabel }}</a>
                            </li>
                            @foreach ($folderBreadcrumbs as $breadcrumbFolder)
                                @php
                                    $isCurrent = $currentFolder?->doc_num === $breadcrumbFolder->doc_num;
                                    $url = $folderUrl($breadcrumbFolder);
                                @endphp
                                <li class="breadcrumb-item {{ $isCurrent ? 'active' : '' }}" @if ($isCurrent) aria-current="page" @endif>
                                    @if ($isCurrent)
                                        {{ $breadcrumbFolder->name }}
                                    @else
                                        <a href="{{ $url }}">{{ $breadcrumbFolder->name }}</a>
                                    @endif
                                </li>
                            @endforeach
                        </ol>
                    </nav>
                @endif
            </div>
            <div class="col-12 col-xl-auto ms-auto d-flex justify-content-xl-end align-items-center gap-2">
                @can('file_manager.view_trashed')
                    <div class="d-flex align-items-center gap-2">
                        <label class="form-label mb-0 text-700 fs-10" for="{{ $trashFilterId }}">{{ __('archive.trash.filter_label') }}</label>
                        <select class="form-select form-select-sm w-auto js-file-manager-trash-filter" id="{{ $trashFilterId }}" aria-label="{{ __('archive.trash.filter_label') }}">
                            <option value="active">{{ __('archive.trash.active') }}</option>
                            <option value="trashed">{{ __('archive.trash.trashed') }}</option>
                            <option value="all">{{ __('archive.trash.all') }}</option>
                        </select>
                    </div>
                @endcan
                @if ($canBulkDownload || $canBulkDelete || $canBulkRestore || $canBulkMove)
                    <div class="d-none align-items-center gap-2 file-manager-bulk-actions-bar js-archive-bulk-actions-bar" id="{{ $bulkActionsBarId }}">
                        <span class="badge rounded-pill badge-subtle-primary js-archive-selected-count" id="{{ $selectedCountId }}">0</span>
                        <select class="form-select form-select-sm w-auto js-archive-bulk-action-select" id="{{ $bulkActionSelectId }}" aria-label="{{ __('archive.bulk_actions') }}">
                            @if ($canBulkMove)
                                <option value="bulk_move" data-visible-filters="active" data-shortcut-action="file-manager.bulk-move">{{ __('archive.move_selected') }}</option>
                            @endif
                            @if ($canBulkDownload)
                                <option value="bulk_download" data-visible-filters="active" data-shortcut-action="file-manager.bulk-download">{{ __('archive.download_selected') }}</option>
                            @endif
                            @if ($canBulkDelete)
                                <option value="bulk_delete" data-visible-filters="active" data-shortcut-action="file-manager.bulk-delete">{{ __('archive.delete_selected') }}</option>
                            @endif
                            @if ($canBulkRestore)
                                <option value="bulk_restore" data-visible-filters="trashed" data-shortcut-action="file-manager.bulk-restore">{{ __('archive.restore_selected') }}</option>
                            @endif
                        </select>
                        <button type="button"
                            class="btn btn-falcon-primary btn-sm js-archive-bulk-action-apply"
                            id="{{ $bulkActionApplyId }}"
                            data-label="{{ __('common.actions.apply') }}"
                            data-shortcut-action="file-manager.bulk-apply"
                            title="{{ __('common.shortcuts.bulk_apply') }}"
                            data-bs-title="{{ __('common.shortcuts.bulk_apply') }}"
                            disabled>
                            <span class="fas fa-check" data-fa-transform="shrink-3 down-2"></span><span class="d-none d-sm-inline-block ms-1">{{ __('common.actions.apply') }}</span>
                        </button>
                    </div>
                @endif
            </div>
        </div>
    </div>
    @if ($enableGlobalSearch)
        <div class="card-body border-top border-bottom bg-body-tertiary py-3">
            <form class="row gx-2 gy-2 align-items-center js-file-manager-global-search-form" novalidate>
                <div class="col-12 col-md">
                    <label class="form-label mb-1" for="{{ $domIdPrefix }}_global_search">{{ __('archive.global_search') }}</label>
                    <div class="search-box">
                        <input class="form-control search-input js-file-manager-global-search-input"
                            id="{{ $domIdPrefix }}_global_search"
                            type="search"
                            autocomplete="off"
                            placeholder="{{ __('archive.global_search_placeholder') }}"
                            aria-label="{{ __('archive.global_search') }}">
                        <span class="fas fa-search search-box-icon"></span>
                    </div>
                </div>
                <div class="col-12 col-md-auto d-flex gap-2 align-self-md-end">
                    <button class="btn btn-falcon-primary js-file-manager-global-search-submit" type="submit">
                        <span class="fas fa-search me-1"></span>{{ __('archive.search_all_files') }}
                    </button>
                    <button class="btn btn-falcon-default d-none js-file-manager-global-search-clear" type="button">
                        <span class="fas fa-times me-1"></span>{{ __('common.actions.clear') }}
                    </button>
                </div>
            </form>
        </div>
    @endif
    <div class="card-body p-0">
        <div class="falcon-data-table">
            <div class="erp-datatable-wrapper">
                <div class="erp-datatable-scroll">
                    <table class="table table-sm table-hover mb-0 data-table erp-datatable align-middle js-file-manager-table"
                        id="{{ $tableId }}"
                        data-url="{{ $dataUrl }}"
                        data-folder-doc-num="{{ $currentFolder?->doc_num }}"
                        data-select-all="#{{ $selectAllId }}"
                        data-bulk-actions-bar="#{{ $bulkActionsBarId }}"
                        data-bulk-action-select="#{{ $bulkActionSelectId }}"
                        data-bulk-action-apply="#{{ $bulkActionApplyId }}"
                        data-selected-count="#{{ $selectedCountId }}"
                        data-trash-filter="#{{ $trashFilterId }}"
                        data-global-search-active="0"
                        @if ($canBulkDownload) data-bulk-download-url="{{ $bulkDownloadUrl }}" @endif
                        @if ($canBulkDelete) data-bulk-delete-url="{{ $bulkDeleteUrl }}" @endif
                        @if ($canBulkRestore) data-bulk-restore-url="{{ $bulkRestoreUrl }}" @endif
                        @if ($canBulkMove) data-bulk-move-url="{{ $bulkMoveUrl }}" @endif
                        @if ($moveUrl) data-move-url="{{ $moveUrl }}" @endif
                        @if ($folderOptionsUrl) data-folder-options-url="{{ $folderOptionsUrl }}" @endif>
                        <thead class="bg-100 text-900">
                            <tr>
                                <th class="text-900 no-sort white-space-nowrap align-middle all no-colvis dt-select" data-orderable="false" style="width: 2.25rem;">
                                    <div class="form-check mb-0 d-flex align-items-center justify-content-center">
                                        <input class="form-check-input js-file-manager-select-all" type="checkbox" id="{{ $selectAllId }}" aria-label="{{ __('archive.select_all') }}">
                                    </div>
                                </th>
                                <th class="text-900 sort pe-1 align-middle white-space-nowrap all no-colvis dt-code">{{ __('common.fields.document_number') }}</th>
                                <th class="text-900 sort pe-1 align-middle white-space-nowrap dt-text dt-ellipsis">{{ __('common.fields.name') }}</th>
                                <th class="text-900 sort pe-1 align-middle white-space-nowrap dt-text dt-ellipsis">{{ __('archive.folder_path') }}</th>
                                <th class="text-900 sort pe-1 align-middle white-space-nowrap dt-text">{{ __('archive.item_type') }}</th>
                                <th class="text-900 sort pe-1 align-middle white-space-nowrap dt-text dt-ellipsis">{{ __('archive.module') }}</th>
                                <th class="text-900 sort pe-1 align-middle white-space-nowrap dt-text dt-ellipsis">{{ __('archive.related_record') }}</th>
                                <th class="text-900 sort pe-1 align-middle white-space-nowrap dt-text dt-ellipsis">{{ __('archive.used_in') }}</th>
                                <th class="text-900 sort pe-1 align-middle white-space-nowrap dt-text">{{ __('archive.picker_visibility') }}</th>
                                <th class="text-900 sort pe-1 align-middle white-space-nowrap dt-text">{{ __('archive.type') }}</th>
                                <th class="text-900 sort pe-1 align-middle white-space-nowrap">{{ __('archive.size') }}</th>
                                <th class="text-900 sort pe-1 align-middle white-space-nowrap dt-text dt-ellipsis">{{ __('common.fields.created_by') }}</th>
                                <th class="text-900 sort pe-1 align-middle white-space-nowrap dt-date">{{ __('common.fields.created_at') }}</th>
                                <th class="text-900 sort pe-1 align-middle white-space-nowrap dt-text dt-ellipsis">{{ __('common.fields.updated_by') }}</th>
                                <th class="text-900 sort pe-1 align-middle white-space-nowrap dt-date">{{ __('common.fields.updated_at') }}</th>
                                <th class="text-900 sort pe-1 align-middle white-space-nowrap dt-text dt-ellipsis">{{ __('common.fields.deleted_by') }}</th>
                                <th class="text-900 sort pe-1 align-middle white-space-nowrap dt-date">{{ __('common.fields.deleted_at') }}</th>
                                <th class="text-900 sort pe-1 align-middle white-space-nowrap dt-text dt-ellipsis">{{ __('common.fields.restored_by') }}</th>
                                <th class="text-900 sort pe-1 align-middle white-space-nowrap dt-date">{{ __('common.fields.restored_at') }}</th>
                                <th class="text-900 no-sort pe-1 align-middle data-table-row-action all no-colvis dt-actions"></th>
                            </tr>
                        </thead>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
