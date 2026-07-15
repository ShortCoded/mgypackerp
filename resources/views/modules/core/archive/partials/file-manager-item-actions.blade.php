@php
    $isFolder = $item->item_type === 'folder';
    $isTrashed = (bool) ($item->is_trashed ?? false);
    $isPreviewable = str_starts_with((string) $item->mime_type, 'image/') || $item->mime_type === 'application/pdf';
    $routes = $routes ?? [
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
    ];
    $routeParameters = $routeParameters ?? [];
    $archiveRoute = static fn (string $routeKey, string $parameterName, string $docNum): string => route($routes[$routeKey], [
        ...$routeParameters,
        $parameterName => $docNum,
    ]);
    $canCreatePublicLink = (bool) ($canCreatePublicLink ?? false);
    $canViewPublicLink = (bool) ($canViewPublicLink ?? false);
    $canRevokePublicLink = (bool) ($canRevokePublicLink ?? false);
    $canUpdatePickerVisibility = (bool) ($canUpdatePickerVisibility ?? false);
    $canRestoreFiles = (bool) ($canRestoreFiles ?? false);
    $canRestoreFolders = (bool) ($canRestoreFolders ?? false);
    $canMove = (bool) ($canMove ?? false);
    $canRestoreItem = $isTrashed && (($isFolder && $canRestoreFolders) || (! $isFolder && $canRestoreFiles));
    $canManagePublicLink = $canCreatePublicLink || $canViewPublicLink || $canRevokePublicLink;
    $hiddenFromPicker = (bool) ($item->hidden_from_picker ?? false);
    $pickerVisibilityLabel = $hiddenFromPicker ? __('archive.visible_in_picker') : __('archive.hide_from_picker');
    $pickerVisibilityIcon = $hiddenFromPicker ? 'fa-eye' : 'fa-eye-slash';
@endphp

