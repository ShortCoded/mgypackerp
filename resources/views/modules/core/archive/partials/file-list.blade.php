@php
    $folders = $folders ?? collect();
    $files = $files ?? collect();
    $currentFolder = $currentFolder ?? null;
    $folderBreadcrumbs = $folderBreadcrumbs ?? [];
    $routeParameters = $routeParameters ?? [];
    $folderRouteParameters = $folderRouteParameters ?? $routeParameters;
    $ajaxFolders = (bool) ($ajaxFolders ?? false);
    $canRenameFolders = (bool) ($canRenameFolders ?? false);
    $canDeleteFolders = (bool) ($canDeleteFolders ?? false);
    $dateTimeFormat = app(\Modules\Core\Services\SettingService::class)->dateTimeFormat();
    $formatBytes = static function (int $bytes): string {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }

        if ($bytes < 1048576) {
            return number_format($bytes / 1024, 1) . ' KiB';
        }

        return number_format($bytes / 1048576, 1) . ' MiB';
    };
    $folderUrl = static function ($folder) use ($folderShowRoute, $folderRouteParameters): string {
        return route($folderShowRoute, [...$folderRouteParameters, 'folder' => $folder->doc_num]);
    };
@endphp

<div class="js-archive-file-list"
     @if (! empty($currentUploadUrl)) data-current-upload-url="{{ $currentUploadUrl }}" @endif
     @if (! empty($currentListUrl)) data-current-list-url="{{ $currentListUrl }}" @endif
     @if ($currentFolder) data-current-folder="{{ $currentFolder->doc_num }}" @endif>
    @if (! empty($folderBreadcrumbs))
        <nav aria-label="{{ __('archive.folders') }}" class="mb-3">
            <ol class="breadcrumb mb-0">
                @foreach ($folderBreadcrumbs as $breadcrumbFolder)
                    @php
                        $isCurrent = $currentFolder?->doc_num === $breadcrumbFolder->doc_num;
                        $url = $loop->first && ! empty($rootUrl) ? $rootUrl : $folderUrl($breadcrumbFolder);
                    @endphp
                    <li class="breadcrumb-item {{ $isCurrent ? 'active' : '' }}" @if ($isCurrent) aria-current="page" @endif>
                        @if ($isCurrent)
                            {{ $breadcrumbFolder->name }}
                        @else
                            <a href="{{ $url }}" @if ($ajaxFolders) class="js-archive-folder-link" data-archive-ajax="true" @endif>
                                {{ $breadcrumbFolder->name }}
                            </a>
                        @endif
                    </li>
                @endforeach
            </ol>
        </nav>
    @endif

    @if (! empty($bulkDownloadUrl))
        <div class="alert alert-info py-2 px-3 d-none js-archive-bulk-bar" data-bulk-url="{{ $bulkDownloadUrl }}">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                <span class="fw-semibold">
                    <span class="js-archive-selected-count">0</span>
                    {{ __('archive.selected_files') }}
                </span>
                <button type="button" class="btn btn-falcon-primary btn-sm js-archive-bulk-download">
                    <span class="fas fa-download me-1"></span>{{ __('archive.download_selected') }}
                </button>
            </div>
        </div>
    @endif

    @if ($folders->isEmpty() && $files->isEmpty())
        <div class="text-600 py-3">{{ __('archive.empty') }}</div>
    @else
        <div class="table-responsive scrollbar">
            <table class="table table-sm mb-0 align-middle">
                <thead class="bg-100 text-900">
                    <tr>
                        @if (! empty($bulkDownloadUrl))
                            <th class="white-space-nowrap" style="width: 1%;"></th>
                        @endif
                        <th>{{ __('archive.file_name') }}</th>
                        <th>{{ __('archive.file_type') }}</th>
                        <th>{{ __('archive.file_size') }}</th>
                        <th>{{ __('archive.uploaded_by') }}</th>
                        <th>{{ __('archive.uploaded_at') }}</th>
                        <th class="text-end">{{ __('common.fields.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($folders as $folder)
                        @php
                            $showUrl = $folderUrl($folder);
                            $updateUrl = ! empty($folderUpdateRoute) ? route($folderUpdateRoute, [...$folderRouteParameters, 'folder' => $folder->doc_num]) : null;
                            $deleteUrl = ! empty($folderDeleteRoute) ? route($folderDeleteRoute, [...$folderRouteParameters, 'folder' => $folder->doc_num]) : null;
                        @endphp
                        <tr class="archive-folder-row">
                            @if (! empty($bulkDownloadUrl))
                                <td></td>
                            @endif
                            <td class="white-space-nowrap">
                                <a href="{{ $showUrl }}" class="fw-semibold text-decoration-none js-archive-folder-link" @if ($ajaxFolders) data-archive-ajax="true" @endif>
                                    <span class="fas fa-folder text-warning me-2"></span>{{ $folder->name }}
                                </a>
                                <div class="fs-11 text-600">{{ $folder->doc_num }}</div>
                            </td>
                            <td><span class="badge rounded-pill badge-subtle-warning">{{ __('archive.folder') }}</span></td>
                            <td>{{ __('common.messages.not_available') }}</td>
                            <td>{{ $folder->createdBy?->name ?? __('common.messages.not_available') }}</td>
                            <td><span class="date-value" dir="ltr">{{ $folder->created_at?->format($dateTimeFormat) ?? __('common.messages.not_available') }}</span></td>
                            <td class="text-end">
                                @if (($canRenameFolders && $updateUrl) || ($canDeleteFolders && $deleteUrl))
                                    <div class="dropstart font-sans-serif position-static d-inline-block">
                                        <button class="btn btn-link text-600 btn-sm dropdown-toggle btn-reveal float-end" type="button" data-bs-toggle="dropdown" data-bs-boundary="viewport" data-bs-reference="parent" aria-haspopup="true" aria-expanded="false" aria-label="{{ __('common.fields.actions') }}">
                                            <span class="fas fa-ellipsis-h fs-10"></span>
                                        </button>
                                        <div class="py-2 border dropdown-menu dropdown-menu-end">
                                            @if ($canRenameFolders && $updateUrl)
                                                <button type="button" class="dropdown-item js-archive-folder-rename" data-folder-name="{{ $folder->name }}" data-folder-update-url="{{ $updateUrl }}">
                                                    {{ __('archive.rename_folder') }}
                                                </button>
                                            @endif
                                            @if ($canDeleteFolders && $deleteUrl)
                                                <button type="button" class="dropdown-item text-danger js-archive-folder-delete" data-folder-delete-url="{{ $deleteUrl }}">
                                                    {{ __('archive.delete_folder') }}
                                                </button>
                                            @endif
                                        </div>
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @endforeach

                    @foreach ($files as $file)
                        @php
                            $parameters = [...$routeParameters, 'file' => $file->doc_num];
                        @endphp
                        <tr>
                            @if (! empty($bulkDownloadUrl))
                                <td>
                                    <x-forms.input class="form-check-input js-archive-file-select" type="checkbox" value="{{ $file->doc_num }}" aria-label="{{ __('archive.file_name') }}" />
                                </td>
                            @endif
                            <td class="white-space-nowrap">
                                <span class="fas fa-file-alt text-600 me-2"></span>
                                <span class="fw-semibold">{{ $file->original_name }}</span>
                                <div class="fs-11 text-600">{{ $file->doc_num }}</div>
                            </td>
                            <td>
                                @if ($file->extension)
                                    <span class="badge rounded-pill badge-subtle-secondary">{{ strtoupper($file->extension) }}</span>
                                @else
                                    {{ __('common.messages.not_available') }}
                                @endif
                            </td>
                            <td>{{ $formatBytes((int) $file->size_bytes) }}</td>
                            <td>{{ $file->uploadedBy?->name ?? __('common.messages.not_available') }}</td>
                            <td><span class="date-value" dir="ltr">{{ $file->created_at?->format($dateTimeFormat) ?? __('common.messages.not_available') }}</span></td>
                            <td class="text-end">
                                @include('modules.core.archive.partials.file-actions', [
                                    'file' => $file,
                                    'downloadUrl' => route($downloadRoute, $parameters),
                                    'previewUrl' => route($previewRoute, $parameters),
                                    'deleteUrl' => route($deleteRoute, $parameters),
                                    'canDownload' => $canDownload,
                                    'canDelete' => $canDelete,
                                ])
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
