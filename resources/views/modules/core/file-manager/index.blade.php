@extends('layouts.app')

@section('title', __('archive.file_manager'))

@section('content')
    @include('modules.core.archive.partials.document-number-settings')

    <div class="row g-3">
        <div class="col-12 col-xl-4">
            @can('file_manager.folders.create')
                <div class="card mb-3">
                    <div class="card-header">
                        <h5 class="mb-0">{{ __('archive.create_folder') }}</h5>
                    </div>
                    <div class="card-body">
                        <form class="js-archive-folder-form" action="{{ $folderStoreUrl }}" method="POST" novalidate>
                            @csrf
                            <input type="hidden" name="parent_folder" value="{{ $currentFolder?->doc_num }}">
                            <label class="form-label" for="archive-folder-name">{{ __('archive.folder_name') }}</label>
                            <div class="input-group">
                                <input id="archive-folder-name" class="form-control" type="text" name="name" maxlength="255" required data-shortcut-action="file-manager.create-folder" title="{{ __('common.shortcuts.file_manager_create_folder') }}" data-bs-title="{{ __('common.shortcuts.file_manager_create_folder') }}">
                                <button class="btn btn-falcon-primary" type="submit" title="{{ __('common.shortcuts.file_manager_create_folder') }}" data-bs-title="{{ __('common.shortcuts.file_manager_create_folder') }}">
                                    <span class="fas fa-folder-plus me-1"></span>{{ __('archive.create_folder') }}
                                </button>
                            </div>
                            <div class="invalid-feedback d-block" data-error-for="name"></div>
                        </form>
                    </div>
                </div>
            @endcan

            @can('file_manager.upload')
                <div class="card mb-3">
                    <div class="card-header">
                        <h5 class="mb-0">{{ __('archive.upload_to_this_folder') }}</h5>
                    </div>
                    <div class="card-body">
                        @include('modules.core.archive.partials.dropzone-uploader', [
                            'uploadUrl' => $uploadUrl,
                            'listUrl' => $listUrl,
                        ])
                    </div>
                </div>
            @endcan
        </div>

        <div class="col-12 col-xl-8">
            @include('modules.core.archive.partials.file-manager-table', [
                'currentFolder' => $currentFolder,
                'folderBreadcrumbs' => $folderBreadcrumbs,
            ])
        </div>
    </div>

    @include('modules.core.archive.partials.public-link-modal')
    @include('modules.core.archive.partials.move-modal')
@endsection

@push('scripts')
    @php
        $archiveMessages = [
            'deleteConfirmTitle' => __('archive.messages.delete_confirm_title'),
            'deleteConfirmText' => __('archive.messages.delete_confirm_text'),
            'deleteConfirmYes' => __('archive.messages.delete_confirm_yes'),
            'folderDeleteConfirmTitle' => __('archive.messages.folder_delete_confirm_title'),
            'folderDeleteConfirmText' => __('archive.messages.folder_delete_confirm_text'),
            'folderDeleteConfirmYes' => __('archive.messages.folder_delete_confirm_yes'),
            'restore' => __('archive.restore'),
            'restoreConfirmTitle' => __('archive.messages.restore_confirm_title'),
            'restoreConfirmText' => __('archive.messages.restore_confirm_text'),
            'restoreConfirmYes' => __('archive.messages.restore_confirm_yes'),
            'renameFolderTitle' => __('archive.rename_folder'),
            'folderName' => __('archive.folder_name'),
            'noFilesSelected' => __('archive.no_files_selected'),
            'foldersCannotBulkDownload' => __('archive.folders_cannot_bulk_download'),
            'foldersCannotBulkDelete' => __('archive.folders_cannot_bulk_delete'),
            'foldersCannotBulkRestore' => __('archive.folders_cannot_bulk_restore'),
            'moveSelected' => __('archive.move_selected'),
            'moveToFolder' => __('archive.move_to_folder'),
            'selectDestinationFolder' => __('archive.select_destination_folder'),
            'destinationFolder' => __('archive.destination_folder'),
            'chooseDestinationFolder' => __('archive.choose_destination_folder'),
            'searchFolders' => __('archive.search_folders'),
            'downloadStarted' => __('archive.download_started'),
            'bulkDeleteConfirmTitle' => __('archive.messages.bulk_delete_confirm_title'),
            'bulkDeleteConfirmText' => __('archive.messages.bulk_delete_confirm_text'),
            'bulkDeleteConfirmYes' => __('archive.messages.bulk_delete_confirm_yes'),
            'bulkRestoreConfirmTitle' => __('archive.messages.bulk_restore_confirm_title'),
            'bulkRestoreConfirmText' => __('archive.messages.bulk_restore_confirm_text'),
            'bulkRestoreConfirmYes' => __('archive.messages.bulk_restore_confirm_yes'),
            'publicLinkMissing' => __('archive.public_links.missing'),
            'publicLinkCopied' => __('archive.public_links.copied'),
            'unexpectedError' => __('common.messages.unexpected_error'),
            'no' => __('common.actions.no'),
        ];
    @endphp
    <script>
        window.archiveMessages = @json($archiveMessages);
        window.dataTableTranslations = @json(__('datatables'));
    </script>
    <script src="{{ asset('vendors/sweetalert2/sweetalert2.all.min.js') }}"></script>
    <script src="{{ asset('assets/js/modules/Core/file-manager.js') }}"></script>
@endpush