@if (! $isTrashed || $canRestoreItem)
<div class="dropstart font-sans-serif position-static d-inline-block">
    <button class="btn btn-link text-600 btn-sm dropdown-toggle btn-reveal float-end"
        type="button"
        data-bs-toggle="dropdown"
        data-bs-boundary="viewport"
        data-bs-reference="parent"
        aria-haspopup="true"
        aria-expanded="false"
        aria-label="{{ __('common.fields.actions') }}">
        <span class="fas fa-ellipsis-h fs-10"></span>
    </button>
    <div class="py-2 border dropdown-menu dropdown-menu-end">
        @if ($isTrashed)
            @if ($canRestoreItem)
                <button type="button"
                    class="dropdown-item text-success js-archive-restore"
                    data-archive-restore-url="{{ $isFolder ? $archiveRoute('folder_restore', 'folder', $item->doc_num) : $archiveRoute('file_restore', 'file', $item->doc_num) }}"
                    data-doc-num="{{ $item->doc_num }}">
                    <span class="fas fa-undo me-2"></span>{{ __('archive.restore') }}
                </button>
            @endif
        @elseif ($isFolder)
            <a class="dropdown-item" href="{{ $archiveRoute('folder_show', 'folder', $item->doc_num) }}">
                <span class="fas fa-eye me-2"></span>{{ __('archive.preview') }}
            </a>
            @if ($canRenameFolders)
                <button type="button"
                    class="dropdown-item js-archive-folder-rename"
                    data-folder-name="{{ $item->item_name }}"
                    data-folder-update-url="{{ $archiveRoute('folder_update', 'folder', $item->doc_num) }}">
                    {{ __('archive.rename_folder') }}
                </button>
            @endif
            @if ($canMove)
                <button type="button"
                    class="dropdown-item js-archive-move-item"
                    data-move-url="{{ route($routes['move']) }}"
                    data-item-type="folder"
                    data-item-doc-num="{{ $item->doc_num }}"
                    data-item-name="{{ $item->item_name }}">
                    <span class="fas fa-folder-open me-2"></span>{{ __('archive.move_to_folder') }}
                </button>
            @endif
            @if ($canUpdatePickerVisibility)
                <button type="button"
                    class="dropdown-item js-archive-picker-visibility-toggle"
                    data-picker-visibility-url="{{ $archiveRoute('folder_picker_visibility_update', 'folder', $item->doc_num) }}"
                    data-hidden-from-picker="{{ $hiddenFromPicker ? '0' : '1' }}">
                    <span class="fas {{ $pickerVisibilityIcon }} me-2"></span>{{ $pickerVisibilityLabel }}
                </button>
            @endif
            @if ($canManagePublicLink)
                <div class="dropdown-divider"></div>
                @if ($canCreatePublicLink)
                    <button type="button"
                        class="dropdown-item js-archive-public-link-create"
                        data-public-link-create-url="{{ $archiveRoute('folder_public_link_create', 'folder', $item->doc_num) }}"
                        data-public-link-show-url="{{ $archiveRoute('folder_public_link_show', 'folder', $item->doc_num) }}"
                        data-public-link-revoke-url="{{ $archiveRoute('folder_public_link_revoke', 'folder', $item->doc_num) }}"
                        data-public-link-item-name="{{ $item->item_name }}"
                        data-public-link-item-type="{{ __('archive.folder') }}">
                        <span class="fas fa-link me-2"></span>{{ __('archive.public_links.create_or_copy') }}
                    </button>
                @endif
                @if ($canViewPublicLink || $canRevokePublicLink)
                    <button type="button"
                        class="dropdown-item js-archive-public-link-manage"
                        data-public-link-create-url="{{ $canCreatePublicLink ? $archiveRoute('folder_public_link_create', 'folder', $item->doc_num) : '' }}"
                        data-public-link-show-url="{{ $archiveRoute('folder_public_link_show', 'folder', $item->doc_num) }}"
                        data-public-link-revoke-url="{{ $canRevokePublicLink ? $archiveRoute('folder_public_link_revoke', 'folder', $item->doc_num) : '' }}"
                        data-public-link-item-name="{{ $item->item_name }}"
                        data-public-link-item-type="{{ __('archive.folder') }}">
                        <span class="fas fa-share-alt me-2"></span>{{ __('archive.public_links.manage') }}
                    </button>
                @endif
            @endif
            @if ($canDeleteFolders)
                <div class="dropdown-divider"></div>
                <button type="button"
                    class="dropdown-item text-danger js-archive-folder-delete"
                    data-folder-delete-url="{{ $archiveRoute('folder_delete', 'folder', $item->doc_num) }}">
                    {{ __('archive.delete_folder') }}
                </button>
            @endif
        @else
            @if ($isPreviewable && $canView)
                <a class="dropdown-item" href="{{ $archiveRoute('file_preview', 'file', $item->doc_num) }}" target="_blank" rel="noopener">
                    <span class="fas fa-eye me-2"></span>{{ __('archive.preview') }}
                </a>
            @endif
            @if ($canDownload)
                <a class="dropdown-item" href="{{ $archiveRoute('file_download', 'file', $item->doc_num) }}">
                    <span class="fas fa-download me-2"></span>{{ __('archive.download') }}
                </a>
            @endif
            @if ($canMove)
                <button type="button"
                    class="dropdown-item js-archive-move-item"
                    data-move-url="{{ route($routes['move']) }}"
                    data-item-type="file"
                    data-item-doc-num="{{ $item->doc_num }}"
                    data-item-name="{{ $item->item_name }}">
                    <span class="fas fa-folder-open me-2"></span>{{ __('archive.move_to_folder') }}
                </button>
            @endif
            @if ($canUpdatePickerVisibility)
                <button type="button"
                    class="dropdown-item js-archive-picker-visibility-toggle"
                    data-picker-visibility-url="{{ $archiveRoute('file_picker_visibility_update', 'file', $item->doc_num) }}"
                    data-hidden-from-picker="{{ $hiddenFromPicker ? '0' : '1' }}">
                    <span class="fas {{ $pickerVisibilityIcon }} me-2"></span>{{ $pickerVisibilityLabel }}
                </button>
            @endif
            @if ($canManagePublicLink)
                <div class="dropdown-divider"></div>
                @if ($canCreatePublicLink)
                    <button type="button"
                        class="dropdown-item js-archive-public-link-create"
                        data-public-link-create-url="{{ $archiveRoute('file_public_link_create', 'file', $item->doc_num) }}"
                        data-public-link-show-url="{{ $archiveRoute('file_public_link_show', 'file', $item->doc_num) }}"
                        data-public-link-revoke-url="{{ $archiveRoute('file_public_link_revoke', 'file', $item->doc_num) }}"
                        data-public-link-item-name="{{ $item->item_name }}"
                        data-public-link-item-type="{{ __('archive.file') }}">
                        <span class="fas fa-link me-2"></span>{{ __('archive.public_links.create_or_copy') }}
                    </button>
                @endif
                @if ($canViewPublicLink || $canRevokePublicLink)
                    <button type="button"
                        class="dropdown-item js-archive-public-link-manage"
                        data-public-link-create-url="{{ $canCreatePublicLink ? $archiveRoute('file_public_link_create', 'file', $item->doc_num) : '' }}"
                        data-public-link-show-url="{{ $archiveRoute('file_public_link_show', 'file', $item->doc_num) }}"
                        data-public-link-revoke-url="{{ $canRevokePublicLink ? $archiveRoute('file_public_link_revoke', 'file', $item->doc_num) : '' }}"
                        data-public-link-item-name="{{ $item->item_name }}"
                        data-public-link-item-type="{{ __('archive.file') }}">
                        <span class="fas fa-share-alt me-2"></span>{{ __('archive.public_links.manage') }}
                    </button>
                @endif
            @endif
            @if ($canDeleteFiles)
                <div class="dropdown-divider"></div>
                <button type="button"
                    class="dropdown-item text-danger js-archive-file-delete"
                    data-archive-delete-url="{{ $archiveRoute('file_delete', 'file', $item->doc_num) }}"
                    data-doc-num="{{ $item->doc_num }}">
                    {{ __('archive.delete') }}
                </button>
            @endif
        @endif
    </div>
</div>
@endif
